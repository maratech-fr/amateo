"""Soft-objective features: match-day rest and PREFERRED TIME (E-feat, V5)."""

from __future__ import annotations

from typing import Any

from app.solver.objective import SCORE_FORMULA_VERSION
from tests.support import make_payload, make_venue, solve_payload, team_constraint


def _team(team_id: str, *, sessions: int = 1, match_day: int | None = None) -> dict[str, Any]:
    team: dict[str, Any] = {
        "id": team_id,
        "sportCategoryId": "cat",
        "priorityTierId": 3,
        "name": team_id,
        "sessionsPerWeek": sessions,
        "isActive": True,
    }
    if match_day is not None:
        team["matchDay"] = match_day
    return team


def _days(result: dict[str, Any], team_id: str) -> set[int]:
    return {int(s["dayOfWeek"]) for s in result["slots"] if s["teamId"] == team_id}


def _starts(result: dict[str, Any], team_id: str) -> set[str]:
    return {str(s["startTime"])[:5] for s in result["slots"] if s["teamId"] == team_id}


def test_score_formula_version_is_v13() -> None:
    assert SCORE_FORMULA_VERSION == "T24_LEVEL_2_FIXED_WEIGHTS_V13"
    result = solve_payload(
        make_payload(
            teams=[_team("t")],
            venues=[make_venue("v", [(2, "18:00")])],
        )
    )
    assert result["metrics"]["scoreFormulaVersion"] == "T24_LEVEL_2_FIXED_WEIGHTS_V13"


def test_match_day_rest_day_left_free_when_possible() -> None:
    # Sunday match (7) → Monday (1) is the rest day. With an alternative slot on
    # Wednesday (3), the team should train Wednesday, leaving Monday free.
    payload = make_payload(
        teams=[_team("t", sessions=1, match_day=7)],
        venues=[make_venue("v", [(1, "18:00"), (3, "18:00")])],
    )
    result = solve_payload(payload)
    assert _days(result, "t"), "team must be placed"
    assert 1 not in _days(result, "t"), "Monday (day after Sunday match) should stay free"


def test_match_day_rest_not_enforced_when_no_alternative() -> None:
    # Only slot is on the rest day → the soft bonus must NOT block placement.
    payload = make_payload(
        teams=[_team("t", sessions=1, match_day=7)],
        venues=[make_venue("v", [(1, "18:00")])],
    )
    result = solve_payload(payload)
    assert result["status"] == "completed"
    assert _days(result, "t") == {1}, "team still placed on the only slot despite rest bonus"


def test_preferred_time_window_chosen_over_equal_slot() -> None:
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[make_venue("v", [(2, "17:00"), (2, "20:00")])],
        constraints=[
            team_constraint(
                constraint_id="c",
                team_id="t",
                family="TIME",
                rule_type="PREFERRED",
                config={"minStartTime": "19:00"},
            )
        ],
    )
    result = solve_payload(payload)
    starts = _starts(result, "t")
    assert starts, "team must be placed"
    assert all(s >= "19:00" for s in starts), f"preferred time window not honoured: {starts}"


def test_malformed_preferred_time_does_not_crash() -> None:
    # Regression (audit review F1): a malformed/empty time bound on a PREFERRED
    # TIME window must be ignored, not raise a 500 for the whole generation.
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[make_venue("v", [(2, "18:00")])],
        constraints=[
            team_constraint(
                constraint_id="c",
                team_id="t",
                family="TIME",
                rule_type="PREFERRED",
                config={"minStartTime": ""},
            )
        ],
    )
    result = solve_payload(payload)
    assert result["status"] == "completed"
    assert _days(result, "t"), "team still placed despite malformed preferred bound"


def test_preferred_time_never_beats_hard_constraint() -> None:
    # HARD maxStart 18:00 contradicts PREFERRED minStart 19:00 → HARD wins.
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[make_venue("v", [(2, "17:00"), (2, "20:00")])],
        constraints=[
            team_constraint(
                constraint_id="hard",
                team_id="t",
                family="TIME",
                rule_type="HARD",
                config={"maxStartTime": "18:00"},
            ),
            team_constraint(
                constraint_id="pref",
                team_id="t",
                family="TIME",
                rule_type="PREFERRED",
                config={"minStartTime": "19:00"},
            ),
        ],
    )
    result = solve_payload(payload)
    starts = _starts(result, "t")
    assert all(s <= "18:00" for s in starts), f"HARD must win over PREFERRED: {starts}"
