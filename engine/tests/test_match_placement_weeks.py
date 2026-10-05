"""ISO-week slicing of the match placement (ENG-50): the solver splits a request
week by week, solves each under a PER-WEEK budget, and returns ONE merged
response (no schema change, CONTRACT_VERSION stays 1.2).

Covered here: the partition key (a weekend stays whole, a Sunday and the next
Monday split), an AWAY match riding in its week's slice, the deterministic merge,
and the EQUIVALENCE invariant — a multi-week request placed in one call matches,
week for week, the isolated solve of each week.
"""

from __future__ import annotations

from datetime import date, time
from typing import Any

from app.main import read_contract_version
from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.solver.match_placement import (
    _iso_week,
    _merge_week_results,
    _partition_by_iso_week,
    solve_match_placement,
)

WEEK_A_SAT = "2026-10-03"  # ISO 2026-W40, Saturday
WEEK_A_SUN = "2026-10-04"  # same weekend → same ISO week as WEEK_A_SAT
WEEK_A_MON = "2026-10-05"  # the following Monday → ISO 2026-W41
WEEK_B_SAT = "2026-10-10"  # ISO 2026-W41
WEEK_C_SAT = "2026-10-17"  # ISO 2026-W42


def _saturday_window() -> list[dict[str, Any]]:
    # Saturday (ISO 6), Sunday (ISO 7) and Monday (ISO 1), all wide open so the
    # partition — never the geometry — is what the tests isolate.
    return [{"dayOfWeek": day, "start": "13:00", "end": "22:30"} for day in (1, 6, 7)]


def _base_payload(matches: list[dict[str, Any]], **over: Any) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "version": read_contract_version(),
        "clubId": "club-weeks",
        "seasonId": "season-2026",
        "solverSeed": 42,
        "solverTimeoutSeconds": 30,
        "matches": matches,
        "venues": [
            {
                "id": "mateo",
                "name": "Mateo",
                "matchWindows": _saturday_window(),
                "unavailabilities": [],
            }
        ],
        "teams": [
            {
                "id": "pnm",
                "name": "PNM",
                "leagueWindows": [],
                "habits": [{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "mateo"}],
                "coaches": [{"coachId": "emerick", "role": "MAIN"}],
            },
            {"id": "sf1", "name": "SF1", "leagueWindows": [], "habits": [], "coaches": []},
            {
                "id": "rf3",
                "name": "RF3",
                "leagueWindows": [],
                "habits": [],
                "coaches": [{"coachId": "emerick", "role": "MAIN"}],
            },
        ],
        "teamLinks": [{"teamAId": "pnm", "teamBId": "sf1", "type": "BACK_TO_BACK"}],
        "trainingOccupancies": [],
    }
    payload.update(over)
    return payload


def test_iso_week_keeps_a_weekend_whole_but_splits_sunday_and_monday() -> None:
    # Two matches the same weekend (Sat + Sun) land in ONE ISO week; a Sunday and
    # the FOLLOWING Monday land in TWO (the ISO week boundary is Monday).
    sat, sun, mon = date.fromisoformat(WEEK_A_SAT), date.fromisoformat(WEEK_A_SUN), date.fromisoformat(WEEK_A_MON)
    assert _iso_week(sat) == _iso_week(sun), "a weekend's Saturday and Sunday share one ISO week"
    assert _iso_week(sun) != _iso_week(mon), "a Sunday and the following Monday are two ISO weeks"


def test_partition_groups_matches_and_embeds_the_away_of_the_week() -> None:
    # Week A: a TO_PLACE pnm match + an AWAY rf3 match (same weekend) ; Week B: a
    # TO_PLACE pnm match. The AWAY must ride in Week A's slice (it frees that day's
    # habit protection) and each week carries only its own matches.
    payload = _base_payload(
        [
            {"id": "a-pnm", "teamId": "pnm", "date": WEEK_A_SAT, "kind": "TO_PLACE"},
            {"id": "a-rf3", "teamId": "rf3", "date": WEEK_A_SUN, "kind": "AWAY", "kickoff": "15:30"},
            {"id": "b-pnm", "teamId": "pnm", "date": WEEK_B_SAT, "kind": "TO_PLACE"},
        ]
    )
    slices = _partition_by_iso_week(MatchPlacementInputSchema.model_validate(payload))

    assert [key for key, _ in slices] == sorted(key for key, _ in slices), "weeks come back in ascending key order"
    by_week = dict(slices)
    week_a = by_week[_iso_week(date.fromisoformat(WEEK_A_SAT))]
    week_b = by_week[_iso_week(date.fromisoformat(WEEK_B_SAT))]

    assert {m.id for m in week_a.matches} == {"a-pnm", "a-rf3"}, "the AWAY match rides in its week's slice"
    assert any(m.kind == "AWAY" for m in week_a.matches)
    assert {m.id for m in week_b.matches} == {"b-pnm"}
    # Shared context is copied verbatim onto every slice.
    assert {t.id for t in week_a.teams} == {t.id for t in week_b.teams} == {"pnm", "sf1", "rf3"}


def test_partition_routes_training_occupancies_to_their_week() -> None:
    payload = _base_payload(
        [
            {"id": "a-pnm", "teamId": "pnm", "date": WEEK_A_SAT, "kind": "TO_PLACE"},
            {"id": "b-pnm", "teamId": "pnm", "date": WEEK_B_SAT, "kind": "TO_PLACE"},
        ],
        trainingOccupancies=[
            {"date": WEEK_A_SAT, "start": "13:00", "end": "14:30", "coachId": "emerick"},
            {"date": WEEK_B_SAT, "start": "13:00", "end": "14:30", "coachId": "emerick"},
        ],
    )
    by_week = dict(_partition_by_iso_week(MatchPlacementInputSchema.model_validate(payload)))
    week_a = by_week[_iso_week(date.fromisoformat(WEEK_A_SAT))]
    week_b = by_week[_iso_week(date.fromisoformat(WEEK_B_SAT))]
    assert [o.occupancy_date.isoformat() for o in week_a.training_occupancies] == [WEEK_A_SAT]
    assert [o.occupancy_date.isoformat() for o in week_b.training_occupancies] == [WEEK_B_SAT]


def test_merge_is_deterministic_and_sums_metrics() -> None:
    # Concatenation follows the input (week) order; metrics are summed; status is
    # "completed" as soon as one week completed.
    week_one = {
        "status": "completed",
        "placements": [{"matchId": "a", "venueId": "mateo", "kickoff": time(15, 30)}],
        "unplaced": [{"matchId": "u1", "reason": "venue_full", "message": "x"}],
        "diagnostics": [{"id": "d1", "type": "unplaced_match"}],
        "metrics": {"solver_version": "cp-sat", "nb_variables": 10, "nb_constraints": 4, "wall_time_ms": 100},
    }
    week_two = {
        "status": "completed",
        "placements": [{"matchId": "b", "venueId": "mateo", "kickoff": time(17, 15)}],
        "unplaced": [],
        "diagnostics": [{"id": "d2", "type": "unplaced_match"}],
        "metrics": {"solver_version": "cp-sat", "nb_variables": 6, "nb_constraints": 2, "wall_time_ms": 50},
    }
    merged = _merge_week_results([week_one, week_two])
    assert [p["matchId"] for p in merged["placements"]] == ["a", "b"]
    assert [d["id"] for d in merged["diagnostics"]] == ["d1", "d2"]
    assert merged["unplaced"] == week_one["unplaced"]
    assert merged["status"] == "completed"
    assert merged["metrics"] == {
        "solver_version": "cp-sat",
        "nb_variables": 16,
        "nb_constraints": 6,
        "wall_time_ms": 150,
    }


def test_merge_fails_only_when_every_week_failed() -> None:
    too_large = {"status": "failed", "placements": [], "unplaced": [], "diagnostics": [{"id": "e"}], "metrics": None}
    completed = {
        "status": "completed",
        "placements": [{"matchId": "a", "venueId": "mateo", "kickoff": time(15, 30)}],
        "unplaced": [],
        "diagnostics": [],
        "metrics": {"solver_version": "cp-sat", "nb_variables": 3, "nb_constraints": 1, "wall_time_ms": 20},
    }
    # One too-large week next to a completed one: the completed placements survive,
    # the error diagnostic still rides, and the global status stays "completed".
    mixed = _merge_week_results([too_large, completed])
    assert mixed["status"] == "completed"
    assert [p["matchId"] for p in mixed["placements"]] == ["a"]
    assert mixed["diagnostics"] == [{"id": "e"}]
    assert mixed["metrics"] == {"solver_version": "cp-sat", "nb_variables": 3, "nb_constraints": 1, "wall_time_ms": 20}
    # Every week failed → "failed", metrics None (the single-week behaviour preserved).
    all_failed = _merge_week_results([too_large, dict(too_large)])
    assert all_failed["status"] == "failed"
    assert all_failed["metrics"] is None


def _solve_dict(payload: dict[str, Any]) -> dict[tuple[str, str], Any]:
    result = solve_match_placement(MatchPlacementInputSchema.model_validate(payload))
    assert result["unplaced"] == [], result
    return {p["matchId"]: (p["venueId"], p["kickoff"]) for p in result["placements"]}


def test_sliced_solve_equals_isolated_week_solves() -> None:
    # EQUIVALENCE (ENG-50): three ISO weeks placed in ONE call produce exactly the
    # union of the placements each week produces solved ALONE — proving the slicing
    # couples no two weeks. Each week has pnm (habit 15:30) + sf1 BACK_TO_BACK, week A
    # also a FIXED anchor and a shared-coach AWAY.
    def _week(prefix: str, sat: str, *, extras: list[dict[str, Any]] | None = None) -> list[dict[str, Any]]:
        matches = [
            {"id": f"{prefix}-pnm", "teamId": "pnm", "date": sat, "kind": "TO_PLACE"},
            {"id": f"{prefix}-sf1", "teamId": "sf1", "date": sat, "kind": "TO_PLACE"},
        ]
        return matches + (extras or [])

    week_a = _week(
        "a",
        WEEK_A_SAT,
        extras=[
            {
                "id": "a-fix",
                "teamId": "rf3",
                "date": WEEK_A_SAT,
                "kind": "FIXED",
                "venueId": "mateo",
                "kickoff": "20:30",
            },
            {"id": "a-away", "teamId": "rf3", "date": WEEK_A_SAT, "kind": "AWAY", "kickoff": "15:30"},
        ],
    )
    week_b = _week("b", WEEK_B_SAT)
    week_c = _week("c", WEEK_C_SAT)
    occupancies = [{"date": WEEK_A_SAT, "start": "13:00", "end": "14:30", "coachId": "emerick"}]

    combined = _solve_dict(_base_payload(week_a + week_b + week_c, trainingOccupancies=occupancies))

    isolated: dict[tuple[str, str], Any] = {}
    for matches, occ in ((week_a, occupancies), (week_b, []), (week_c, [])):
        isolated.update(_solve_dict(_base_payload(matches, trainingOccupancies=occ)))

    assert combined == isolated, "a week solved in the combined call matches its isolated solve"
