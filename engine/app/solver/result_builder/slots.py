"""Result builder — construction des slots de sortie (verrous + slots solveur) (paquet ENG-39).

Extrait tel quel de l'ancien monolithe ``result_builder.py`` (déplacement pur, ENG-39). Dépend de
``helpers`` (lecteurs de champs) et du ``model`` du paquet solveur ; ne dépend PAS de
``diagnostics`` ni de l'agrégateur.
"""

from __future__ import annotations

import uuid
from collections.abc import Mapping
from typing import Any

from ortools.sat.python import cp_model

from ..constraints.common import _hold_key, _lock_hold
from ..model import (
    DEFAULT_SESSION_MINUTES,
    SLOT_MINUTES,
    ScheduleCpModel,
    _format_time,
    _time_to_minutes,
)
from .helpers import _find_coach_for_team, _get


def _first_holder(holders: Mapping[str, Any], model: Any, solver: Any) -> str | None:
    """Le premier coach, dans l'ordre de l'équipe, qui tient la séance. Les tenues arrêtées par
    ``main._settle_holds`` priment sur le solve : le coach stocké n'est pas un choix du solveur."""
    settled = getattr(model, "settled_holds", None) or {}

    def _held(literal: Any) -> bool:
        return bool((settled[literal.Index()] if literal.Index() in settled else solver.Value(literal)) == 1)

    return next((coach_id for coach_id, literal in holders.items() if _held(literal)), None)


def _lock_holder(locked: Mapping[str, Any], model: Any, solver: Any, solved: bool) -> str | None:
    """Le coach qui tient un verrou. Sans solution, le premier de l'équipe ; sans carte, ``None``."""
    team_id = str(_get(locked, "team_id", "teamId"))
    coaches = getattr(model, "team_coach_map", None) or {}
    start = _time_to_minutes(str(_get(locked, "start_time", "startTime")))
    end = start + int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
    day = int(_get(locked, "day_of_week", "dayOfWeek"))
    hold = _lock_hold(model, team_id, str(_get(locked, "venue_id", "venueId")), day, start, end)
    if hold is not None:
        holder = _first_holder(hold["vars"], model, solver) if solved else None
        return holder or str(hold["named"])
    team_coaches = coaches.get(team_id) or []
    return str(team_coaches[0]) if team_coaches else None


def _session_holder(team_id: str, venue_id: str, day: int, start: str, model: Any, solver: Any) -> str | None:
    """Le coach qui tient une séance posée : le sien, ou, en séance de bloc, celui d'un autre membre."""
    holds = getattr(model, "hold_vars", None) or {}
    var = model.x[(team_id, venue_id, day, start)]
    holder = _first_holder(holds.get(_hold_key(var)) or {}, model, solver)
    if holder is not None:
        return holder
    blocks = getattr(model, "shared_block_case_bvars", {}).get((venue_id, f"{day}:{start}"), ())
    for members, block_var in blocks:
        if team_id not in members or solver.Value(block_var) != 1:
            continue
        for member in sorted(members):
            partner_var = model.x.get((member, venue_id, day, start))
            partner_holds = holds.get(_hold_key(partner_var)) or {} if partner_var is not None else {}
            holder = _first_holder(partner_holds, model, solver)
            if holder is not None:
                return holder
        # Un membre VERROUILLÉ de la séance commune la tient.
        for locked in getattr(model, "locked_slots", ()) or ():
            if (
                str(locked["team_id"]) in members
                and str(locked["venue_id"]) == venue_id
                and int(locked["day_of_week"]) == day
                and str(locked["start_time"])[:5] == start[:5]
            ):
                holder = _lock_holder(locked, model, solver, True)
                if holder is not None:
                    return holder
    return None


def _locked_slot_to_dict(
    locked: Mapping[str, Any] | Any, model: Any = None, solver: Any = None, solved: bool = False
) -> dict[str, Any]:
    """Convert a normalized HARD locked slot into an output slot dict."""
    team_id = str(_get(locked, "team_id", "teamId"))
    venue_id = str(_get(locked, "venue_id", "venueId"))
    day_of_week = int(_get(locked, "day_of_week", "dayOfWeek"))
    start_time = str(_get(locked, "start_time", "startTime"))[:5]  # normalize "HH:MM:SS" → "HH:MM"
    duration = int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
    coach_id = (_lock_holder(locked, model, solver, solved) if model is not None else None) or _get(
        locked, "coach_id", "coachId", default=None
    )
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
        default_coach = _find_coach_for_team(model_data, team_id, team_coach_map)

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
                        "coachId": _session_holder(
                            team_id, venue_id, day_of_week, _format_time(block_start), model, solver
                        )
                        or default_coach,
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
                "coachId": _session_holder(team_id, venue_id, day_of_week, _format_time(block_start), model, solver)
                or default_coach,
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
