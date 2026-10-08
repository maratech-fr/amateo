"""Diagnostics des 4 règles implicites : repos coach, salarié, enchaînements, âge croissant.

Extrait verbatim de l'ancien ``diagnostics.py`` (découpe pure, P4-295 C1).
"""

from __future__ import annotations

import contextlib
from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from typing import Any

from ...constraints import ResolvedImplicitRules
from ...model import _time_to_minutes
from ..helpers import (
    _coach_name_map,
    _collection,
    _day_label,
    _get,
    _label,
    _slot_day,
    _team_name_map,
    _venue_name_map,
)

_IMPLICIT_RULE_LABELS = {
    "coachRestDay": "jour de repos",
    "salarieDistribution": "présence d'un salarié",
    "maxConsecutiveSessions": "enchaînements",
    "ageAscending": "âge croissant",
}


def _softened_prefix(rule_label: str, intensity: str) -> str:
    """PREFERRED = le gestionnaire a assoupli la règle ; HARD = un verrou l'a contournée
    malgré le solveur. Deux textes distincts, aucun identifiant interne."""
    if intensity == "PREFERRED":
        return f"Règle « {rule_label} » assouplie par vous"
    return f"Le solveur n'a pas pu honorer la règle « {rule_label} »"


def _list_days(days: list[int]) -> str:
    """« du lundi au vendredi » si contigu, sinon « lundi, mercredi, vendredi »."""
    ordered = sorted(days)
    names = [_day_label(d) for d in ordered]
    if len(ordered) >= 2 and ordered == list(range(ordered[0], ordered[-1] + 1)):
        return f"du {names[0]} au {names[-1]}"
    return ", ".join(names)


def _diagnose_implicit_rule_violations(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
    rules: ResolvedImplicitRules,
    team_coach_map: Mapping[str, list[str]],
    team_player_map: Mapping[str, list[str]],
) -> list[dict[str, Any]]:
    """Diagnostique les 4 règles implicites sur les slots FINAUX (verrous inclus), au MÊME
    grain que la pose : personnes = coachs (``team_coach_map``, ASSISTANT déjà exclus) +
    joueurs (``team_player_map``), JAMAIS ``slot.coachId``.

    Toujours exécuté, quel que soit le cran : PREFERRED non tenu → « assouplie par vous » ;
    HARD contourné par un verrou → « le solveur n'a pas pu honorer ». Émet
    ``implicit_rule_not_honored`` (WARNING) avec la ``ruleKey`` du contrat.
    """
    diagnostics: list[dict[str, Any]] = []
    coach_names = _coach_name_map(model_data)
    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)

    coach_ids: set[str] = {
        str(_get(c, "id", "coach_id", "coachId"))
        for c in _collection(model_data, "coaches")
        if _get(c, "id", "coach_id", "coachId") is not None
    }
    salarie_ids: set[str] = {
        str(_get(c, "id", "coach_id", "coachId"))
        for c in _collection(model_data, "coaches")
        if _get(c, "id", "coach_id", "coachId") is not None and _get(c, "isEmployee", "is_employee", default=False)
    }

    def _persons(team_id: str, roles: Mapping[str, list[str]]) -> list[str]:
        return [str(p) for p in roles.get(team_id, [])]

    # --- 3b coach rest day : jours travaillés (coach OU joueur) par coach, lun-ven -------
    coach_working_days: dict[str, set[int]] = defaultdict(set)
    for slot in slots:
        day = _slot_day(slot)
        if day is None or not 1 <= day <= 5:
            continue
        team_id = str(slot["teamId"])
        for person in _persons(team_id, team_coach_map) + _persons(team_id, team_player_map):
            if person in coach_ids:
                coach_working_days[person].add(day)

    rest_cap = 5 - rules.min_rest_days
    for coach_id in sorted(coach_working_days):
        days = coach_working_days[coach_id]
        if len(days) <= rest_cap:
            continue
        name = _label(coach_id, coach_names)
        prefix = _softened_prefix(_IMPLICIT_RULE_LABELS["coachRestDay"], rules.coach_rest_day_intensity)
        diagnostics.append(
            {
                "id": f"diag-implicit-rest-{coach_id}",
                "type": "implicit_rule_not_honored",
                "ruleKey": "coachRestDay",
                "severity": "WARNING",
                "coachId": coach_id,
                "message": (f"{prefix} : {name} est présent les {len(days)} soirs, {_list_days(list(days))}."),
                "suggestions": [
                    "Répartissez certaines séances sur un autre coach, ou renforcez le jour de repos.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )

    # --- 3c salarié distribution : un jour ouvré sans salarié présent -------------------
    if len(salarie_ids) >= 2:
        salarie_days: set[int] = set()
        for slot in slots:
            day = _slot_day(slot)
            if day is None or not 1 <= day <= 5:
                continue
            team_id = str(slot["teamId"])
            people = set(_persons(team_id, team_coach_map)) | set(_persons(team_id, team_player_map))
            if people & salarie_ids:
                salarie_days.add(day)
        missing = [d for d in range(1, 6) if d not in salarie_days]
        if missing:
            prefix = _softened_prefix(
                _IMPLICIT_RULE_LABELS["salarieDistribution"], rules.salarie_distribution_intensity
            )
            days_text = " et ".join(_day_label(d) for d in missing)
            diagnostics.append(
                {
                    "id": "diag-implicit-salarie",
                    "type": "implicit_rule_not_honored",
                    "ruleKey": "salarieDistribution",
                    "severity": "WARNING",
                    "message": f"{prefix} : aucun salarié encadrant le {days_text}.",
                    "suggestions": [
                        "Placez au moins une séance d'un coach salarié ce(s) jour(s)-là.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    # --- 3d enchaînements : chaîne dos-à-dos de longueur max_consecutive par (personne, jour)
    diagnostics.extend(
        _diagnose_chain_violations(slots, rules, team_coach_map, team_player_map, coach_ids, coach_names, team_names)
    )

    # --- 12 âge croissant : paire inversée par (gymnase, jour) ---------------------------
    diagnostics.extend(_diagnose_age_violations(model_data, slots, rules, venue_names, team_names))

    return diagnostics


def _diagnose_chain_violations(
    slots: list[dict[str, Any]],
    rules: ResolvedImplicitRules,
    team_coach_map: Mapping[str, list[str]],
    team_player_map: Mapping[str, list[str]],
    coach_ids: set[str],
    coach_names: Mapping[str, str],
    team_names: Mapping[str, str],
) -> list[dict[str, Any]]:
    k = rules.max_consecutive
    # (person, day) -> list of (start, end, team_id, is_coaching)
    person_day: dict[tuple[str, str], list[tuple[int, int, str, bool]]] = defaultdict(list)
    for slot in slots:
        raw_day = slot.get("dayOfWeek")
        if raw_day is None:
            continue
        day = str(raw_day)
        team_id = str(slot["teamId"])
        start = _time_to_minutes(str(slot["startTime"]))
        end = start + int(slot.get("durationMinutes") or 0)
        coaches = {str(c) for c in team_coach_map.get(team_id, [])}
        players = {str(p) for p in team_player_map.get(team_id, [])}
        for person in (coaches | players) & coach_ids:
            person_day[(person, day)].append((start, end, team_id, person in coaches))

    diagnostics: list[dict[str, Any]] = []
    prefix = _softened_prefix(_IMPLICIT_RULE_LABELS["maxConsecutiveSessions"], rules.max_consecutive_sessions_intensity)
    for (person, day), entries in sorted(person_day.items()):
        chain = _first_back_to_back_chain(entries, k)
        if chain is None:
            continue
        name = _label(person, coach_names)
        parts = [
            _label(team_id, team_names) if is_coaching else f"il joue avec {_label(team_id, team_names)}"
            for _s, _e, team_id, is_coaching in chain
        ]
        diagnostics.append(
            {
                "id": f"diag-implicit-chain-{person}-{day}",
                "type": "implicit_rule_not_honored",
                "ruleKey": "maxConsecutiveSessions",
                "severity": "WARNING",
                "coachId": person,
                "message": (f"{prefix} : {name} enchaîne {k} créneaux {_day_label(day)} — {', '.join(parts)}."),
                "suggestions": [
                    "Insérez une pause en déplaçant l'une des séances sur un autre horaire.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics


def _first_back_to_back_chain(
    entries: list[tuple[int, int, str, bool]],
    length: int,
) -> list[tuple[int, int, str, bool]] | None:
    """Première chaîne dos-à-dos de ``length`` créneaux (fin == début suivant), triée par
    heure. Miroir de ``constraints._find_consecutive_chains`` côté détection."""
    length = max(2, length)
    ordered = sorted(entries)
    by_start: dict[int, list[tuple[int, int, str, bool]]] = defaultdict(list)
    for entry in ordered:
        by_start[entry[0]].append(entry)

    def _extend(chain: list[tuple[int, int, str, bool]]) -> list[tuple[int, int, str, bool]] | None:
        if len(chain) == length:
            return chain
        for nxt in by_start.get(chain[-1][1], []):
            if any(nxt is member for member in chain):
                continue
            found = _extend([*chain, nxt])
            if found is not None:
                return found
        return None

    for entry in ordered:
        found = _extend([entry])
        if found is not None:
            return found
    return None


def _diagnose_age_violations(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
    rules: ResolvedImplicitRules,
    venue_names: Mapping[str, str],
    team_names: Mapping[str, str],
) -> list[dict[str, Any]]:
    team_age_min: dict[str, int] = {}
    for team in _collection(model_data, "teams"):
        tid = _get(team, "id", "team_id", "teamId")
        age_min = _get(team, "ageMin", "age_min", default=None)
        if tid is not None and age_min is not None:
            with contextlib.suppress(TypeError, ValueError):
                team_age_min[str(tid)] = int(age_min)

    # (venue, day) -> team_id -> earliest start observed
    by_group: dict[tuple[str, str], dict[str, int]] = defaultdict(dict)
    for slot in slots:
        raw_day = slot.get("dayOfWeek")
        if raw_day is None:
            continue
        team_id = str(slot["teamId"])
        if team_id not in team_age_min:
            continue
        key = (str(slot["venueId"]), str(raw_day))
        start = _time_to_minutes(str(slot["startTime"]))
        current = by_group[key].get(team_id)
        by_group[key][team_id] = start if current is None else min(current, start)

    diagnostics: list[dict[str, Any]] = []
    prefix = _softened_prefix(_IMPLICIT_RULE_LABELS["ageAscending"], rules.age_ascending_intensity)
    for (venue_id, day), starts_by_team in sorted(by_group.items()):
        inversion: tuple[str, str] | None = None
        teams_here = list(starts_by_team)
        for i in range(len(teams_here)):
            for j in range(len(teams_here)):
                younger, older = teams_here[i], teams_here[j]
                if team_age_min[younger] >= team_age_min[older]:
                    continue
                if starts_by_team[younger] > starts_by_team[older]:
                    inversion = (younger, older)
                    break
            if inversion is not None:
                break
        if inversion is None:
            continue
        younger, older = inversion
        diagnostics.append(
            {
                "id": f"diag-implicit-age-{venue_id}-{day}",
                "type": "implicit_rule_not_honored",
                "ruleKey": "ageAscending",
                "severity": "WARNING",
                "venueId": venue_id,
                "dayOfWeek": int(day) if str(day).lstrip("-").isdigit() else None,
                "message": (
                    f"{prefix} : au gymnase {_label(venue_id, venue_names)} {_day_label(day)}, "
                    f"{_label(younger, team_names)} (plus jeunes) passent après {_label(older, team_names)}."
                ),
                "suggestions": [
                    "Placez l'équipe la plus jeune sur un créneau plus tôt dans la journée.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics
