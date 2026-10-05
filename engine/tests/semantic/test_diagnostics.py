"""Diagnostic-quality tests (ENG-09): no fake conflicts, one severity scale.

The manager's first contact with a successful plan must not be a wall of red
false errors. These assert the diagnostics are truthful and use the enum the
backend/frontend expect (ERROR|WARNING|INFO|SUCCESS).
"""

from __future__ import annotations

import json
import pathlib
from typing import Any

from tests.support import make_payload, make_venue, solve_payload

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"
ALLOWED_SEVERITIES = {"ERROR", "WARNING", "INFO", "SUCCESS"}


def _team(team_id: str, sessions: int = 1) -> dict[str, Any]:
    return {
        "id": team_id,
        "sportCategoryId": "cat",
        "priorityTierId": 3,
        "name": team_id,
        "sessionsPerWeek": sessions,
    }


def test_duplicate_hard_templates_deduplicated() -> None:
    # Two identical HARD templates for the same team/venue/day/start must yield
    # ONE slot and NO over-capacity conflict (the "SM3, SM3" false positive).
    venue = make_venue("v", [(2, "18:00")], capacity=1)
    tpl = {
        "id": "tpl",
        "teamId": "t",
        "venueId": "v",
        "coachId": None,
        "dayOfWeek": 2,
        "startTime": "18:00",
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }
    payload = make_payload(
        teams=[_team("t")],
        venues=[venue],
        slot_templates=[dict(tpl, id="tpl-1"), dict(tpl, id="tpl-2")],
    )
    result = solve_payload(payload)

    v_slots = [s for s in result["slots"] if s["teamId"] == "t" and int(s["dayOfWeek"]) == 2]
    assert len(v_slots) == 1, "duplicate HARD templates must collapse to one slot"
    conflicts = [d for d in result["diagnostics"] if d["type"] == "conflict"]
    assert conflicts == [], f"no fake conflict expected, got {conflicts}"


def test_conflict_message_lists_each_team_once() -> None:
    # If a real over-capacity conflict lists teams, each team appears once.
    venue = make_venue("v", [(2, "18:00")], capacity=1)
    tpl_a = {
        "id": "a",
        "teamId": "a",
        "venueId": "v",
        "coachId": None,
        "dayOfWeek": 2,
        "startTime": "18:00",
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }
    tpl_b = dict(tpl_a, id="b", teamId="b")
    payload = make_payload(
        teams=[_team("a"), _team("b")],
        venues=[venue],
        slot_templates=[tpl_a, dict(tpl_a, id="a2"), tpl_b],  # team a duplicated
    )
    result = solve_payload(payload)
    conflicts = [d for d in result["diagnostics"] if d["type"] == "conflict"]
    assert conflicts, "an over-capacity conflict is expected here"
    for c in conflicts:
        # message ends with "... : TeamA, TeamB." — team names default to ids.
        listed = c["message"].rsplit(" : ", 1)[1].rstrip(".").split(", ")
        assert len(listed) == len(set(listed)), f"team listed twice: {listed}"


def test_real_coach_double_booking_not_hidden_by_dedup() -> None:
    # Regression (audit review F1): the coach check must NOT dedupe by team id
    # alone. The SAME team locked into TWO gyms at the same time with ONE coach
    # is a real double-booking (the coach cannot be in two places) and must be
    # reported, even though both slots carry the same team id.
    tpl = {
        "teamId": "a",
        "coachId": "C",
        "dayOfWeek": 2,
        "startTime": "18:00",
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }
    payload = make_payload(
        teams=[_team("a")],
        venues=[make_venue("v1", [(2, "18:00")]), make_venue("v2", [(2, "18:00")])],
        slot_templates=[dict(tpl, id="s1", venueId="v1"), dict(tpl, id="s2", venueId="v2")],
    )
    result = solve_payload(payload)
    coach_conflicts = [d for d in result["diagnostics"] if d["type"] == "conflict" and d.get("coachId") == "C"]
    assert coach_conflicts, "coach in two gyms at once must be flagged, even for one team"


def test_distinct_duration_hard_locks_not_deduplicated() -> None:
    # Regression (audit review F2): two HARD templates at the same
    # team/venue/day/start but DIFFERENT durations are not duplicates — the
    # longer one must not be silently dropped.
    base = {"teamId": "a", "venueId": "v", "dayOfWeek": 2, "startTime": "18:00", "lockLevel": "HARD"}
    payload = make_payload(
        teams=[_team("a")],
        venues=[make_venue("v", [(2, "18:00")])],
        slot_templates=[
            dict(base, id="short", durationMinutes=60),
            dict(base, id="long", durationMinutes=120),
        ],
    )
    result = solve_payload(payload)
    durations = {int(s["durationMinutes"]) for s in result["slots"] if s["teamId"] == "a"}
    assert 120 in durations, "the longer distinct lock must be preserved"


def test_all_fixture_severities_in_allowed_enum() -> None:
    for name in ("simple_club", "medium_club", "vacation_week", "impossible"):
        with open(FIXTURES_DIR / f"{name}.json", encoding="utf-8") as f:
            data = json.load(f)
        result = solve_payload(data, timeout=30)
        for diag in result["diagnostics"]:
            assert diag["severity"] in ALLOWED_SEVERITIES, (
                f"{name}: severity {diag['severity']!r} not in {ALLOWED_SEVERITIES}"
            )


def test_clean_schedule_has_no_conflict_diagnostics() -> None:
    # A well-formed single-team payload completes with zero conflict diagnostics.
    payload = make_payload(
        teams=[_team("t", sessions=1)],
        venues=[make_venue("v", [(2, "18:00"), (4, "18:00")])],
    )
    result = solve_payload(payload)
    assert result["status"] == "completed"
    conflicts = [d for d in result["diagnostics"] if d["type"] == "conflict"]
    assert conflicts == [], f"clean schedule must have no conflicts, got {conflicts}"
