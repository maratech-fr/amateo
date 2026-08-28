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

from ..model import (
    DEFAULT_SESSION_MINUTES,
    SLOT_MINUTES,
    ScheduleCpModel,
    _format_time,
    _time_to_minutes,
)
from .helpers import _find_coach_for_team, _get


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
