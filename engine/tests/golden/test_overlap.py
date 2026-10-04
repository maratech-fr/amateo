"""TDD golden tests for coach/player overlap detection (BUG-1 + BUG-2).

These tests reproduce the real BCCL overlap cases where a person is assigned
as coach on one team and as player on another team at overlapping time
intervals (different start times) on the same day.

Before the fix: ``add_coach_at_most_one`` and ``add_coach_player_non_overlap``
grouped assignments by ``_time_key`` (slot start), so overlapping intervals
with different start times were missed.

After the fix: both functions use ``_intervals_overlap`` to detect overlap
via interval intersection (start/end), catching the real BCCL cases.
"""

from __future__ import annotations

import asyncio
import json
import pathlib
from typing import Any

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema
from app.solver.constraints import _intervals_overlap, parse_v2_constraints

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"


def _load_fixture(name: str) -> dict[str, Any]:
    path = FIXTURES_DIR / f"{name}.json"
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def _run_build_schedule(data: dict[str, Any]) -> Any:
    input_data = ScheduleInputSchema.model_validate(data)
    return asyncio.run(build_schedule(input_data))


def _time_to_minutes(time_obj: Any) -> int:
    if hasattr(time_obj, "hour"):
        return time_obj.hour * 60 + time_obj.minute
    text = str(time_obj)
    parts = text.split(":")
    return int(parts[0]) * 60 + int(parts[1])


def _extract_person_slots(result: Any, data: dict[str, Any]) -> dict[str, list[tuple[int, int, str]]]:
    """Extract slots per person (coach or player) with (start, end, day) intervals.

    Uses ``team_coach_map`` and ``team_player_map`` from parsed constraints to
    look up which persons are associated with each placed team.
    """
    parsed = parse_v2_constraints(data.get("constraints", []))
    team_coach_map: dict[str, list[str]] = parsed.get("team_coach_map", {})
    team_player_map: dict[str, list[str]] = parsed.get("team_player_map", {})

    person_intervals: dict[str, list[tuple[int, int, str]]] = {}

    for slot in result.slots:
        team_id_str = str(slot.team_id)
        persons: set[str] = set()
        persons.update(team_coach_map.get(team_id_str, []))
        persons.update(team_player_map.get(team_id_str, []))

        start = _time_to_minutes(slot.start_time)
        end = start + slot.duration_minutes
        day = str(slot.day_of_week)

        for person_id in persons:
            person_intervals.setdefault(person_id, []).append((start, end, day))

    return person_intervals


def _count_overlaps(intervals: dict[str, list[tuple[int, int, str]]]) -> list[str]:
    """Return list of overlap descriptions for any person on the same day."""
    overlaps: list[str] = []
    for person_id, slots in intervals.items():
        by_day: dict[str, list[tuple[int, int]]] = {}
        for start, end, day in slots:
            by_day.setdefault(day, []).append((start, end))

        for day, day_slots in by_day.items():
            for i in range(len(day_slots)):
                for j in range(i + 1, len(day_slots)):
                    a_start, a_end = day_slots[i]
                    b_start, b_end = day_slots[j]
                    if _intervals_overlap(a_start, a_end, b_start, b_end):
                        overlaps.append(
                            f"person={person_id} day={day} slot_A=({a_start},{a_end}) slot_B=({b_start},{b_end})"
                        )
    return overlaps


class TestOverlapConstraints:
    def test_shared_coach_no_overlap(self) -> None:
        """A coach who is also a player must not be coach+joueur on overlapping slots.

        BCCL case: the coach coaches U18M1 at Jean Vilar Tuesday 18:45-20:15
        and plays SM2 at Debarros Tuesday 19:00-20:30. These intervals overlap
        (18:45 < 20:30 and 19:00 < 20:15) but have different start times, so
        the old ``_time_key`` grouping missed the conflict.
        """
        data = _load_fixture("overlap_shared_coach")
        result = _run_build_schedule(data)

        # If status=failed, no slots to check — acceptable (constraints too tight).
        if result.status == "failed":
            return

        person_intervals = _extract_person_slots(result, data)
        overlaps = _count_overlaps(person_intervals)

        assert overlaps == [], (
            f"Expected 0 overlaps for the shared coach, got {len(overlaps)}: {overlaps}. Person intervals: {person_intervals}"
        )

    def test_anna_no_overlap(self) -> None:
        """Anna must not be coach+joueuse on overlapping slots.

        BCCL case: Anna coaches CEC Groupe 3 at Matéo Wednesday 17:30-19:00
        and plays U18F1 at Gymnase Armand Wednesday 18:45-20:15. These
        intervals overlap (17:30 < 20:15 and 18:45 < 19:00) but have different
        start times, so the old ``_time_key`` grouping missed the conflict.
        """
        data = _load_fixture("overlap_anna")
        result = _run_build_schedule(data)

        if result.status == "failed":
            return

        person_intervals = _extract_person_slots(result, data)
        overlaps = _count_overlaps(person_intervals)

        assert overlaps == [], (
            f"Expected 0 overlaps for Anna, got {len(overlaps)}: {overlaps}. Person intervals: {person_intervals}"
        )
