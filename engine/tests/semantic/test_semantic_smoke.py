"""Semantic smoke tests — a constraint entered in the UI is honoured by the solver.

These run through the REAL production pipeline (``solve_payload``) with payloads
in the exact backend contract shape. This is the suite that would have caught
ENG-01 (coach availability silently ignored): a green test means the placed
schedule actually respects the constraint, not merely that the solver returned
COMPLETED.
"""

from __future__ import annotations

from typing import Any

from tests.support import (
    coach_availability,
    make_payload,
    make_venue,
    solve_payload,
    team_coach,
    team_constraint,
)


def _team(team_id: str, sessions: int = 1) -> dict[str, Any]:
    return {
        "id": team_id,
        "sportCategoryId": "cat",
        "priorityTierId": 3,
        "name": team_id,
        "sessionsPerWeek": sessions,
        "isActive": True,
    }


def _days_of(result: dict[str, Any], team_id: str) -> set[int]:
    return {int(s["dayOfWeek"]) for s in result["slots"] if s["teamId"] == team_id}


def _venue_day_starts(result: dict[str, Any], venue_id: str, day: int) -> list[str]:
    return [s["teamId"] for s in result["slots"] if s["venueId"] == venue_id and int(s["dayOfWeek"]) == day]


def _starts(result: dict[str, Any], team_id: str) -> set[str]:
    # startTime may be a datetime.time or a "HH:MM" string depending on the path.
    return {str(s["startTime"])[:5] for s in result["slots"] if s["teamId"] == team_id}


# --- TIME (HARD) --------------------------------------------------------------


def test_hard_time_min_start_respected() -> None:
    venue = make_venue("v", [(1, "17:00"), (1, "20:00")])
    payload = make_payload(
        teams=[_team("t")],
        venues=[venue],
        constraints=[
            team_constraint(
                constraint_id="c",
                team_id="t",
                family="TIME",
                rule_type="HARD",
                config={"minStartTime": "19:00"},
            )
        ],
    )
    result = solve_payload(payload)
    starts = _starts(result, "t")
    assert starts, "team should be placed"
    assert all(s >= "19:00" for s in starts), f"minStartTime violated: {starts}"


def test_hard_time_max_start_respected() -> None:
    venue = make_venue("v", [(1, "17:00"), (1, "20:00")])
    payload = make_payload(
        teams=[_team("t")],
        venues=[venue],
        constraints=[
            team_constraint(
                constraint_id="c",
                team_id="t",
                family="TIME",
                rule_type="HARD",
                config={"maxStartTime": "18:00"},
            )
        ],
    )
    result = solve_payload(payload)
    starts = _starts(result, "t")
    assert starts
    assert all(s <= "18:00" for s in starts), f"maxStartTime violated: {starts}"


# --- DAY (HARD) ---------------------------------------------------------------


def test_hard_day_forbidden_respected() -> None:
    venue = make_venue("v", [(2, "18:00"), (4, "18:00")])
    payload = make_payload(
        teams=[_team("t")],
        venues=[venue],
        constraints=[
            team_constraint(
                constraint_id="c",
                team_id="t",
                family="DAY",
                rule_type="HARD",
                config={"forbiddenDays": [2]},
            )
        ],
    )
    result = solve_payload(payload)
    assert 2 not in _days_of(result, "t"), "forbidden day 2 must have no session"


def test_hard_day_forced_has_session_on_day() -> None:
    venue = make_venue("v", [(2, "18:00"), (4, "18:00")])
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[venue],
        constraints=[
            team_constraint(
                constraint_id="c",
                team_id="t",
                family="DAY",
                rule_type="HARD",
                config={"forcedDays": [4]},
            )
        ],
    )
    result = solve_payload(payload)
    assert 4 in _days_of(result, "t"), "forced day 4 must carry a session"


def test_hard_day_allowed_days_excludes_others() -> None:
    venue = make_venue("v", [(1, "18:00"), (2, "18:00"), (3, "18:00")])
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[venue],
        constraints=[
            team_constraint(
                constraint_id="c",
                team_id="t",
                family="DAY",
                rule_type="HARD",
                config={"allowedDays": [2]},
            )
        ],
    )
    result = solve_payload(payload)
    assert _days_of(result, "t") <= {2}, "only allowed day 2 may carry a session"


# --- COACH availability (ENG-01) ---------------------------------------------


def test_coach_unavailable_day_no_session() -> None:
    # THE test that would have caught ENG-01: a coach entered as unavailable on a
    # day must leave that day free for their team.
    venue = make_venue("v", [(2, "18:00"), (4, "18:00")])
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[venue],
        constraints=[
            team_coach("tc", team_id="t", coach_id="coach-1"),
            coach_availability("ca", "coach-1", unavailable_days=[2]),
        ],
    )
    result = solve_payload(payload)
    assert 2 not in _days_of(result, "t"), "coach unavailable on day 2 → no session that day"
    assert _days_of(result, "t"), "team should still be placed on an available day"


def test_coach_unavailable_day_enforced_for_second_coach() -> None:
    # Regression (audit review): a team can have two required coaches; the
    # assignment carries only the first. The SECOND coach's unavailability must
    # still block the day, else ENG-01 survives for co-coached teams.
    venue = make_venue("v", [(2, "18:00"), (4, "18:00")])
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[venue],
        constraints=[
            team_coach("tc1", team_id="t", coach_id="coach-A"),
            team_coach("tc2", team_id="t", coach_id="coach-B"),
            coach_availability("ca", "coach-B", unavailable_days=[2]),
        ],
    )
    result = solve_payload(payload)
    assert 2 not in _days_of(result, "t"), "co-head-coach B unavailable day 2 → no session"


def test_empty_available_days_is_no_restriction() -> None:
    # Regression (audit review): an empty availableDays whitelist must NOT block
    # every day (which would zero the team) — it is treated as unconfigured.
    venue = make_venue("v", [(2, "18:00"), (4, "18:00")])
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[venue],
        constraints=[
            team_coach("tc", team_id="t", coach_id="coach-1"),
            coach_availability("ca", "coach-1", available_days=[]),
        ],
    )
    result = solve_payload(payload)
    assert _days_of(result, "t"), "empty availableDays must not zero the team"


def test_string_day_names_do_not_crash() -> None:
    # Regression (audit review): a legacy string day value must be skipped, not
    # crash the whole solve.
    venue = make_venue("v", [(2, "18:00"), (4, "18:00")])
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[venue],
        constraints=[
            team_coach("tc", team_id="t", coach_id="coach-1"),
            coach_availability("ca", "coach-1", unavailable_days=["monday"]),
        ],
    )
    result = solve_payload(payload)
    assert result["status"] == "completed"
    assert _days_of(result, "t"), "unparseable day is ignored, team still placed"


def test_coach_available_days_complement() -> None:
    venue = make_venue("v", [(1, "18:00"), (2, "18:00"), (3, "18:00")])
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[venue],
        constraints=[
            team_coach("tc", team_id="t", coach_id="coach-1"),
            coach_availability("ca", "coach-1", available_days=[3]),
        ],
    )
    result = solve_payload(payload)
    assert _days_of(result, "t") <= {3}, "coach only available day 3 → sessions only on day 3"


# --- FACILITY ----------------------------------------------------------------


def test_facility_forbidden_venue_respected() -> None:
    payload = make_payload(
        teams=[_team("t")],
        venues=[make_venue("bad", [(2, "18:00")]), make_venue("good", [(3, "18:00")])],
        constraints=[
            team_constraint(
                constraint_id="c",
                team_id="t",
                family="FACILITY",
                rule_type="HARD",
                config={"forbiddenVenueId": "bad"},
            )
        ],
    )
    result = solve_payload(payload)
    venues_used = {s["venueId"] for s in result["slots"] if s["teamId"] == "t"}
    assert "bad" not in venues_used, "forbidden venue must not be used"


def test_facility_forced_venue_respected() -> None:
    payload = make_payload(
        teams=[_team("t")],
        venues=[make_venue("target", [(2, "18:00")]), make_venue("other", [(2, "18:00")])],
        constraints=[
            team_constraint(
                constraint_id="c",
                team_id="t",
                family="FACILITY",
                rule_type="HARD",
                config={"preferredVenueId": "target"},
            )
        ],
    )
    result = solve_payload(payload)
    venues_used = {s["venueId"] for s in result["slots"] if s["teamId"] == "t"}
    assert venues_used <= {"target"}, "HARD preferred venue forces placement there"
