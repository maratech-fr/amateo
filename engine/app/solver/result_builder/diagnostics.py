"""Result builder — diagnostics post-solve lisibles par le gestionnaire (paquet ENG-39).

Extrait tel quel de l'ancien monolithe ``result_builder.py`` (déplacement pur, ENG-39). Réunit
``_generate_diagnostics``, les treize ``_diagnose_*`` et leurs helpers locaux (causes de séance,
géométrie de trajet, message d'infaisabilité…). Dépend de ``helpers`` (cartes/libellés) et des
modules solveur ``model`` / ``constraints`` ; ne dépend PAS de ``slots`` ni de l'agrégateur.

⚠ ENG-37 : ``_diagnose_travel_times`` consomme la SOURCE UNIQUE ``is_travel_too_tight`` (importée
ci-dessous). Le test-garde ``test_travel_diagnostic_delegates_to_the_shared_geometry_source``
neutralise cette source en patchant ``app.solver.result_builder.diagnostics.is_travel_too_tight`` —
le nom doit rester résolu DANS CE MODULE pour que le garde morde.
"""

from __future__ import annotations

import contextlib
from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from typing import Any

from ortools.sat.python import cp_model

from ..constraints import (
    ResolvedImplicitRules,
    build_travel_matrix,
    is_travel_too_tight,
    iter_team_link_overlaps,
    team_share_declared_pairs,
)
from ..constraints.common import _fold_case_occupant_identity
from ..model import (
    DEFAULT_SESSION_MINUTES,
    ScheduleCpModel,
    _format_time,
    _time_to_minutes,
)
from .helpers import (
    _coach_name_map,
    _coach_threshold,
    _collection,
    _day_label,
    _get,
    _label,
    _named_list,
    _occupant_list,
    _slot_day,
    _slot_templates,
    _team_ids,
    _team_name_map,
    _time_range,
    _venue_name_map,
)


def _generate_diagnostics(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
    *,
    slot_capacities: dict[Any, int] | None = None,
    implicit_rules: ResolvedImplicitRules | None = None,
    team_coach_map: Mapping[str, list[str]] | None = None,
    team_player_map: Mapping[str, list[str]] | None = None,
    session_causes_by_team: Mapping[str, dict[str, Any]] | None = None,
) -> list[dict[str, Any]]:
    """Run post-solve checks and return manager-readable diagnostics."""
    diagnostics: list[dict[str, Any]] = []
    # ENG-22: every "analysis of the placed slots" diagnostic only makes sense for a REAL
    # solve (OPTIMAL/FEASIBLE). On INFEASIBLE the demand-vs-supply message explains it; on
    # UNKNOWN/timeout the solver simply didn't finish — claiming teams are "below their
    # minimum for lack of gym slots" or slots are "occupied" would be a lie contradicting the
    # timeout diagnostic. _diagnose_conflicts owns the INFEASIBLE + timeout cases.
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # Les règles implicites TOUJOURS diagnostiquées, quel que soit le cran (sur les slots
        # FINAUX, verrous inclus). Calculé d'abord : un coach déjà signalé « repos non tenu »
        # ne doit pas recevoir EN PLUS un coach_overload (un seul warning par fait).
        implicit_diags = _diagnose_implicit_rule_violations(
            model_data,
            slots,
            implicit_rules if implicit_rules is not None else ResolvedImplicitRules(),
            team_coach_map or {},
            team_player_map or {},
        )
        diagnostics.extend(implicit_diags)
        rest_flagged_coaches = {
            str(diag.get("coachId"))
            for diag in implicit_diags
            if diag.get("ruleKey") == "coachRestDay" and diag.get("coachId")
        }

        diagnostics.extend(
            _diagnose_locked_structural_conflicts(model_data, slots, team_coach_map or {}, team_player_map or {})
        )
        diagnostics.extend(_diagnose_unplaced(model_data, slots))
        diagnostics.extend(_diagnose_soft_lock_moved(model_data, slots))
        diagnostics.extend(
            diag
            for diag in _diagnose_coach_overload(model_data, slots)
            if str(diag.get("coachId")) not in rest_flagged_coaches
        )
        diagnostics.extend(
            _diagnose_session_below_effective_min(model_data, slots, session_causes_by_team=session_causes_by_team)
        )
        diagnostics.extend(_diagnose_unused_slots(model_data, slots))
    diagnostics.extend(_diagnose_conflicts(model_data, solver_status, slots, slot_capacities=slot_capacities))
    diagnostics.extend(_diagnose_shared_blocks(model_data, solver_status, slots))
    diagnostics.extend(_diagnose_team_links(model_data, solver_status, slots))
    diagnostics.extend(_diagnose_travel_times(model_data, solver_status, slots, team_coach_map or {}))
    return diagnostics


def _diagnose_locked_structural_conflicts(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
    team_coach_map: Mapping[str, list[str]],
    team_player_map: Mapping[str, list[str]],
) -> list[dict[str, Any]]:
    """Diagnostique les conflits STRUCTURELS que des VERROUS SEULS provoquent (P4-97 bis).

    Un créneau verrouillé est souverain : quand deux verrous se contredisent (choix du
    gestionnaire), la génération sort quand même — ``completed`` — mais le silence doit cesser.
    La pose (``constraints.py``) empêche désormais tout créneau LIBRE d'entrer en conflit avec un
    verrou ; ne restent donc que les conflits ENTRE verrous, impossibles à résoudre sans lever un
    verrou. On n'émet un diagnostic que si AU MOINS un créneau HARD est impliqué.

    Deux familles, aux personnes lues des CARTES (``team_coach_map``/``team_player_map``, jamais
    ``slot.coachId``) :
      * une personne dans DEUX gymnases à la même minute (impossible physiquement — le même
        gymnase reste permis pour deux rôles coach, D-14) ;
      * une équipe avec DEUX séances le même jour.
    Texte français nommant équipes/personnes/gymnases/horaires réels ; aucun identifiant interne.
    """
    diagnostics: list[dict[str, Any]] = []
    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    coach_names = _coach_name_map(model_data)

    # --- Personne dans deux gymnases à la fois ------------------------------------------
    # person -> [(start, end, day, venue, team, role, is_hard)]
    person_slots: dict[str, list[tuple[int, int, int, str, str, str, bool]]] = defaultdict(list)
    for slot in slots:
        day = _slot_day(slot)
        if day is None:
            continue
        team_id = str(slot["teamId"])
        start = _time_to_minutes(str(slot["startTime"]))
        end = start + int(slot.get("durationMinutes") or 0)
        venue = str(slot["venueId"])
        is_hard = str(slot.get("lockLevel") or "NONE").upper() == "HARD"
        roles: dict[str, str] = {}
        for coach_id in team_coach_map.get(team_id, []):
            roles.setdefault(str(coach_id), "coach")
        for player_id in team_player_map.get(team_id, []):
            roles[str(player_id)] = "player"
        for person, role in roles.items():
            person_slots[person].append((start, end, day, venue, team_id, role, is_hard))

    seen_person: set[tuple[str, int, str, str]] = set()
    for person, entries in sorted(person_slots.items()):
        ordered = sorted(entries)
        for i in range(len(ordered)):
            a_start, a_end, a_day, a_venue, a_team, a_role, a_hard = ordered[i]
            for j in range(i + 1, len(ordered)):
                b_start, b_end, b_day, b_venue, b_team, b_role, b_hard = ordered[j]
                if a_day != b_day or not (a_start < b_end and b_start < a_end):
                    continue
                if a_venue == b_venue and a_role == "coach" and b_role == "coach":
                    continue  # deux groupes surveillés dans le même gymnase (D-14)
                if a_venue == b_venue:
                    continue  # même gymnase : c'est le conflit d'ÉQUIPE, traité plus bas
                if not (a_hard or b_hard):
                    continue  # la pose empêche déjà les conflits impliquant un créneau libre
                teams_key = tuple(sorted((a_team, b_team)))
                key = (person, a_day, teams_key[0], teams_key[1])
                if key in seen_person:
                    continue
                seen_person.add(key)
                when = f"{_day_label(a_day)} {_time_range(_format_time(a_start), a_end - a_start)}"
                diagnostics.append(
                    {
                        "id": f"diag-locked-person-{person}-{a_day}-{a_start}",
                        "type": "conflict",
                        "severity": "ERROR",
                        "coachId": person,
                        "dayOfWeek": a_day,
                        "message": (
                            f"{_label(person, coach_names)} est réservé(e) dans deux gymnases en même temps "
                            f"le {when} — {_label(a_venue, venue_names)} avec {_label(a_team, team_names)} et "
                            f"{_label(b_venue, venue_names)} avec {_label(b_team, team_names)} : "
                            "un créneau verrouillé prime, mais la personne ne peut pas être aux deux endroits."
                        ),
                        "suggestions": [
                            "Déplacez ou retirez l'une des réservations verrouillées de cette personne.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )

    # --- Équipe avec deux séances le même jour ------------------------------------------
    team_day_slots: dict[tuple[str, int], list[tuple[int, str, bool]]] = defaultdict(list)
    for slot in slots:
        day = _slot_day(slot)
        if day is None:
            continue
        team_id = str(slot["teamId"])
        start = _time_to_minutes(str(slot["startTime"]))
        is_hard = str(slot.get("lockLevel") or "NONE").upper() == "HARD"
        team_day_slots[(team_id, day)].append((start, str(slot["startTime"])[:5], is_hard))

    for (team_id, day), day_slots in sorted(team_day_slots.items()):
        if len(day_slots) < 2 or not any(is_hard for _s, _t, is_hard in day_slots):
            continue
        times = ", ".join(t for _s, t, _h in sorted(day_slots))
        diagnostics.append(
            {
                "id": f"diag-locked-team-day-{team_id}-{day}",
                "type": "conflict",
                "severity": "ERROR",
                "teamId": team_id,
                "dayOfWeek": day,
                "message": (
                    f"{_label(team_id, team_names)} a {len(day_slots)} séances le même jour "
                    f"({_day_label(day)} à {times}) alors qu'une seule par jour est permise : "
                    "un créneau verrouillé prime, mais deux séances le même jour restent à arbitrer."
                ),
                "suggestions": [
                    "Déplacez ou retirez l'une des réservations verrouillées de cette équipe ce jour-là.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )

    return diagnostics


def _diagnose_unplaced(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Flag teams that have no sessions in the final schedule (who + why)."""
    diagnostics: list[dict[str, Any]] = []
    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    placed_team_ids = {slot["teamId"] for slot in slots}

    # Total training-slot supply — used to distinguish "no slots at all" from
    # "slots exist but were all taken / incompatible".
    total_available_slots = sum(
        len(_collection(venue, "training_slots", "trainingSlots")) for venue in _collection(model_data, "venues")
    )
    teams_by_id = {
        str(_get(team, "id", "team_id", "teamId")): team
        for team in _collection(model_data, "teams")
        if _get(team, "id", "team_id", "teamId") is not None
    }
    # Which venues actually declare at least one slot (for forced-venue reason).
    venues_with_slots = {
        str(_get(venue, "id", "venue_id", "venueId"))
        for venue in _collection(model_data, "venues")
        if _collection(venue, "training_slots", "trainingSlots")
    }

    for team_id in _team_ids(model_data):
        if team_id in placed_team_ids:
            continue

        team_label = _label(team_id, team_names)
        team = teams_by_id.get(team_id)
        forced_venue_id = _get(team, "forced_venue_id", "forcedVenueId", default=None) if team is not None else None

        if total_available_slots == 0:
            reason = "aucun créneau d'entraînement n'est déclaré dans les gymnases."
            suggestions = ["Ajoutez des créneaux de disponibilité sur au moins un gymnase."]
        elif forced_venue_id is not None and str(forced_venue_id) not in venues_with_slots:
            venue_label = _label(forced_venue_id, venue_names)
            reason = (
                f"son gymnase imposé ({venue_label}) n'a aucun créneau disponible "
                "(gymnase fermé ou sans horaires déclarés)."
            )
            suggestions = [
                f"Ajoutez des créneaux au gymnase {venue_label}, ou retirez le gymnase imposé pour cette équipe.",
            ]
        else:
            reason = (
                "tous les créneaux compatibles étaient déjà occupés par des équipes plus "
                "prioritaires, ou en conflit avec ses contraintes (coach indisponible, "
                "gymnase fermé, jour interdit)."
            )
            suggestions = [
                "Ajoutez de la disponibilité de gymnase ou assouplissez une contrainte dure de cette équipe.",
                "Vérifiez que l'équipe dispose d'au moins un créneau réellement libre.",
            ]

        diagnostics.append(
            {
                "id": f"diag-unplaced-{team_id}",
                "type": "unplaced",
                "severity": "ERROR",
                "teamId": team_id,
                "message": f"L'équipe {team_label} n'a pas pu être placée : {reason}",
                "suggestions": suggestions,
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics


def _unplaced_team_ids(model_data: Mapping[str, Any] | Any, slots: list[dict[str, Any]]) -> list[str]:
    placed_team_ids = {slot["teamId"] for slot in slots}
    return [team_id for team_id in sorted(_team_ids(model_data)) if team_id not in placed_team_ids]


def _diagnose_soft_lock_moved(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Warn when a SOFT locked template did not survive in the solution."""
    diagnostics: list[dict[str, Any]] = []
    for template in _slot_templates(model_data):
        lock_level = str(_get(template, "lock_level", "lockLevel", default="")).upper()
        if lock_level != "SOFT":
            continue

        team_id = str(_get(template, "team_id", "teamId"))
        venue_id = str(_get(template, "venue_id", "venueId"))
        day_of_week = int(_get(template, "day_of_week", "dayOfWeek"))
        start_time = str(_get(template, "start_time", "startTime"))

        found = any(
            slot["teamId"] == team_id
            and slot["venueId"] == venue_id
            and slot["dayOfWeek"] == day_of_week
            and slot["startTime"] == start_time
            for slot in slots
        )
        if not found:
            diagnostics.append(
                {
                    "id": f"diag-soft-moved-{team_id}-{day_of_week}-{start_time}",
                    "type": "soft_lock_moved",
                    "severity": "WARNING",
                    "teamId": team_id,
                    "venueId": venue_id,
                    "message": (
                        f"The preferred slot for team {team_id} at {venue_id} "
                        f"on day {day_of_week} starting at {start_time} was moved. "
                        "The solver found a better overall fit by shifting this session."
                    ),
                    "suggestions": [
                        "Review the new time and confirm it still works for the team.",
                        "If the original time is essential, consider raising the lock to HARD.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
    return diagnostics


def _diagnose_coach_overload(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Flag coaches working more DAYS than their recommended maximum."""
    diagnostics: list[dict[str, Any]] = []
    coach_names = _coach_name_map(model_data)
    # ENG-24: the threshold (_coach_threshold = maxDaysOverride) is a number of DAYS, so count
    # distinct working days per coach — NOT 15-min blocks (two 90-min sessions on the same day
    # = 1 day worked, not 12 blocks) which produced systematic false alarms.
    coach_days: dict[str, set[int]] = defaultdict(set)
    for slot in slots:
        coach_id = slot.get("coachId")
        if coach_id and slot.get("dayOfWeek") is not None:
            coach_days[coach_id].add(int(slot["dayOfWeek"]))

    for coach_id, days in coach_days.items():
        count = len(days)
        threshold = _coach_threshold(model_data, coach_id)
        if count > threshold:
            diagnostics.append(
                {
                    "id": f"diag-overload-{coach_id}",
                    "type": "coach_overload",
                    "severity": "WARNING",
                    "coachId": coach_id,
                    "message": (
                        f"Le coach {_label(coach_id, coach_names)} intervient sur {count} jours, "
                        f"au-dessus de la limite recommandée de {threshold} : "
                        "risque de fatigue ou de conflits d'agenda."
                    ),
                    "suggestions": [
                        "Répartissez certaines séances sur un autre coach.",
                        "Vérifiez le nombre de jours maximum dans le profil du coach.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
    return diagnostics


def _collect_session_causes(
    model: ScheduleCpModel,
    solver: cp_model.CpSolver,
) -> dict[str, dict[str, Any]]:
    """Agrège, PAR ÉQUIPE, la cause MESURÉE des créneaux non retenus (P4-99, décision B).

    Ne RE-TESTE aucune règle : lit les fermetures enregistrées À LA POSE — par variable dans
    ``model.candidate_closures``, et pour les candidats SANS variable (retirés par le verrou
    d'une autre équipe) dans ``model.lock_removed_candidates``. Un candidat dont la variable
    EXISTE, sans fermeture, et non retenu (``solver.Value == 0``) tombe dans la famille
    « resté ouvert » : on rapporte le COMPTE seul, jamais qui a pris la place (ce serait une
    re-dérivation). Renvoie ``team_id -> {"causes": [...], "openCandidates": int}`` où chaque
    cause est ``{kind, constraintId, label, count}`` (forme ``DiagnosticCauseSchema``)."""
    candidate_closures: dict[int, list[dict[str, Any]]] = getattr(model, "candidate_closures", {}) or {}
    lock_removed: dict[Any, dict[str, Any]] = getattr(model, "lock_removed_candidates", {}) or {}

    # team -> (kind, constraintId, label) -> nombre de créneaux fermés par cette cause
    aggregated: dict[str, dict[tuple[Any, Any, Any], int]] = defaultdict(lambda: defaultdict(int))
    open_counts: dict[str, int] = defaultdict(int)

    for slot_key, var in model.x.items():
        if solver.Value(var) != 0:
            continue  # créneau RETENU — ce n'est pas un candidat manquant
        team_id = str(slot_key[0])
        closures = candidate_closures.get(int(var.Index()))
        if closures:
            for cause in closures:
                key = (cause.get("kind"), cause.get("constraintId"), cause.get("label"))
                aggregated[team_id][key] += 1
        else:
            open_counts[team_id] += 1  # existe, non fermé, non retenu → resté ouvert

    for slot_key, cause in lock_removed.items():
        team_id = str(slot_key[0])
        key = (cause.get("kind"), cause.get("constraintId"), cause.get("label"))
        aggregated[team_id][key] += 1

    result: dict[str, dict[str, Any]] = {}
    for team_id in set(aggregated) | set(open_counts):
        causes = [
            {"kind": kind, "constraintId": constraint_id, "label": label, "count": count}
            for (kind, constraint_id, label), count in aggregated.get(team_id, {}).items()
        ]
        result[team_id] = {"causes": causes, "openCandidates": open_counts.get(team_id, 0)}
    return result


def _diagnose_session_below_effective_min(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
    *,
    session_causes_by_team: Mapping[str, dict[str, Any]] | None = None,
) -> list[dict[str, Any]]:
    """Warn when a team's placed session units fall below its effective minimum."""
    diagnostics: list[dict[str, Any]] = []
    causes_by_team = session_causes_by_team or {}

    tier_min: dict[int, int] = {}
    for tier in _collection(model_data, "priorityTiers", "priority_tiers"):
        tid = _get(tier, "id")
        default_min = _get(tier, "defaultMinSessions", "default_min_sessions")
        if tid is not None and default_min is not None:
            with contextlib.suppress(TypeError, ValueError):
                tier_min[int(tid)] = int(default_min)

    for constraint in _collection(model_data, "constraints"):
        if not isinstance(constraint, Mapping):
            continue
        if constraint.get("type") != "PRIORITY_TIER":
            continue
        metadata = constraint.get("metadata") or {}
        tier_id = metadata.get("id")
        default_min = metadata.get("defaultMinSessions")
        if tier_id is not None and default_min is not None:
            with contextlib.suppress(TypeError, ValueError):
                tier_min[int(tier_id)] = int(default_min)

    # Count SESSIONS (one placed slot = one session), not 15-min units. The
    # comparison is against sessionsPerWeek / tier default_min, both expressed
    # in sessions — counting units (duration // 15) would make a single 90-min
    # session look like 6 and hide a genuinely missing session.
    placed_counts: dict[str, int] = defaultdict(int)
    for slot in slots:
        team_id = slot.get("teamId")
        if team_id:
            placed_counts[str(team_id)] += 1

    teams: dict[str, Any] = {}
    team_names: dict[str, str] = {}
    for team in _collection(model_data, "teams"):
        team_id = str(_get(team, "id", "team_id", "teamId"))
        teams[team_id] = team
        team_names[team_id] = str(_get(team, "name", "team_name", default=team_id))

    for team_id, team in teams.items():
        spw_raw = _get(team, "sessions_per_week", "sessionsPerWeek", default=None)
        if spw_raw is None:
            continue
        spw = int(spw_raw)

        tier_id_raw = _get(team, "priority_tier_id", "priorityTierId", default=None)
        effective_min = spw
        if tier_id_raw is not None and tier_min:
            try:
                tier_key = int(tier_id_raw)
            except (TypeError, ValueError):
                tier_key = None
            if tier_key is not None and tier_key in tier_min:
                effective_min = min(spw, tier_min[tier_key])

        placed = placed_counts.get(team_id, 0)
        # Warn whenever fewer sessions were placed than the team REQUESTED
        # (sessionsPerWeek), even if its tier floor (effective_min) is met — the
        # manager still needs to know a requested session is missing. Below the
        # tier floor is the more severe case (the guaranteed minimum was missed).
        if placed < spw:
            team_name = team_names.get(team_id, team_id)
            below_floor = placed < effective_min
            severity = "ERROR" if below_floor else "WARNING"
            # Message NEUTRE : on rapporte le FAIT (N demandée(s), M placée(s), et le cas
            # échéant sous le minimum cible), jamais une CAUSE devinée. Affirmer « créneaux
            # de gymnase insuffisants » / « faute de créneau disponible » était un mensonge
            # de diagnostic — sous V10 une séance peut manquer alors que des créneaux étaient
            # libres (le remplissage prime, mais un conflit dur ou un arbitrage a laissé un
            # trou). La vraie transgression, quand il y en a une, remonte par son diagnostic
            # dédié (verrou, conflit, coach_overload…), pas par une cause inventée ici.
            # "cible", pas "garanti" : le minimum est visé en objectif soft (ENG-18), pas
            # garanti en plancher dur.
            reason = f" — en-dessous de son minimum cible de {effective_min}." if below_floor else "."
            # P4-99 — la cause RÉELLE, MESURÉE à la pose (jamais devinée) : la liste des règles
            # ayant fermé un créneau de cette équipe (cliquable côté front) + le compte des
            # créneaux « restés ouverts » (libres, non fermés, non retenus). Absent de la carte
            # (aucune donnée mesurée) → causes vide + openCandidates None : on garde alors le
            # message NEUTRE et les suggestions statiques, sans inventer de cause.
            team_causes = causes_by_team.get(team_id, {})
            diagnostics.append(
                {
                    "id": f"diag-session-below-min-{team_id}",
                    "type": "session_below_effective_min",
                    "severity": severity,
                    "teamId": team_id,
                    "message": (
                        f"L'équipe {team_name} : {spw} séance(s) demandée(s) par semaine, "
                        f"seulement {placed} placée(s){reason}"
                    ),
                    "suggestions": [
                        "Ajoutez de la disponibilité de gymnase ou un créneau supplémentaire pour cette équipe.",
                        "Vérifiez le tier de priorité et le nombre de séances/semaine de l'équipe.",
                    ],
                    "causes": team_causes.get("causes", []),
                    "openCandidates": team_causes.get("openCandidates"),
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    return diagnostics


def _diagnose_conflicts(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
    *,
    slot_capacities: dict[Any, int] | None = None,
) -> list[dict[str, Any]]:
    """Report infeasibility or detected double-bookings — who, when, why.

    ``slot_capacities`` maps ``(venue_id, day_of_week, start_time)`` to the
    maximum number of teams allowed simultaneously.  When provided, a venue
    booking is only flagged as a conflict when the number of teams exceeds the
    slot's declared capacity (supporting multi-team training slots with
    capacity > 1).  When absent, the legacy threshold of 1 is used.
    """
    diagnostics: list[dict[str, Any]] = []
    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    coach_names = _coach_name_map(model_data)

    if solver_status == cp_model.INFEASIBLE:
        diagnostics.append(
            {
                "id": "diag-infeasible",
                "type": "conflict",
                "severity": "ERROR",
                "message": _infeasible_message(model_data),
                "suggestions": [
                    "Assouplissez ou retirez une contrainte dure (jour/heure imposé, gymnase forcé).",
                    "Ajoutez de la disponibilité de gymnase ou un coach supplémentaire.",
                    "Vérifiez les créneaux verrouillés (LOCK) qui se chevauchent entre équipes.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
        return diagnostics

    if solver_status == cp_model.UNKNOWN:
        # ENG-22: the solver stopped WITHOUT a solution and WITHOUT proving infeasibility — the
        # time budget ran out on a hard instance. Say so, instead of a silent "failed".
        diagnostics.append(
            {
                "id": "diag-timeout",
                "type": "conflict",
                "severity": "ERROR",
                "message": (
                    "Le solveur n'a pas trouvé de planning dans le temps imparti (problème trop "
                    "complexe). Aucune infaisabilité prouvée — une solution existe peut-être avec "
                    "plus de temps ou moins de contraintes."
                ),
                "suggestions": [
                    "Réduisez la taille du problème (équipes / gymnases) ou le nombre de contraintes.",
                    "Relancez la génération : le solveur peut aboutir sur un nouvel essai.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
        return diagnostics

    if solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # ENG-22: MODEL_INVALID (or any other non-solve status) is a construction bug, NOT a
        # time problem — "retry / shrink" would mislead. Surface it as an internal error.
        diagnostics.append(
            {
                "id": "diag-solver-error",
                "type": "conflict",
                "severity": "ERROR",
                "message": (
                    "Erreur interne du solveur (modèle invalide). Ce n'est pas un problème de "
                    "taille ni de temps — signalez-le au support."
                ),
                "suggestions": ["Contactez le support : la génération n'a pas pu être construite correctement."],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
        return diagnostics

    _caps: dict[Any, int] = slot_capacities or {}

    # P2-51 — mutualisation par BLOC : une séance de bloc = UNE occupation d'une case (pas N).
    # Une réservation de bloc s'éclate en N `Reservation` (une par membre, même case) → N verrous
    # HARD ; les compter comme N occupants distincts crierait faussement à la sur-capacité. Le
    # ``team_to_group`` (exemption du modèle groupe K) a disparu avec ce modèle : il reste une carte
    # VIDE que le repli des blocs ({@see _fold_case_occupant_identity}) consomme sans effet.
    team_to_group: dict[str, str] = {}

    # P2-51 — mutualisation par BLOC : une séance de bloc = UNE occupation de la case (pas N). La
    # multi-appartenance étant permise (une équipe dans plusieurs blocs), l'attribution se fait PAR
    # CASE (un bloc dont TOUS les membres siègent ICI se fond en un occupant), jamais « premier bloc
    # gagne » via une carte globale. Bloc `sharedBlocks` absent ⇒ liste vide ⇒ comptage == groupes
    # ⇒ chemin byte-identique (goldens inchangés).
    blocks: list[tuple[str, frozenset[str]]] = []
    for block_index, block in enumerate(_collection(model_data, "sharedBlocks", "shared_blocks")):
        block_members = frozenset(str(m) for m in (_get(block, "teamIds", "team_ids", default=[]) or []))
        if len(block_members) >= 2:
            blocks.append((f"__shared_block__{_get(block, 'id', default=block_index)}", block_members))

    # Post-solve safety check: venue over-capacity.
    venue_bookings: dict[tuple[str, int, str], list[str]] = defaultdict(list)
    venue_durations: dict[tuple[str, int, str], int] = {}
    for slot in slots:
        key = (slot["venueId"], slot["dayOfWeek"], slot["startTime"])
        venue_bookings[key].append(slot["teamId"])
        venue_durations[key] = max(venue_durations.get(key, 0), int(slot.get("durationMinutes") or 0))

    for (venue_id, day_of_week, start_time), booked in venue_bookings.items():
        # Distinct teams only: at a fixed (venue, day, start), the same team twice
        # is the duplicate-slot artifact, not over-capacity (audit ENG-09).
        team_ids = list(dict.fromkeys(booked))
        capacity = _caps.get((venue_id, day_of_week, start_time), 1)
        # P2-46 / P2-51 — chaque membre d'un groupe OU d'un bloc co-localisé se fond en UN occupant.
        # Groupes : carte globale (unicité). Blocs : fondus PAR CASE (multi-appartenance). Sans
        # groupe ni bloc, `occupants == team_ids` (chemin byte-identique).
        if blocks:
            identity, block_keys_here = _fold_case_occupant_identity(team_ids, team_to_group, blocks)
            occupants = set(identity.values())
            occupant_text = _occupant_list_with_blocks(team_ids, identity, block_keys_here, team_names)
        else:
            occupants = {team_to_group.get(team_id, team_id) for team_id in team_ids}
            occupant_text = _occupant_list(team_ids, team_to_group, team_names)
        if len(occupants) > capacity:
            when = f"{_day_label(day_of_week)} {_time_range(start_time, venue_durations.get((venue_id, day_of_week, start_time)))}"
            diagnostics.append(
                {
                    "id": f"diag-conflict-venue-{venue_id}-{day_of_week}-{start_time}",
                    "type": "conflict",
                    "severity": "ERROR",
                    "venueId": venue_id,
                    "dayOfWeek": day_of_week,
                    "startTime": str(start_time)[:5],
                    # P2-46 — le message COMPTE ce que la règle a compté : `occupants`, pas
                    # `team_ids`. Sinon il ment sur le remède — « 3 équipes / capacité 1 » avec
                    # un groupe de 2 + une étrangère ferait viser une capacité 3 quand 2 suffit,
                    # et « déplacez une séance » enverrait déplacer UN membre, ce qui ne libère
                    # rien (le groupe reste). Un groupe est nommé comme un seul occupant.
                    "message": (
                        f"Le gymnase {_label(venue_id, venue_names)} accueille {len(occupants)} "
                        f"{'occupant' if len(occupants) == 1 else 'occupants'} en même temps le {when} "
                        f"alors que sa capacité est de {capacity} : "
                        f"{occupant_text}."
                    ),
                    "suggestions": [
                        "Déplacez l'une des séances sur un autre horaire ou un autre gymnase.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    # Post-solve safety check: coach double-booking.
    #
    # D-14 (2026-08-09) — deux corrections, faites ensemble parce qu'elles portaient sur la
    # MÊME question et se contredisaient :
    #
    #  1. La clé était `(coach, jour, heure de début EXACTE)`. Deux séances 17h00-18h30 et
    #     17h30-19h00 dédoublent pourtant bien le coach : elles tombaient dans deux clés
    #     distinctes et passaient inaperçues. La contrainte HARD du solveur, elle, teste
    #     l'intersection d'intervalles depuis toujours — ce filet était donc plus laxiste
    #     que le modèle qu'il est censé surveiller. On aligne sur les intervalles.
    #
    #  2. Le MÊME gymnase n'est PAS un conflit (arbitrage fondateur) : un coach peut tenir
    #     les SM1 et les SM2 côte à côte, il est présent une fois. Le diagnostic remontait
    #     une ERROR rouge sur un geste que le backend et l'UI offrent explicitement.
    #
    # Ce qui reste un conflit : gymnases DIFFÉRENTS et intervalles qui se chevauchent — y
    # compris pour la MÊME équipe (elle ne peut pas être à deux endroits non plus). Le
    # doublon même-équipe/même-gymnase (artefact de template dupliqué) reste, lui, muet.
    coach_slots: dict[tuple[str, int], list[tuple[int, int, str, str, str]]] = defaultdict(list)
    for slot in slots:
        coach_id = slot.get("coachId")
        if not coach_id:
            continue
        start_minutes = _time_to_minutes(slot["startTime"])
        duration = int(slot.get("durationMinutes") or 0)
        coach_slots[(str(coach_id), slot["dayOfWeek"])].append(
            (
                start_minutes,
                start_minutes + duration,
                str(slot["teamId"]),
                str(slot["venueId"]),
                str(slot["startTime"]),
            ),
        )

    for (clash_coach, clash_day), clash_booked in sorted(coach_slots.items()):
        ordered = sorted(clash_booked)
        seen_pairs: set[tuple[str, str]] = set()
        for i, (a_start, a_end, a_team, a_venue, a_raw) in enumerate(ordered):
            for b_start, b_end, b_team, b_venue, _b_raw in ordered[i + 1 :]:
                if a_venue == b_venue:
                    continue  # même gymnase : le coach n'y est qu'une fois (D-14)
                if not (a_start < b_end and b_start < a_end):
                    continue  # intervalles demi-ouverts : se toucher n'est pas se chevaucher
                pair = (a_team, b_team) if a_team <= b_team else (b_team, a_team)
                if pair in seen_pairs:
                    continue
                seen_pairs.add(pair)
                when = f"{_day_label(clash_day)} {_time_range(a_raw, a_end - a_start)}"
                diagnostics.append(
                    {
                        "id": f"diag-conflict-coach-{clash_coach}-{clash_day}-{a_raw}",
                        "type": "conflict",
                        "severity": "ERROR",
                        "coachId": clash_coach,
                        "dayOfWeek": clash_day,
                        "startTime": str(a_raw)[:5],
                        "message": (
                            f"Le coach {_label(clash_coach, coach_names)} est affecté à plusieurs équipes "
                            f"en même temps le {when}, dans des gymnases différents : {_named_list(list(pair), team_names)}."
                        ),
                        "suggestions": [
                            "Séparez les séances ou affectez un autre coach à l'une des équipes.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )

    return diagnostics


def _occupant_list_with_blocks(
    team_ids: list[str], identity: Mapping[str, str], block_keys: set[str], names: Mapping[str, str]
) -> str:
    """Miroir de ``_occupant_list`` étendu aux blocs : une clé de bloc fondue s'énonce « le bloc
    mutualisé (A, B) », une clé de groupe « le groupe mutualisé (…) », le reste équipe par équipe."""
    parts: list[str] = []
    seen: set[str] = set()
    for team_id in team_ids:
        key = identity.get(team_id, team_id)
        if key in seen:
            continue
        seen.add(key)
        if key in block_keys:
            members = [_label(other, names) for other in team_ids if identity.get(other) == key]
            parts.append(f"le bloc mutualisé ({', '.join(members)})")
        elif isinstance(key, str) and key.startswith("__shared_group__"):
            members = [_label(other, names) for other in team_ids if identity.get(other) == key]
            parts.append(f"le groupe mutualisé ({', '.join(members)})")
        else:
            parts.append(_label(team_id, names))
    return ", ".join(parts)


def _diagnose_shared_blocks(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """P2-51 (arbitrage n°7) — mutualisation par BLOC : nommer le bloc quand il n'est pas honoré,
    code ``shared_block_not_honored`` (severity ERROR).

      * INFEASIBLE — cause PROUVÉE : le bloc a MOINS de cases communes candidates (gymnase, jour,
        heure où TOUS ses membres ont un créneau disponible) que ses ``commonSessions`` — il ne
        pourra jamais placer ses séances. On nomme le bloc (le message ``diag-infeasible`` reste,
        celui-ci l'attribue). Prudent : on n'accuse que quand le compte de cases l'exclut.
      * OPTIMAL/FEASIBLE — défense en profondeur : si le nombre RÉEL de séances communes du bloc
        (co-présence de TOUS ses membres dans les slots finaux) diffère de ``commonSessions``.

    Message français nommant les équipes réelles du bloc ; aucun identifiant interne."""
    blocks = _collection(model_data, "sharedBlocks", "shared_blocks")
    if not blocks:
        return []

    team_names = _team_name_map(model_data)
    diagnostics: list[dict[str, Any]] = []

    if solver_status == cp_model.INFEASIBLE:
        # Cases (gymnase, jour, heure) qui EXISTENT — n'importe quelle équipe peut y siéger, donc
        # ce sont les cases communes candidates d'un bloc. Un bloc qui en a moins que sa demande de
        # séances est provablement non plaçable (miroir du ``Σb == commonSessions`` insatisfiable).
        candidate_cases = 0
        for venue in _collection(model_data, "venues"):
            for _slot in _collection(venue, "training_slots", "trainingSlots"):
                candidate_cases += 1
        for index, block in enumerate(blocks):
            members = [str(t) for t in (_get(block, "teamIds", "team_ids", default=[]) or [])]
            if len(members) < 2:
                continue
            common_sessions = int(_get(block, "commonSessions", "common_sessions", default=0) or 0)
            if candidate_cases < common_sessions:
                diagnostics.append(
                    {
                        "id": f"shared-block-infeasible-{_get(block, 'id', default=index)}",
                        "type": "shared_block_not_honored",
                        "severity": "ERROR",
                        "message": (
                            f"Le bloc de mutualisation ({_named_list(members, team_names)}) ne peut pas placer "
                            f"ses {common_sessions} séance(s) commune(s) : il n'existe que {candidate_cases} "
                            "créneau(x) de gymnase où réunir ses équipes. Ajoutez des créneaux communs ou "
                            "réduisez le nombre de séances communes du bloc."
                        ),
                        "suggestions": [
                            "Ajoutez des créneaux de gymnase où toutes les équipes du bloc peuvent se réunir.",
                            "Réduisez le nombre de séances communes déclarées pour le bloc.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )
        return diagnostics

    if solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        return []

    occupancy: dict[str, set[tuple[str, int, str]]] = defaultdict(set)
    for slot in slots:
        day = _slot_day(slot)
        if day is None:
            continue
        occupancy[str(slot["teamId"])].add((str(slot["venueId"]), day, str(slot["startTime"])[:5]))

    for index, block in enumerate(blocks):
        members = [str(t) for t in (_get(block, "teamIds", "team_ids", default=[]) or [])]
        if len(members) < 2:
            continue
        common_sessions = int(_get(block, "commonSessions", "common_sessions", default=0) or 0)
        member_sets = [occupancy.get(member, set()) for member in members]
        common = set.intersection(*member_sets) if member_sets else set()
        if len(common) != common_sessions:
            diagnostics.append(
                {
                    "id": f"shared-block-not-honored-{_get(block, 'id', default=index)}",
                    "type": "shared_block_not_honored",
                    "severity": "ERROR",
                    "message": (
                        f"Le bloc de mutualisation n'est pas respecté : les équipes "
                        f"{_named_list(members, team_names)} devraient partager {common_sessions} séance(s) "
                        f"commune(s) en bloc mais en partagent {len(common)}."
                    ),
                    "suggestions": ["Vérifiez les disponibilités communes de ces équipes ou ajustez le bloc."],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
    return diagnostics


def _team_link_placements_from_slots(slots: list[dict[str, Any]]) -> dict[str, list[tuple[int, int, int, str, None]]]:
    """Les PLACES finales par équipe, au format ``iter_team_link_overlaps`` (var toujours None :
    on juge la solution posée, pas des variables). ``(start, end, day, venue, None)``."""
    placements: dict[str, list[tuple[int, int, int, str, None]]] = defaultdict(list)
    for slot in slots:
        day = _slot_day(slot)
        if day is None:
            continue
        try:
            start = _time_to_minutes(str(slot["startTime"])[:5])
        except (KeyError, ValueError, TypeError):
            continue
        duration = int(slot.get("durationMinutes") or DEFAULT_SESSION_MINUTES)
        placements[str(slot["teamId"])].append((start, start + duration, day, str(slot["venueId"]), None))
    return placements


def _diagnose_team_links(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Lot PASSERELLES PR-2 — NOMMER un chevauchement RÉSIDUEL entre deux équipes passerelées.

    Un seul code ``team_link_not_honored`` (ERROR), sur un solve abouti : on lit les PLACES
    finales et, pour chaque passerelle, on compte les chevauchements NON exemptés (même géométrie
    et même exemption doctrinale que la pose — ``iter_team_link_overlaps``). Deux régimes convergent
    ici, aucun n'est INFEASIBLE muet (CLAUDE.md §6) :

      * ``PREFERRED`` — le solveur a CÉDÉ le malus (les deux séances coïncident malgré la pénalité) ;
      * ``MANDATORY`` — le seul chevauchement possible est deux VERROUS HARD (``add_team_link_constraints``
        ne pose rien entre deux constantes) : deux actes volontaires du gestionnaire qui se
        contredisent, annoncés plutôt qu'avalés.

    Message français nommant les deux équipes réelles ; aucun identifiant interne. Une séance
    mutualisée DÉCLARÉE (même case + groupe partagé) n'est JAMAIS rapportée (exemption)."""
    links = _collection(model_data, "teamLinks", "team_links")
    if not links or solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        return []

    team_names = _team_name_map(model_data)
    share_pairs = team_share_declared_pairs(_collection(model_data, "sharedBlocks", "shared_blocks"))
    placements = _team_link_placements_from_slots(slots)

    diagnostics: list[dict[str, Any]] = []
    for index, link in enumerate(links):
        team_a = str(_get(link, "teamAId", "team_a_id", default=""))
        team_b = str(_get(link, "teamBId", "team_b_id", default=""))
        if not team_a or not team_b or team_a == team_b:
            continue
        share_declared = frozenset({team_a, team_b}) in share_pairs
        overlaps = list(
            iter_team_link_overlaps(
                placements.get(team_a, []), placements.get(team_b, []), share_declared=share_declared
            )
        )
        if not overlaps:
            continue
        intensity = str(_get(link, "intensity", default="PREFERRED"))
        link_id = _get(link, "id", default=index)
        diagnostics.append(
            {
                "id": f"team-link-not-honored-{link_id}",
                "type": "team_link_not_honored",
                "severity": "ERROR",
                "message": (
                    f"Les équipes {_label(team_a, team_names)} et {_label(team_b, team_names)}, déclarées "
                    f"en passerelle, ont {len(overlaps)} séance(s) qui se chevauchent dans le temps"
                    + (
                        " : deux séances verrouillées se contredisent."
                        if intensity == "MANDATORY"
                        else " (chevauchement toléré à contrecœur)."
                    )
                ),
                "suggestions": [
                    "Déplacez l'une des séances pour qu'elles ne se chevauchent plus, "
                    "ou déclarez ces équipes en séance mutualisée si le chevauchement est voulu.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics


def _diagnose_travel_times(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
    team_coach_map: Mapping[str, list[str]],
) -> list[dict[str, Any]]:
    """P2-53 RMM-8 PR-2 — NOMMER un battement de trajet RÉSIDUEL sous une règle MANDATORY.

    ``add_travel_time_hard_constraints`` interdit tout enchaînement cross-gymnase au battement
    trop court entre séances LIBRES (ou libre⇔verrou). Le seul cas qui SURVIT est deux séances
    VERROUILLÉES qui s'enchaînent trop serré à des gymnases différents : deux actes du
    gestionnaire qui se contredisent, ANNONCÉS post-solve plutôt qu'avalés (« jamais INFEASIBLE
    muet », CLAUDE.md §6 — patron ``_diagnose_team_links``). PREFERRED ne passe pas ici : son
    battement concédé est un COMPROMIS (famille ``travel_time``), pas un diagnostic. Matrice
    absente / règle inactive ou PREFERRED / solve non abouti ⇒ ``[]``."""
    implicit = _get(model_data, "implicitRules", "implicit_rules", default=None)
    travel = _get(implicit, "travelTime", "travel_time", default=None) if implicit is not None else None
    if travel is None or solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        return []
    if str(_get(travel, "intensity", default="PREFERRED")).upper() != "MANDATORY":
        return []

    matrix = build_travel_matrix(_collection(model_data, "venueTravelTimes", "venue_travel_times"))
    if not matrix:
        return []
    default_minutes = int(_get(travel, "defaultMinutes", "default_minutes", default=20))

    placements = _team_link_placements_from_slots(slots)
    coach_names = _coach_name_map(model_data)
    team_names = _team_name_map(model_data)
    vehicled = {
        str(_get(c, "id", default="")): bool(_get(c, "is_vehicled", "isVehicled", default=False))
        for c in _collection(model_data, "coaches")
    }

    def _too_tight(pa: tuple[int, int, int, str, None], pb: tuple[int, int, int, str, None], *, driving: bool) -> bool:
        # ENG-37 — le prédicat battement/barème n'est PLUS recalculé ici : la source unique
        # (``is_travel_too_tight``, qui compose ``_cross_venue_gap`` + ``_barometer``) est celle-là
        # même que la pose du solveur consomme. Le diagnostic juge donc EXACTEMENT la géométrie que
        # ``add_travel_time_hard_constraints`` a interdite.
        return is_travel_too_tight(pa, pb, driving=driving, matrix=matrix, default_minutes=default_minutes)

    diagnostics: list[dict[str, Any]] = []
    seen: set[tuple[str, ...]] = set()

    # Voyageur COACH : ses séances (celles de ses équipes), barème voiture/à pied selon véhiculé.
    coach_teams: dict[str, list[str]] = defaultdict(list)
    for team_id, coach_ids in (team_coach_map or {}).items():
        for coach_id in coach_ids or ():
            coach_teams[str(coach_id)].append(str(team_id))
    for coach_id, team_ids in coach_teams.items():
        gathered = [p for team_id in team_ids for p in placements.get(team_id, [])]
        ordered = sorted(gathered, key=lambda p: (p[2], p[0], p[1], p[3]))
        driving = vehicled.get(coach_id, False)
        for i in range(len(ordered)):
            for j in range(i + 1, len(ordered)):
                if not _too_tight(ordered[i], ordered[j], driving=driving):
                    continue
                key: tuple[str, ...] = ("coach", coach_id, str(ordered[i][2]), ordered[i][3], ordered[j][3])
                if key in seen:
                    continue
                seen.add(key)
                diagnostics.append(
                    {
                        "id": f"travel-time-infeasible-coach-{coach_id}-{ordered[i][2]}-{ordered[i][3]}-{ordered[j][3]}",
                        "type": "travel_time_infeasible",
                        "severity": "ERROR",
                        "coachId": coach_id,
                        "message": (
                            f"Le coach {_label(coach_id, coach_names)} enchaîne deux séances verrouillées "
                            "à des gymnases différents sans avoir le temps de faire le trajet entre les deux."
                        ),
                        "suggestions": [
                            "Déverrouillez l'une des deux séances, écartez-les dans la journée, "
                            "ou ajustez le temps de trajet entre ces deux gymnases.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )

    # Voyageur PASSERELLE : séances de A face à celles de B, barème À PIED d'office.
    for index, link in enumerate(_collection(model_data, "teamLinks", "team_links")):
        team_a = str(_get(link, "teamAId", "team_a_id", default=""))
        team_b = str(_get(link, "teamBId", "team_b_id", default=""))
        if not team_a or not team_b or team_a == team_b:
            continue
        for pa in placements.get(team_a, []):
            for pb in placements.get(team_b, []):
                if not _too_tight(pa, pb, driving=False):
                    continue
                key = ("link", team_a, team_b, str(pa[2]), pa[3], pb[3])
                if key in seen:
                    continue
                seen.add(key)
                diagnostics.append(
                    {
                        "id": f"travel-time-infeasible-link-{index}-{pa[2]}-{pa[3]}-{pb[3]}",
                        "type": "travel_time_infeasible",
                        "severity": "ERROR",
                        "message": (
                            f"Les équipes {_label(team_a, team_names)} et {_label(team_b, team_names)}, "
                            "déclarées en passerelle, ont des séances verrouillées à des gymnases différents "
                            "sans le temps de faire le trajet à pied entre les deux."
                        ),
                        "suggestions": [
                            "Déverrouillez l'une des séances, écartez-les dans la journée, "
                            "ou ajustez le temps de trajet entre ces deux gymnases.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )

    return diagnostics


def _slot_capacity_by_key(model_data: Mapping[str, Any] | Any) -> dict[tuple[str, str, str], int]:
    """PLACES, not slots — mirrors ``model.slot_capacities``: a dict keyed on
    (venue, day, start), so duplicate triplets overwrite instead of adding, and a
    2-team slot counts 2. Counting ``len(slots)`` claimed « capacité insuffisante »
    on a club that had 87 places for 84 sessions (BCCL, 2026-08-06)."""
    capacities: dict[tuple[str, str, str], int] = {}
    for venue in _collection(model_data, "venues"):
        venue_id = str(_get(venue, "id", default=""))
        for slot in _collection(venue, "training_slots", "trainingSlots"):
            day = str(_get(slot, "day_of_week", "dayOfWeek", default=""))
            start = str(_get(slot, "start_time", "startTime", default=""))[:5]
            raw_capacity = _get(slot, "capacity", default=1)
            try:
                capacity = int(raw_capacity) if raw_capacity is not None else 1
            except (TypeError, ValueError):
                capacity = 1
            capacities[(venue_id, day, start)] = max(1, capacity)
    return capacities


def _saturated_venue_minimum(
    model_data: Mapping[str, Any] | Any,
    capacities: Mapping[tuple[str, str, str], int],
) -> tuple[str, int, int] | None:
    """Name the venue whose « au moins N ici » minimums outgrow its FREE places.

    The model enforces each minimum on its VARIABLES (sum >= N), and a HARD-locked
    triplet has no variable for anyone (``model.py:62-63``) — so the places a pin
    consumes are gone for every minimum. Demand = Σ of per-team minimums (max per
    team×venue: the model posts one ``>=`` per rule, the max dominates); free =
    Σ capacities of unpinned triplets. demand > free ⇒ provably INFEASIBLE."""
    min_by_venue_team: dict[str, dict[str, int]] = {}
    for row in _collection(model_data, "constraints"):
        config = _get(row, "config", default=None)
        config = config if isinstance(config, Mapping) else {}
        if (
            _get(row, "family", default=None) != "FACILITY"
            or not config.get("minAtVenueId")
            or _get(row, "ruleType", "rule_type", default=None) not in ("HARD", "LOCK")
            or _get(row, "scope", default=None) != "TEAM"
            or _get(row, "isActive", "is_active", default=True) is False
        ):
            continue
        team_id = _get(row, "scopeTargetId", "scope_target_id", default=None)
        if not team_id:
            continue
        raw_count = config.get("minAtVenueCount")
        minimum = max(1, int(raw_count) if raw_count is not None else 1)
        per_team = min_by_venue_team.setdefault(str(config["minAtVenueId"]), {})
        per_team[str(team_id)] = max(per_team.get(str(team_id), 0), minimum)

    if not min_by_venue_team:
        return None

    pinned: set[tuple[str, str, str]] = set()
    for pin in _collection(model_data, "slotTemplates", "slot_templates"):
        if _get(pin, "lockLevel", "lock_level", default=None) != "HARD":
            continue
        pinned.add(
            (
                str(_get(pin, "venueId", "venue_id", default="")),
                str(_get(pin, "dayOfWeek", "day_of_week", default="")),
                str(_get(pin, "startTime", "start_time", default=""))[:5],
            )
        )

    venue_names = {
        str(_get(venue, "id", default="")): str(_get(venue, "name", default="") or _get(venue, "id", default=""))
        for venue in _collection(model_data, "venues")
    }
    for venue_id, min_by_team in min_by_venue_team.items():
        demand = sum(min_by_team.values())
        free = sum(capacity for key, capacity in capacities.items() if key[0] == venue_id and key not in pinned)
        if demand > free:
            return venue_names.get(venue_id, venue_id), demand, free
    return None


def _infeasible_message(model_data: Mapping[str, Any] | Any) -> str:
    """Explain infeasibility in manager terms, hinting at capacity shortfall."""
    demand = 0
    for team in _collection(model_data, "teams"):
        spw = _get(team, "sessions_per_week", "sessionsPerWeek", default=None)
        try:
            demand += int(spw) if spw is not None else 0
        except (TypeError, ValueError):
            continue
    capacities = _slot_capacity_by_key(model_data)
    supply = sum(capacities.values())

    base = (
        "Le planning n'a pas pu être généré : les contraintes actuelles sont impossibles à satisfaire toutes ensemble."
    )
    if demand and supply and demand > supply:
        return (
            f"{base} Il faut placer {demand} séance(s) par semaine pour seulement "
            f"{supply} place(s) de créneau déclarée(s) (capacités comprises) : "
            "la capacité est insuffisante."
        )
    saturated = _saturated_venue_minimum(model_data, capacities)
    if saturated is not None:
        venue_name, min_demand, free = saturated
        return (
            f"{base} Vos contraintes « au moins » réclament {min_demand} place(s) au "
            f"gymnase {venue_name}, qui n'en a que {free} de libre(s) une fois les "
            "créneaux réservés déduits : ce gymnase est saturé."
        )
    return (
        f"{base} Aucune affectation valide n'existe — cherchez des contraintes dures "
        "qui se contredisent (jour/heure imposés, gymnase forcé, créneaux verrouillés)."
    )


_DAY_NAMES = {
    0: "Sunday",
    1: "Monday",
    2: "Tuesday",
    3: "Wednesday",
    4: "Thursday",
    5: "Friday",
    6: "Saturday",
}


def _diagnose_unused_slots(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Warn about available training slots that received no team assignment.

    Only slots that were *available* (declared in ``venues[].trainingSlots``)
    but not used by any placed session are reported. Venue closures and coach
    unavailability are excluded because those slots are not in the available
    set the solver could use.
    """
    diagnostics: list[dict[str, Any]] = []

    used: set[tuple[str, int, str]] = {
        (str(slot["venueId"]), int(slot["dayOfWeek"]), str(slot["startTime"])) for slot in slots
    }

    for venue in _collection(model_data, "venues"):
        venue_id = str(_get(venue, "id"))
        venue_name = str(_get(venue, "name", default=venue_id))
        for ts in _collection(venue, "training_slots", "trainingSlots"):
            day_of_week = int(_get(ts, "day_of_week", "dayOfWeek"))
            start_time = str(_get(ts, "start_time", "startTime"))
            duration = int(_get(ts, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))

            if (venue_id, day_of_week, start_time) in used:
                continue

            start_minutes = _time_to_minutes(start_time)
            end_minutes = start_minutes + duration
            end_time = _format_time(end_minutes)
            day_name = _DAY_NAMES.get(day_of_week, str(day_of_week))

            diagnostics.append(
                {
                    "id": f"diag-unused-slot-{venue_id}-{day_of_week}-{start_time}",
                    "type": "unused_slot",
                    "severity": "WARNING",
                    "venueId": venue_id,
                    "dayOfWeek": day_of_week,
                    "startTime": start_time,
                    "durationMinutes": duration,
                    "message": f"{venue_name} {day_name} {start_time}-{end_time}: no team assigned",
                    "suggestions": [],
                    "teamId": None,
                    "coachId": None,
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    return diagnostics


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
