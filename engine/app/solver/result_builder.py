"""Transform a CP-SAT solution into a ScheduleOutputSchema-compatible dict.

The builder reads the solved boolean variables ``x[team, venue, day, slot]``,
merges them with pre-placed HARD locked slots, and produces manager-readable
diagnostics for any post-solve issues it detects.
"""

from __future__ import annotations

import contextlib
import uuid
from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from importlib.metadata import version
from typing import Any

from ortools.sat.python import cp_model

from app.solver.constraints import (
    ResolvedImplicitRules,
    iter_team_link_overlaps,
    team_share_declared_pairs,
)
from app.solver.model import (
    DEFAULT_SESSION_MINUTES,
    SLOT_MINUTES,
    ScheduleCpModel,
    _format_time,
    _time_to_minutes,
)
from app.solver.objective import SCORE_FORMULA_VERSION


def build_result(
    model_data: Mapping[str, Any] | Any,
    solver: cp_model.CpSolver,
    model: ScheduleCpModel,
    *,
    status: Any | None = None,
    constraint_version: str | None = None,
    team_coach_map: Mapping[str, list[str]] | None = None,
) -> dict[str, Any]:
    """Transform a CP-SAT solution into a dict matching ``ScheduleOutputSchema``.

    Args:
        model_data: The original input data (dict or Pydantic model).
        solver: The OR-Tools ``CpSolver`` instance after solving.
        model: The ``ScheduleCpModel`` containing variables and locked slots.
        team_coach_map: ENG-17 — la carte équipe → coachs MAIN issue de
            ``parse_v2_constraints``. SOURCE UNIQUE : c'est déjà elle que le solveur
            utilise pour l'exclusivité coach et pour attacher le coach aux
            assignments (``main.py``). La redériver ici recopierait sa règle (filtre
            de rôle, alias de clés) et les deux divergeraient.
        status: Optional solver status. If omitted, inferred from the solver.

    Returns:
        A dictionary that validates against ``ScheduleOutputSchema``.
    """
    if status is not None:
        solver_status = status
    elif hasattr(solver, "_checked_response") and hasattr(solver._checked_response, "status"):
        solver_status = solver._checked_response.status
    else:
        solver_status = cp_model.UNKNOWN

    schema_status = "completed" if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE) else "failed"

    slots: list[dict[str, Any]] = []
    diagnostics: list[dict[str, Any]] = []

    # Preserve HARD locked slots regardless of solver status.
    for locked in model.locked_slots:
        slots.append(_locked_slot_to_dict(locked))

    # Add solver-placed slots when the problem was solved successfully.
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # ENG-17 — la carte vient du MODÈLE par défaut (posée par `_solve`) ; le
        # paramètre reste pour les appels directs (tests, harnais) qui construisent
        # un modèle à la main.
        solver_slots = _build_solver_slots(
            model_data,
            solver,
            model,
            team_coach_map if team_coach_map is not None else getattr(model, "team_coach_map", None),
        )
        slots.extend(solver_slots)

    # Always run diagnostic checks. Le réglage implicite + les cartes coach/joueur viennent
    # du MODÈLE (posés par ``main._solve``) : les warnings de règle implicite doivent se
    # calculer au MÊME grain que la pose (personnes = coachs/joueurs des contraintes).
    slot_capacities: dict[Any, int] = getattr(model, "slot_capacities", {})
    resolved_team_coach_map = team_coach_map if team_coach_map is not None else getattr(model, "team_coach_map", None)
    # P4-99 — la cause MESURÉE d'un créneau manquant, par équipe. Calculée UNIQUEMENT sur un
    # solve abouti (les variables n'ont de valeur qu'alors) ; l'inversion var→équipe se fait
    # ici, depuis `model.x`, seul endroit qui tient variable ET slot_key.
    session_causes_by_team: dict[str, dict[str, Any]] = {}
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        session_causes_by_team = _collect_session_causes(model, solver)
    diagnostics.extend(
        _generate_diagnostics(
            model_data,
            solver_status,
            slots,
            slot_capacities=slot_capacities,
            implicit_rules=getattr(model, "implicit_rules", None),
            team_coach_map=resolved_team_coach_map,
            team_player_map=getattr(model, "team_player_map", None),
            session_causes_by_team=session_causes_by_team,
        )
    )

    unplaced = _unplaced_team_ids(model_data, slots)

    score: int | None = None
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # P3-21 — quand la phase 2 a appliqué le terme de stabilité, `_solve` a recalculé le
        # score aux poids d'ORIGINE (placement + chaînage naturel, stabilité exclue) car
        # `ObjectiveValue()` porterait alors le multiplicateur de chaînage + la stabilité.
        # Sans stabilité l'override reste None ⇒ `ObjectiveValue()` tel quel (byte-identique).
        override = getattr(model, "reported_score_override", None)
        score = override if override is not None else int(solver.ObjectiveValue())

    metrics = {
        "solver_version": version("ortools"),
        "nb_variables": int(model.NumVariables()),
        "nb_constraints": len(model.Proto().constraints),
        "wall_time_ms": round(solver.WallTime() * 1000),
        # Determinism identifiers — the backend persists these on the Schedule.
        "score_formula_version": SCORE_FORMULA_VERSION,
        "constraint_version": constraint_version,
    }

    return {
        "status": schema_status,
        "score": score,
        "metrics": metrics,
        "unplaced": unplaced,
        "slots": slots,
        "diagnostics": diagnostics,
    }


def _locked_slot_to_dict(locked: Mapping[str, Any] | Any) -> dict[str, Any]:
    """Convert a normalized HARD locked slot into an output slot dict."""
    team_id = str(_get(locked, "team_id", "teamId"))
    venue_id = str(_get(locked, "venue_id", "venueId"))
    day_of_week = int(_get(locked, "day_of_week", "dayOfWeek"))
    start_time = str(_get(locked, "start_time", "startTime"))[:5]  # normalize "HH:MM:SS" → "HH:MM"
    duration = int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
    coach_id = _get(locked, "coach_id", "coachId", default=None)
    pending_constraint_suggestion = _get(
        locked, "pending_constraint_suggestion", "pendingConstraintSuggestion", default=None
    )

    return {
        "id": _slot_id(team_id, venue_id, day_of_week, start_time),
        "teamId": team_id,
        "venueId": venue_id,
        "coachId": coach_id,
        "dayOfWeek": day_of_week,
        "startTime": start_time,
        "durationMinutes": duration,
        "lockLevel": "HARD",
        "pendingConstraintSuggestion": pending_constraint_suggestion,
    }


def _build_solver_slots(
    model_data: Mapping[str, Any] | Any,
    solver: cp_model.CpSolver,
    model: ScheduleCpModel,
    team_coach_map: Mapping[str, list[str]] | None = None,
) -> list[dict[str, Any]]:
    """Build output slots from CP-SAT boolean variables set to 1.

    Consecutive variables for the same (team, venue, day) are merged into a
    single slot. Duration per variable comes from ``model.slot_durations``
    (the training-slot's declared duration) with a fallback to SLOT_MINUTES
    for backward-compatible 15-min granularity.
    """
    from collections import defaultdict

    slot_durations: dict[Any, int] = getattr(model, "slot_durations", {})

    def _slot_dur(v_id: str, dow: int, start_min: int) -> int:
        return slot_durations.get((v_id, dow, _format_time(start_min)), SLOT_MINUTES)

    # Collect all active (team, venue, day, start_minutes) tuples
    active: dict[tuple[str, str, int], list[int]] = defaultdict(list)
    for slot_key, var in model.x.items():
        if solver.Value(var) != 1:
            continue
        team_id, venue_id, day_of_week, slot_start = slot_key
        start_minutes = _time_to_minutes(slot_start)
        active[(team_id, venue_id, day_of_week)].append(start_minutes)

    slots: list[dict[str, Any]] = []
    for (team_id, venue_id, day_of_week), starts in active.items():
        starts_sorted = sorted(starts)
        coach_id = _find_coach_for_team(model_data, team_id, team_coach_map)

        # Merge consecutive variables into contiguous blocks.
        # Two variables are contiguous when the next start equals the end of the
        # current block (i.e., no gap between them regardless of duration).
        if not starts_sorted:
            continue
        block_start = starts_sorted[0]
        block_end = starts_sorted[0] + _slot_dur(venue_id, day_of_week, starts_sorted[0])

        for s in starts_sorted[1:]:
            if s == block_end:
                # contiguous — extend block
                block_end = s + _slot_dur(venue_id, day_of_week, s)
            else:
                # gap — emit previous block and start a new one
                duration = block_end - block_start
                slots.append(
                    {
                        "id": _slot_id(team_id, venue_id, day_of_week, _format_time(block_start)),
                        "teamId": team_id,
                        "venueId": venue_id,
                        "coachId": coach_id,
                        "dayOfWeek": day_of_week,
                        "startTime": _format_time(block_start),
                        "durationMinutes": duration,
                        "lockLevel": "NONE",
                        "pendingConstraintSuggestion": None,
                    }
                )
                block_start = s
                block_end = s + _slot_dur(venue_id, day_of_week, s)

        # Emit the last block
        duration = block_end - block_start
        slots.append(
            {
                "id": _slot_id(team_id, venue_id, day_of_week, _format_time(block_start)),
                "teamId": team_id,
                "venueId": venue_id,
                "coachId": coach_id,
                "dayOfWeek": day_of_week,
                "startTime": _format_time(block_start),
                "durationMinutes": duration,
                "lockLevel": "NONE",
                "pendingConstraintSuggestion": None,
            }
        )
    return slots


def _slot_id(team_id: str, venue_id: str, day_of_week: int, start_time: str) -> str:
    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"clubscheduler-slot:{team_id}:{venue_id}:{day_of_week}:{start_time}"))


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
    diagnostics.extend(_diagnose_shared_trainings(model_data, solver_status, slots))
    diagnostics.extend(_diagnose_team_links(model_data, solver_status, slots))
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

    # P2-46 — mutualisation : N verrous d'un MÊME groupe déclaré sur une case sont UNE séance
    # commune, pas N séances. Une réservation de groupe s'éclate en N `Reservation` (une par
    # membre, même case) → N verrous HARD sur cette case ; les compter comme N occupants
    # distincts crierait faussement à la sur-capacité (« accueille 3 équipes… capacité 2 ») alors
    # que le gestionnaire a réservé UNE séance partagée. Même esprit que l'exemption passerelle
    # « une séance mutualisée déclarée n'est jamais une violation » (constraints.py) et la
    # tolérance coach D-14. L'exemption est CONDITIONNÉE à la déclaration : un membre est
    # rabattu sur l'identité de SON groupe, une équipe hors de tout groupe garde la sienne, si
    # bien que trois équipes sur une case SANS groupe déclaré restent une violation, et un
    # mélange (2 membres d'un groupe + 1 équipe étrangère) compte 2 — le groupe pour 1,
    # l'étrangère pour 1. Bloc `sharedTrainings` absent ⇒ map vide ⇒ occupants == équipes
    # distinctes ⇒ chemin byte-identique (goldens inchangés).
    team_to_group: dict[str, str] = {}
    for group_index, group in enumerate(_collection(model_data, "sharedTrainings", "shared_trainings")):
        group_key = f"__shared_group__{_get(group, 'id', default=group_index)}"
        for member in _get(group, "teamIds", "team_ids", default=[]) or []:
            team_to_group.setdefault(str(member), group_key)

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
        # P2-46 — chaque membre d'un groupe déclaré compte pour l'identité de son groupe : les
        # membres co-localisés se fondent en UN occupant. Sans groupe, `occupants == team_ids`.
        occupants = {team_to_group.get(team_id, team_id) for team_id in team_ids}
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
                    "message": (
                        f"Le gymnase {_label(venue_id, venue_names)} accueille {len(team_ids)} équipes "
                        f"en même temps le {when} alors que sa capacité est de {capacity} : "
                        f"{_named_list(team_ids, team_names)}."
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


def _diagnose_shared_trainings(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """P2-27 — mutualisation : nommer la déclaration quand elle n'est pas honorée.

    Deux régimes, un seul code de diagnostic ``shared_training_not_honored`` (severity ERROR) :

      * INFEASIBLE — cause CERTAINE : aucune fenêtre de gymnase n'a une capacité ≥ la taille du
        groupe, donc ces N équipes ne pourront JAMAIS partager une case. On nomme le groupe (le
        message ``diag-infeasible`` générique reste, celui-ci l'attribue). On reste PRUDENT : on
        ne prétend PAS que le groupe est la cause dès qu'il y a un autre motif — seulement quand
        la capacité l'exclut de façon prouvée.
      * OPTIMAL/FEASIBLE — défense en profondeur : la contrainte est dure, un solve abouti devrait
        l'honorer ; si le nombre RÉEL de séances communes du groupe (dans les slots finaux) diffère
        du K déclaré, on le signale (patron ``_diagnose_implicit_rule_violations``).

    Message français nommant les équipes réelles ; aucun identifiant interne.
    """
    groups = _collection(model_data, "sharedTrainings", "shared_trainings")
    if not groups:
        return []

    team_names = _team_name_map(model_data)
    diagnostics: list[dict[str, Any]] = []

    if solver_status == cp_model.INFEASIBLE:
        max_capacity = 0
        for venue in _collection(model_data, "venues"):
            for slot in _collection(venue, "training_slots", "trainingSlots"):
                raw = _get(slot, "capacity", default=1)
                try:
                    cap = int(raw) if raw is not None else 1
                except (TypeError, ValueError):
                    cap = 1
                max_capacity = max(max_capacity, cap)
        for index, group in enumerate(groups):
            members = [str(t) for t in (_get(group, "teamIds", "team_ids", default=[]) or [])]
            if len(members) < 2:
                continue
            if max_capacity < len(members):
                diagnostics.append(
                    {
                        "id": f"shared-training-infeasible-{_get(group, 'id', default=index)}",
                        "type": "shared_training_not_honored",
                        "severity": "ERROR",
                        "message": (
                            f"Les équipes déclarées ensemble ({_named_list(members, team_names)}) ne peuvent "
                            f"pas partager de séance : aucun créneau de gymnase n'accueille {len(members)} équipes "
                            "en même temps. Augmentez la capacité d'un créneau ou réduisez le groupe."
                        ),
                        "suggestions": [
                            "Augmentez la capacité d'un créneau de gymnase (gymnase divisible).",
                            "Retirez une équipe du groupe mutualisé.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )
        return diagnostics

    if solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        return []

    # Défense en profondeur : compter les séances communes RÉELLES dans les slots finaux.
    occupancy: dict[str, set[tuple[str, int, str]]] = defaultdict(set)
    for slot in slots:
        day = _slot_day(slot)
        if day is None:
            continue
        occupancy[str(slot["teamId"])].add((str(slot["venueId"]), day, str(slot["startTime"])[:5]))

    for index, group in enumerate(groups):
        members = [str(t) for t in (_get(group, "teamIds", "team_ids", default=[]) or [])]
        if len(members) < 2:
            continue
        common_sessions = int(_get(group, "commonSessions", "common_sessions", default=0) or 0)
        member_sets = [occupancy.get(member, set()) for member in members]
        common = set.intersection(*member_sets) if member_sets else set()
        if len(common) != common_sessions:
            diagnostics.append(
                {
                    "id": f"shared-training-not-honored-{_get(group, 'id', default=index)}",
                    "type": "shared_training_not_honored",
                    "severity": "ERROR",
                    "message": (
                        f"La mutualisation déclarée n'est pas respectée : les équipes "
                        f"{_named_list(members, team_names)} devraient partager {common_sessions} séance(s) "
                        f"commune(s) mais en partagent {len(common)}."
                    ),
                    "suggestions": ["Vérifiez les disponibilités communes de ces équipes ou ajustez la déclaration."],
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
    share_pairs = team_share_declared_pairs(_collection(model_data, "sharedTrainings", "shared_trainings"))
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


def _slot_day(slot: Mapping[str, Any]) -> int | None:
    raw = slot.get("dayOfWeek")
    if raw is None:
        return None
    try:
        return int(raw)
    except (TypeError, ValueError):
        return None


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_FR_DAYS = {
    1: "lundi",
    2: "mardi",
    3: "mercredi",
    4: "jeudi",
    5: "vendredi",
    6: "samedi",
    7: "dimanche",
}


def _day_label(day_of_week: Any) -> str:
    """Human day name (French). Falls back to 'jour N' for unknown values."""
    try:
        return _FR_DAYS.get(int(day_of_week), f"jour {int(day_of_week)}")
    except (TypeError, ValueError):
        return f"jour {day_of_week}"


def _time_range(start_time: str, duration_minutes: int | None) -> str:
    """Return 'HH:MM–HH:MM' from a start time and duration (start only if unknown)."""
    start = str(start_time)[:5]
    if not duration_minutes:
        return start
    try:
        end = _format_time(_time_to_minutes(start) + int(duration_minutes))
    except (TypeError, ValueError):
        return start
    return f"{start}–{end}"


def _team_name_map(model_data: Mapping[str, Any] | Any) -> dict[str, str]:
    names: dict[str, str] = {}
    for team in _collection(model_data, "teams"):
        team_id = _get(team, "id", "team_id", "teamId")
        if team_id is not None:
            names[str(team_id)] = str(_get(team, "name", "team_name", default=str(team_id)))
    return names


def _venue_name_map(model_data: Mapping[str, Any] | Any) -> dict[str, str]:
    names: dict[str, str] = {}
    for venue in _collection(model_data, "venues"):
        venue_id = _get(venue, "id", "venue_id", "venueId")
        if venue_id is not None:
            names[str(venue_id)] = str(_get(venue, "name", default=str(venue_id)))
    return names


def _coach_name_map(model_data: Mapping[str, Any] | Any) -> dict[str, str]:
    names: dict[str, str] = {}
    for coach in _collection(model_data, "coaches"):
        coach_id = _get(coach, "id", "coach_id", "coachId")
        if coach_id is None:
            continue
        full = _get(coach, "name", "coach_name", default=None)
        if full is None:
            first = _get(coach, "first_name", "firstName", default="")
            last = _get(coach, "last_name", "lastName", default="")
            full = f"{first} {last}".strip() or str(coach_id)
        names[str(coach_id)] = str(full)
    return names


def _label(entity_id: Any, names: Mapping[str, str]) -> str:
    """'Name' when known, else the raw id — never bare so the manager can act."""
    return names.get(str(entity_id), str(entity_id))


def _named_list(ids: list[str], names: Mapping[str, str]) -> str:
    return ", ".join(_label(i, names) for i in ids)


def _get(source: Mapping[str, Any] | Any, *names: str, default: Any = None) -> Any:
    """Read the first available field from a dict or object."""
    for name in names:
        if isinstance(source, Mapping):
            if name in source and source[name] is not None:
                return source[name]
            continue
        if hasattr(source, name):
            value = getattr(source, name)
            if value is not None:
                return value
    return default


def _team_ids(model_data: Mapping[str, Any] | Any) -> set[str]:
    """Return all team IDs found in the input data."""
    ids: set[str] = set()
    for team in _collection(model_data, "teams"):
        team_id = _get(team, "id", "team_id", "teamId")
        if team_id is not None:
            ids.add(str(team_id))
    return ids


def _slot_templates(model_data: Mapping[str, Any] | Any) -> list[Any]:
    """Return all slot templates from the input data."""
    return list(_collection(model_data, "slot_templates", "slotTemplates", "slots"))


def _collection(source: Mapping[str, Any] | Any, *names: str) -> list[Any]:
    """Extract a list-like collection from a dict or object by field name."""
    for name in names:
        values = _get(source, name, default=None)
        if values is None:
            continue
        if isinstance(values, (list, tuple)):
            return list(values)
        raise TypeError(f"{name} must be a list-like collection")
    return []


def _find_coach_for_team(
    model_data: Mapping[str, Any] | Any,
    team_id: str,
    team_coach_map: Mapping[str, list[str]] | None = None,
) -> str | None:
    """Qui encadre les séances GÉNÉRÉES de cette équipe ? (ENG-17)

    Le coach MAIN d'abord — c'est ce que le solveur a réellement modélisé
    (exclusivité coach, bonus de chaînage) et ce que `main.py` attache à chaque
    assignment. Avant ce correctif, seuls les `slotTemplates` étaient consultés :
    une équipe dont le coach vient d'une contrainte TEAM_COACH (le chemin
    DOMINANT — c'est ainsi que le backend sérialise les liens) sortait
    `coachId=None` sur toutes ses séances placées, et TOUS les diagnostics coach
    (double-réservation, surcharge, jour de repos) restaient muets pour elle.

    Le repli sur les `slotTemplates` reste : un payload sans contrainte
    TEAM_COACH (tests, legacy) garde son comportement d'avant.
    """
    if team_coach_map is not None:
        coaches = team_coach_map.get(team_id) or []
        if coaches:
            return str(coaches[0])

    for template in _slot_templates(model_data):
        template_team_id = _get(template, "team_id", "teamId")
        if template_team_id is not None and str(template_team_id) == team_id:
            coach_id = _get(template, "coach_id", "coachId")
            if coach_id is not None:
                return str(coach_id)
    return None


def _coach_threshold(model_data: Mapping[str, Any] | Any, coach_id: str) -> int:
    """Return the recommended maximum number of working DAYS for a coach (maxDaysOverride)."""
    for coach in _collection(model_data, "coaches"):
        cid = _get(coach, "id", "coach_id", "coachId")
        if cid is not None and str(cid) == coach_id:
            override = _get(coach, "max_days_override", "maxDaysOverride")
            if override is not None:
                return max(1, int(override))
    return 10**9
