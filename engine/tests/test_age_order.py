"""Tests for the age-ascending hard constraint.

Younger teams (lower ageMin) must train earlier than older teams (higher ageMin)
in the same venue on the same day. Teams with ageMin=None, HARD-locked teams,
and same-ageMin pairs are exempt.
"""

from __future__ import annotations

import json
import pathlib
from typing import Any

from ortools.sat.python import cp_model

from app.solver.constraints import add_level_1_hard_constraints
from app.solver.model import build_model
from app.solver.objective import add_level_2_objective
from app.solver.result_builder import build_result

FIXTURES_DIR = pathlib.Path(__file__).resolve().parent / "fixtures"


def _load_fixture(name: str) -> dict[str, Any]:
    with open(FIXTURES_DIR / name) as f:
        return json.load(f)


def _normalize_team_fields(data: dict[str, Any]) -> None:
    """Add snake_case aliases so constraints.py can read camelCase input."""
    for team in data.get("teams", []):
        if "sessionsPerWeek" in team and "sessions_per_week" not in team:
            team["sessions_per_week"] = team["sessionsPerWeek"]
        if "minSessionsOverride" in team and "min_sessions_override" not in team:
            team["min_sessions_override"] = team["minSessionsOverride"]
        if "forcedVenueId" in team and "forced_venue_id" not in team:
            team["forced_venue_id"] = team["forcedVenueId"]


def _run_pipeline(data: dict[str, Any], *, max_time_in_seconds: int = 5) -> dict[str, Any]:
    _normalize_team_fields(data)
    model = build_model(data)

    team_coaches: dict[str, str] = {}
    for tpl in data.get("slotTemplates", []):
        tid = tpl.get("teamId")
        cid = tpl.get("coachId")
        if tid and cid:
            team_coaches[tid] = cid

    assignments: list[dict[str, Any]] = []
    for slot_key, var in model.x.items():
        team_id, venue_id, day_of_week, slot_start = slot_key
        assignments.append(
            {
                "var": var,
                "team_id": team_id,
                "venue_id": venue_id,
                "slot_id": f"{day_of_week}:{slot_start}",
                "coach_id": team_coaches.get(team_id),
            }
        )

    add_level_1_hard_constraints(model, assignments, teams=data.get("teams", []))

    # Upper bound: no team gets more than sessions_per_week.
    assignments_by_team: dict[str, list[Any]] = {}
    for assignment in assignments:
        tid = assignment["team_id"]
        if tid:
            assignments_by_team.setdefault(tid, []).append(assignment["var"])

    for team in data.get("teams", []):
        tid = team.get("id")
        max_sessions = team.get("sessions_per_week") or team.get("sessionsPerWeek")
        if tid and max_sessions:
            team_vars = assignments_by_team.get(tid, [])
            if team_vars:
                model.Add(sum(team_vars) <= int(max_sessions))

    add_level_2_objective(model, assignments, teams=data.get("teams", []))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = max_time_in_seconds
    solver.parameters.random_seed = int(data.get("solverSeed", 42))
    status = solver.Solve(model)
    return build_result(data, solver, model, status=status)


def _start_times_by_team(result: dict[str, Any]) -> dict[str, str]:
    """Extract startTime per team from solver result."""
    start_times: dict[str, str] = {}
    for slot in result["slots"]:
        start_times[slot["teamId"]] = slot["startTime"]
    return start_times


class TestAgeOrder:
    def test_age_ascending_order(self) -> None:
        """U13M <= U15M <= U18M <= SeniorM by startTime in same venue+day."""
        data = _load_fixture("age_order_club.json")
        result = _run_pipeline(data)

        assert result["status"] == "completed", f"Solver status: {result['status']}"

        st = _start_times_by_team(result)
        assert "u13m" in st, "U13M not placed"
        assert "u15m" in st, "U15M not placed"
        assert "u18m" in st, "U18M not placed"
        assert "senior-m" in st, "SeniorM not placed"

        assert st["u13m"] <= st["u15m"], f"U13M ({st['u13m']}) should start at or before U15M ({st['u15m']})"
        assert st["u15m"] <= st["u18m"], f"U15M ({st['u15m']}) should start at or before U18M ({st['u18m']})"
        assert st["u18m"] <= st["senior-m"], f"U18M ({st['u18m']}) should start at or before SeniorM ({st['senior-m']})"

    def test_no_reverse_order(self) -> None:
        """A younger team must never be placed after an older team (same venue+day)."""
        data = _load_fixture("age_order_club.json")
        result = _run_pipeline(data)

        assert result["status"] == "completed", f"Solver status: {result['status']}"

        st = _start_times_by_team(result)
        expected_order = ["u13m", "u15m", "u18m", "senior-m"]

        for i in range(len(expected_order)):
            for j in range(i + 1, len(expected_order)):
                younger = expected_order[i]
                older = expected_order[j]
                if younger in st and older in st:
                    assert st[younger] <= st[older], (
                        f"Reverse order: {younger} at {st[younger]} is after {older} at {st[older]}"
                    )

    def test_hard_lock_exempt(self) -> None:
        """HARD-locked teams are exempt from age-ascending constraint.

        U13M is HARD-locked at 22:00 (latest slot — violates age order).
        The solver must preserve the HARD lock and still age-order the rest.
        A 5th slot is added so the remaining 3 teams have enough capacity.
        U13M's sessionsPerWeek is set to 0 so min_sessions doesn't require
        an additional solver-assigned session for the HARD-locked team.
        """
        data = _load_fixture("age_order_club.json")
        data["venues"][0]["trainingSlots"].append(
            {"dayOfWeek": 1, "startTime": "23:30", "durationMinutes": 90, "capacity": 1}
        )
        data["teams"][0]["sessionsPerWeek"] = 0
        data["slotTemplates"] = [
            {
                "id": "tpl-u13-hard",
                "teamId": "u13m",
                "venueId": "test-gym",
                "coachId": None,
                "dayOfWeek": 1,
                "startTime": "22:00",
                "durationMinutes": 90,
                "lockLevel": "HARD",
            }
        ]
        result = _run_pipeline(data)

        assert result["status"] == "completed", f"Solver status: {result['status']}"

        hard_slots = [s for s in result["slots"] if s.get("lockLevel") == "HARD"]
        solver_slots = [s for s in result["slots"] if s.get("lockLevel") != "HARD"]

        u13_hard = [s for s in hard_slots if s["teamId"] == "u13m"]
        assert len(u13_hard) == 1, f"Expected 1 HARD slot for U13M, got {len(u13_hard)}"
        assert u13_hard[0]["startTime"] == "22:00", (
            f"U13M should be HARD-locked at 22:00, got {u13_hard[0]['startTime']}"
        )

        st: dict[str, str] = {s["teamId"]: s["startTime"] for s in solver_slots}
        if "u15m" in st and "u18m" in st:
            assert st["u15m"] <= st["u18m"], f"U15M ({st['u15m']}) should start at or before U18M ({st['u18m']})"
        if "u18m" in st and "senior-m" in st:
            assert st["u18m"] <= st["senior-m"], (
                f"U18M ({st['u18m']}) should start at or before SeniorM ({st['senior-m']})"
            )

    def test_same_age_min_not_constrained(self) -> None:
        """Teams with same ageMin are not constrained relative to each other.

        Replace U15M with U13F (same ageMin=12 as U13M). The solver must place
        both without failing, and both must still precede U18M and SeniorM.
        Their relative order (U13M vs U13F) is unconstrained.
        """
        data = _load_fixture("age_order_club.json")
        data["teams"][1] = {
            "id": "u13f",
            "sportCategoryId": "u13",
            "ageMin": 12,
            "ageMax": 13,
            "priorityTierId": 3,
            "name": "U13 Women",
            "sessionsPerWeek": 1,
        }
        result = _run_pipeline(data)

        assert result["status"] == "completed", f"Solver status: {result['status']}"

        st = _start_times_by_team(result)
        assert "u13m" in st, "U13M not placed"
        assert "u13f" in st, "U13F not placed"
        assert "u18m" in st, "U18M not placed"
        assert "senior-m" in st, "SeniorM not placed"

        # Both same-ageMin teams must precede older teams
        assert st["u13m"] <= st["u18m"], f"U13M ({st['u13m']}) should start at or before U18M ({st['u18m']})"
        assert st["u13f"] <= st["u18m"], f"U13F ({st['u13f']}) should start at or before U18M ({st['u18m']})"
        assert st["u13m"] <= st["senior-m"], f"U13M ({st['u13m']}) should start at or before SeniorM ({st['senior-m']})"
        assert st["u13f"] <= st["senior-m"], f"U13F ({st['u13f']}) should start at or before SeniorM ({st['senior-m']})"
