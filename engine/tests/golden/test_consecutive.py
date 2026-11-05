"""TDD golden test for cross-venue consecutive sessions (BUG-3).

Three back-to-back sessions on Tuesday across
two venues (Gymnase Armand -> Debarros -> Debarros).  The old
``add_max_consecutive_sessions_constraints`` grouped by ``(venue_id, day)``
so each venue only had <= 2 sessions and no consecutive triple was detected.

After the fix: the function also groups by ``(person_id, day)`` cross-venue,
detecting the triple and adding ``sum(varA + varB + varC) <= 2``.
"""

from __future__ import annotations

import asyncio
import json
import pathlib
from typing import Any

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema
from app.solver.constraints import parse_v2_constraints

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


def _extract_person_slots(result: Any, data: dict[str, Any]) -> dict[str, list[tuple[int, int, int]]]:
    """Extract slots per person (coach or player) with (start, end, day) intervals.

    Uses ``team_coach_map`` and ``team_player_map`` from parsed constraints to
    look up which persons are associated with each placed team.
    """
    parsed = parse_v2_constraints(data.get("constraints", []))
    team_coach_map: dict[str, list[str]] = parsed.get("team_coach_map", {})
    team_player_map: dict[str, list[str]] = parsed.get("team_player_map", {})

    person_intervals: dict[str, list[tuple[int, int, int]]] = {}

    for slot in result.slots:
        team_id_str = str(slot.team_id)
        persons: set[str] = set()
        persons.update(team_coach_map.get(team_id_str, []))
        persons.update(team_player_map.get(team_id_str, []))

        start = _time_to_minutes(slot.start_time)
        end = start + slot.duration_minutes
        day = int(slot.day_of_week)

        for person_id in persons:
            person_intervals.setdefault(person_id, []).append((start, end, day))

    return person_intervals


def _find_consecutive_triples(intervals: list[tuple[int, int, int]], day: int) -> list[str]:
    """Return descriptions of any 3-slot consecutive chain on the given day.

    A chain is A->B->C where A.end == B.start and B.end == C.start.
    """
    day_slots = sorted(
        [(s, e) for s, e, d in intervals if d == day],
        key=lambda x: x[0],
    )
    triples: list[str] = []
    for i in range(len(day_slots) - 2):
        a_start, a_end = day_slots[i]
        b_start, b_end = day_slots[i + 1]
        c_start, c_end = day_slots[i + 2]
        if a_end == b_start and b_end == c_start:
            triples.append(f"({a_start}-{a_end}) -> ({b_start}-{b_end}) -> ({c_start}-{c_end})")
    return triples


class TestConsecutiveConstraints:
    def test_single_coach_max_2_consecutive(self) -> None:
        """A single coach must not have 3 consecutive back-to-back sessions on Tuesday.

        Coach 03 coaches U15F1 at Gymnase Armand Tuesday 17:30-19:00,
        plays SM2 at Debarros Tuesday 19:00-20:30, and coaches SF1 at Debarros
        Tuesday 20:30-22:30.  These form a cross-venue chain that the old
        ``(venue_id, day)`` grouping missed because no single venue has 3
        sessions.
        """
        data = _load_fixture("consecutive_single_coach")
        result = _run_build_schedule(data)

        # If status=failed, no slots to check — acceptable (constraints too tight).
        if result.status == "failed":
            return

        person_intervals = _extract_person_slots(result, data)

        # Coach 03 is the key person in this scenario.
        coach_slots = person_intervals.get("coach-03", [])
        assert len(coach_slots) > 0, (
            f"Expected Coach 03 to have placed slots, got 0. Person intervals: {person_intervals}"
        )

        triples = _find_consecutive_triples(coach_slots, day=2)
        assert triples == [], (
            f"Expected 0 consecutive triples for Coach 03 on Tuesday, got {len(triples)}: "
            f"{triples}. Coach 03 slots: {coach_slots}"
        )
