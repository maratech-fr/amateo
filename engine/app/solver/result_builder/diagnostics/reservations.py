"""Les séances privées de case par une réservation (décision fondateur 2026-10-30).

Deux chemins mènent ici, sur un planning PRODUIT :
  * le best-effort a levé une règle de demande (jour imposé, plancher de gymnase) : la séance qu'elle
    exigeait n'a jamais été placée ailleurs (``model.relaxed_demands``) ;
  * le solve a abouti tout seul, la séance manque simplement : une équipe sous son quota dont aucune
    case n'est restée ouverte, et dont au moins une case compatible avec ses règles est prise par le
    verrou d'une AUTRE équipe. Cet écart ne se voit qu'après le solve.

Dans les deux cas la règle TIENT : ``blocked_by_reservation`` nomme l'équipe, la règle et la réservation.
Une équipe dont les verrous épuisent déjà le quota n'est pas privée par une autre : sa propre réservation
contredit la règle, c'est un ``rule_sacrificed``.
"""

from __future__ import annotations

from collections.abc import Mapping
from datetime import UTC, datetime
from typing import Any

from ...constraints.availability import available_coach_exists
from ...constraints.diagnostics import _day_rules_union, _is_enforced_window
from ...constraints.targeting import time_window_excludes_slot
from ...model import DEFAULT_SESSION_MINUTES, _time_to_minutes
from ..helpers import _collection, _day_label, _get, _label, _team_name_map, _venue_name_map

_NOT_A_RULE = {"hard_lock"}


def _minutes(value: Any) -> int | None:
    return _time_to_minutes(str(value)) if value is not None else None


def _case_fits_team_rules(team_id: str, venue_id: str, day: int, start: int, duration: int, parsed: Any) -> bool:
    """La case aurait-elle servi l'équipe sans le verrou ? Rejoue les règles de FERMETURE sur un candidat
    que le verrou a retiré avant toute pose (il n'a donc enregistré aucune fermeture)."""
    coaches = parsed.get("team_coach_map", {}).get(team_id) or ()
    if not available_coach_exists(coaches, day, start, parsed.get("coach_unavailability") or {}):
        return False
    for window in parsed.get("time_windows") or ():
        if str(window.get("scope_target_id") or window.get("scopeTargetId") or "") != team_id:
            continue
        if not _is_enforced_window(window) or str(window.get("family") or "").upper() != "TIME":
            continue
        config = window.get("config") or {}
        if time_window_excludes_slot(
            start,
            duration,
            min_start_minutes=_minutes(config.get("minStartTime")),
            max_start_minutes=_minutes(config.get("maxStartTime")),
            max_end_minutes=_minutes(config.get("maxEndTime")),
        ):
            return False
    day_entry = _day_rules_union(parsed.get("time_windows") or ()).get(team_id)
    if day_entry is not None:
        day_rules = day_entry[0]
        if day in day_rules["forbidden"] or (day_rules["allowed"] and day not in day_rules["allowed"]):
            return False
    forced_venue = (parsed.get("forced_venues") or {}).get(team_id)
    if forced_venue is not None and str(forced_venue) != venue_id:
        return False
    for item in parsed.get("forbidden_assignments") or ():
        tid = item.get("scope_target_id") or item.get("team_id")
        vid = item.get("venue_id") or item.get("room_id")
        if tid is not None and vid is not None and str(tid) == team_id and str(vid) == venue_id:
            return False
    return True


def _other_teams_locks_on(team_id: str, venue_id: str, day: int, start: int, model: Any) -> list[Mapping[str, Any]]:
    """Les verrous d'autres équipes qui occupent cette case (une réservation longue couvre plusieurs départs)."""
    found: list[Mapping[str, Any]] = []
    for lock in model.locked_slots:
        if str(lock["team_id"]) == team_id or str(lock["venue_id"]) != venue_id or int(lock["day_of_week"]) != day:
            continue
        lock_start = _time_to_minutes(str(lock["start_time"]))
        if lock_start <= start < lock_start + int(lock.get("duration_minutes") or DEFAULT_SESSION_MINUTES):
            found.append(lock)
    return found


def _reservations_phrase(
    locks: list[Mapping[str, Any]], team_names: Mapping[str, str], venue_names: Mapping[str, str]
) -> str:
    """« la réservation de Cadets (vendredi 18:00 au gymnase V) », en ordre stable, chaque verrou une fois."""
    seen = sorted(
        {(int(k["day_of_week"]), str(k["start_time"])[:5], str(k["venue_id"]), str(k["team_id"])) for k in locks}
    )
    parts = [
        f"la réservation de {_label(team, team_names)} ({_day_label(day)} {start} au gymnase {_label(venue, venue_names)})"
        for day, start, venue, team in seen
    ]
    return " et ".join(parts)


def _gap(
    team_id: str,
    message: str,
    suggestion: str,
    causes: list[dict[str, Any]],
    *,
    diagnostic_type: str = "blocked_by_reservation",
) -> dict[str, Any]:
    return {
        "id": f"{diagnostic_type}-{team_id}",
        "type": diagnostic_type,
        "severity": "WARNING",
        "teamId": team_id,
        "message": message,
        "causes": causes,
        "suggestions": [suggestion],
        "createdAt": datetime.now(UTC).isoformat(),
    }


def _diagnose_reservation_gaps(
    model_data: Mapping[str, Any] | Any,
    model: Any,
    slots: list[dict[str, Any]],
    session_causes_by_team: Mapping[str, Mapping[str, Any]],
) -> list[dict[str, Any]]:
    """Nomme chaque séance qu'une réservation a privée de case (cf. docstring du module)."""
    parsed = getattr(model, "parsed_rules", None) or {}
    locked_slots = getattr(model, "locked_slots", None) or ()
    if not locked_slots or not parsed:
        return []

    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    placed: dict[str, list[dict[str, Any]]] = {}
    for slot in slots:
        placed.setdefault(str(slot["teamId"]), []).append(slot)
    own_locks: dict[str, int] = {}
    for lock in locked_slots:
        own_locks[str(lock["team_id"])] = own_locks.get(str(lock["team_id"]), 0) + 1

    # Les candidats retirés par un verrou, par équipe : (gymnase, jour, début).
    taken: dict[str, list[tuple[str, int, str]]] = {}
    for slot_key in getattr(model, "lock_removed_candidates", None) or {}:
        taken.setdefault(str(slot_key[0]), []).append((str(slot_key[1]), int(slot_key[2]), str(slot_key[3])))

    gaps: list[dict[str, Any]] = []
    handled: set[str] = set()
    spw_by_team = {
        str(_get(team, "id", "team_id", "teamId")): int(
            _get(team, "sessions_per_week", "sessionsPerWeek", default=0) or 0
        )
        for team in _collection(model_data, "teams")
    }

    # 1. Les règles de demande levées par le best-effort.
    for demand in getattr(model, "relaxed_demands", None) or ():
        team_id = str(demand["teamId"])
        team = _label(team_id, team_names)
        rule = f"la règle « {demand['label']} »" if demand.get("label") else "sa règle"
        cause = {
            "kind": demand["kind"],
            "constraintId": demand.get("constraintId"),
            "label": demand.get("label"),
            "count": int(demand["sessions"]),
        }
        if own_locks.get(team_id, 0) >= spw_by_team.get(team_id, 0) > 0:
            # Sa propre réservation épuise le quota. Le jour imposé est déjà nommé à la pose.
            if demand["kind"] != "day_forced":
                gaps.append(
                    _gap(
                        team_id,
                        f"{rule.capitalize()} de {team} n'est pas tenue : ses réservations occupent toutes ses "
                        "séances. La réservation est tenue ; la règle est relâchée.",
                        "Déplacez la réservation si vous voulez que cette règle s'applique.",
                        [cause],
                        diagnostic_type="rule_sacrificed",
                    )
                )
            continue
        locks = [
            lock
            for venue_id, day, start in taken.get(team_id, ())
            if (day in demand["days"] if "days" in demand else venue_id == demand.get("venueId"))
            for lock in _other_teams_locks_on(team_id, venue_id, day, _time_to_minutes(start), model)
        ]
        by = _reservations_phrase(locks, team_names, venue_names) or "une réservation"
        handled.add(team_id)
        gaps.append(
            _gap(
                team_id,
                f"Séance non placée pour {team} : {by} occupe la seule case compatible avec {rule}. "
                "La règle tient, la séance n'est pas placée ailleurs.",
                "Déplacez cette réservation ou ajoutez un créneau compatible avec la règle.",
                [cause],
            )
        )

    # 2. L'équipe sous son quota, sans plus aucune case ouverte, dont une case compatible est réservée.
    for team_id, candidates in sorted(taken.items()):
        spw = spw_by_team.get(team_id, 0)
        count = len(placed.get(team_id, ()))
        team_causes = session_causes_by_team.get(team_id) or {}
        if team_id in handled or count >= spw or team_causes.get("openCandidates"):
            continue
        busy_days = {int(slot["dayOfWeek"]) for slot in placed.get(team_id, ())}
        locks = []
        for venue_id, day, start in candidates:
            start_minutes = _time_to_minutes(start)
            duration = int(getattr(model, "slot_durations", {}).get((venue_id, day, start), DEFAULT_SESSION_MINUTES))
            if day not in busy_days and _case_fits_team_rules(team_id, venue_id, day, start_minutes, duration, parsed):
                locks.extend(_other_teams_locks_on(team_id, venue_id, day, start_minutes, model))
        if not locks:
            continue
        rules = [c for c in team_causes.get("causes", ()) if c.get("kind") not in _NOT_A_RULE]
        labels = sorted({str(c["label"]) for c in rules if c.get("label")})
        by = _reservations_phrase(locks, team_names, venue_names)
        team = _label(team_id, team_names)
        head = f"L'équipe {team} : {spw} séance(s) demandée(s), {count} placée(s)."
        if labels:
            quoted = ", ".join(f"« {label} »" for label in labels)
            detail = f"{by} occupe la seule case compatible avec {quoted}. La règle tient, la séance n'est pas placée ailleurs."
        else:
            detail = f"{by} occupe la dernière case libre."
        gaps.append(
            _gap(
                team_id,
                f"{head} {detail[0].upper()}{detail[1:]}",
                "Déplacez cette réservation ou ajoutez un créneau compatible avec les règles de l'équipe.",
                rules or [{"kind": "min_sessions", "constraintId": None, "label": None, "count": spw - count}],
            )
        )
    return gaps
