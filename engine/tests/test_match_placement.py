"""Unit tests of the dated match-placement solve (P1-4 PR D, ADR-0003).

Every payload goes through MatchPlacementInputSchema (camelCase aliases = the
wire contract). Deterministic: 1 worker + fixed seed baked in the solver.
"""

from __future__ import annotations

from typing import Any

from app.main import read_contract_version
from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.solver.match_placement import solve_match_placement

SATURDAY = "2026-10-03"
SUNDAY = "2026-10-04"


def payload(**over: Any) -> MatchPlacementInputSchema:
    base: dict[str, Any] = {
        # Version DÉRIVÉE de la source de vérité (engine/CONTRACT_VERSION), pas
        # d'un littéral qui redemanderait ce travail au prochain bump.
        "version": read_contract_version(),
        "clubId": "club-1",
        "seasonId": "season-1",
        "matches": [],
        "venues": [],
        "teams": [],
    }
    base.update(over)
    return MatchPlacementInputSchema.model_validate(base)


def venue(
    venue_id: str = "v1",
    windows: list[dict[str, Any]] | None = None,
    unavailabilities: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    return {
        "id": venue_id,
        "name": venue_id,
        "matchWindows": windows if windows is not None else [{"dayOfWeek": 6, "start": "14:00", "end": "18:00"}],
        "unavailabilities": unavailabilities or [],
    }


def team(team_id: str = "t1", **over: Any) -> dict[str, Any]:
    base: dict[str, Any] = {"id": team_id, "name": team_id, "leagueWindows": [], "habits": [], "coaches": []}
    base.update(over)
    return base


def to_place(match_id: str = "m1", team_id: str = "t1", match_date: str = SATURDAY, **over: Any) -> dict[str, Any]:
    base: dict[str, Any] = {"id": match_id, "teamId": team_id, "date": match_date, "kind": "TO_PLACE"}
    base.update(over)
    return base


def kickoff_of(result: dict[str, Any], match_id: str) -> str:
    placement = next(p for p in result["placements"] if p["matchId"] == match_id)
    return placement["kickoff"].strftime("%H:%M")


def test_places_inside_the_access_window() -> None:
    # Window 14:00-18:00, footprint 2h15 → legal kickoffs 14:30..16:15.
    result = solve_match_placement(payload(matches=[to_place()], venues=[venue()], teams=[team()]))
    assert result["unplaced"] == []
    assert "14:30" <= kickoff_of(result, "m1") <= "16:15"


def test_no_access_window_on_that_day_is_named() -> None:
    result = solve_match_placement(payload(matches=[to_place(match_date=SUNDAY)], venues=[venue()], teams=[team()]))
    assert result["placements"] == []
    assert result["unplaced"][0]["reason"] == "no_access_window"
    assert result["diagnostics"][0]["type"] == "unplaced_match"


def test_league_window_bounds_the_kickoff() -> None:
    # Access 14:00-22:30 · league Saturday 17:00-21:00 → kickoff in [17:00, 20:45].
    result = solve_match_placement(
        payload(
            matches=[to_place()],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "22:30"}])],
            teams=[team(leagueWindows=[{"dayOfWeek": 6, "kickoffMin": "17:00", "kickoffMax": "21:00"}])],
        )
    )
    assert result["unplaced"] == []
    assert "17:00" <= kickoff_of(result, "m1") <= "20:45"


def test_league_day_mismatch_is_named() -> None:
    # Mapped team whose league only allows SUNDAY → a Saturday match has no
    # legal kickoff at all: no_league_intersection.
    result = solve_match_placement(
        payload(
            matches=[to_place()],
            venues=[venue()],
            teams=[team(leagueWindows=[{"dayOfWeek": 7, "kickoffMin": "10:00", "kickoffMax": "16:00"}])],
        )
    )
    assert result["unplaced"][0]["reason"] == "no_league_intersection"


def test_unavailable_venue_is_named() -> None:
    result = solve_match_placement(
        payload(
            matches=[to_place()],
            venues=[venue(unavailabilities=[{"startDate": "2026-10-01", "endDate": "2026-10-05"}])],
            teams=[team()],
        )
    )
    assert result["unplaced"][0]["reason"] == "venue_unavailable"


def test_two_matches_never_overlap_in_one_venue() -> None:
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1"), to_place("m2", "t2")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "19:00"}])],
            teams=[team("t1"), team("t2")],
        )
    )
    assert result["unplaced"] == []
    k1 = kickoff_of(result, "m1")
    k2 = kickoff_of(result, "m2")
    minutes = lambda s: int(s[:2]) * 60 + int(s[3:])  # noqa: E731
    assert abs(minutes(k1) - minutes(k2)) >= 135


def test_fixed_match_consumes_its_slot_and_never_moves() -> None:
    # FIXED at 15:30 occupies 15:00-17:15 → the TO_PLACE lands 17:45+.
    result = solve_match_placement(
        payload(
            matches=[
                {"id": "fx", "teamId": "t1", "date": SATURDAY, "kind": "FIXED", "venueId": "v1", "kickoff": "15:30"},
                to_place("m1", "t2"),
            ],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "20:00"}])],
            teams=[team("t1"), team("t2")],
        )
    )
    assert all(p["matchId"] != "fx" for p in result["placements"])  # never re-emitted
    assert kickoff_of(result, "m1") >= "17:45"


def test_full_venue_is_named() -> None:
    # Window fits exactly ONE footprint (14:00-16:15 → single kickoff 14:30),
    # already taken by a FIXED match.
    result = solve_match_placement(
        payload(
            matches=[
                {"id": "fx", "teamId": "t1", "date": SATURDAY, "kind": "FIXED", "venueId": "v1", "kickoff": "14:30"},
                to_place("m1", "t2"),
            ],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "16:15"}])],
            teams=[team("t1"), team("t2")],
        )
    )
    assert result["unplaced"][0]["reason"] == "venue_full"


def test_colliding_fixed_anchors_never_sink_the_whole_solve() -> None:
    # NR P1-4 PR E1 (bug caught by smoke-place-matches): the manual loop NEVER
    # blocks a collision (founder decision — the diagnostic alerts), so two
    # manual anchors CAN overlap on the same venue+date. As fixed NoOverlap
    # intervals they made the model INFEASIBLE and every other match came back
    # venue_full. Anchors must prune candidates, not sink the solve.
    result = solve_match_placement(
        payload(
            matches=[
                {"id": "fx1", "teamId": "t1", "date": SATURDAY, "kind": "FIXED", "venueId": "v1", "kickoff": "15:00"},
                {"id": "fx2", "teamId": "t2", "date": SATURDAY, "kind": "FIXED", "venueId": "v1", "kickoff": "15:00"},
                to_place("m1", "t3", SUNDAY),
            ],
            venues=[
                venue(
                    windows=[
                        {"dayOfWeek": 6, "start": "14:00", "end": "18:00"},
                        {"dayOfWeek": 7, "start": "14:00", "end": "18:00"},
                    ]
                )
            ],
            teams=[team("t1"), team("t2"), team("t3")],
        )
    )
    assert result["status"] == "completed"
    assert result["unplaced"] == []
    assert kickoff_of(result, "m1") >= "14:30"


def test_candidates_under_a_fixed_anchor_are_pruned_not_infeasible() -> None:
    # Same collision, and a TO_PLACE on the SAME day: the anchors eat 14:30-17:15
    # of the 14:00-18:00 window (kickoffs 14:30..16:15 all overlap 15:00's
    # footprint) → venue_full NAMED, the solve still completes.
    result = solve_match_placement(
        payload(
            matches=[
                {"id": "fx1", "teamId": "t1", "date": SATURDAY, "kind": "FIXED", "venueId": "v1", "kickoff": "15:00"},
                {"id": "fx2", "teamId": "t2", "date": SATURDAY, "kind": "FIXED", "venueId": "v1", "kickoff": "15:00"},
                to_place("m1", "t3"),
            ],
            venues=[venue()],
            teams=[team("t1"), team("t2"), team("t3")],
        )
    )
    assert result["status"] == "completed"
    assert result["unplaced"][0]["reason"] == "venue_full"


def test_habit_time_and_venue_attract_the_placement() -> None:
    result = solve_match_placement(
        payload(
            matches=[to_place()],
            venues=[venue("v1"), venue("v2")],
            teams=[team(habits=[{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "v2"}])],
        )
    )
    placement = result["placements"][0]
    assert placement["venueId"] == "v2"
    assert placement["kickoff"].strftime("%H:%M") == "15:30"


def test_stability_keeps_the_previous_solver_placement() -> None:
    result = solve_match_placement(
        payload(
            matches=[to_place(currentVenueId="v1", currentKickoff="15:45")],
            venues=[venue()],
            teams=[team()],
        )
    )
    assert kickoff_of(result, "m1") == "15:45"


def test_main_coach_training_pushes_the_match_away() -> None:
    # MAIN coach trains 15:00-17:15 → every kickoff before 17:45 overlaps.
    result = solve_match_placement(
        payload(
            matches=[to_place()],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "20:00"}])],
            teams=[team(coaches=[{"coachId": "c1", "role": "MAIN"}])],
            trainingOccupancies=[{"date": SATURDAY, "start": "15:00", "end": "17:15", "coachId": "c1"}],
        )
    )
    assert kickoff_of(result, "m1") >= "17:45"


def test_not_simultaneous_link_separates_the_two_teams() -> None:
    # Two venues, wide windows: overlapping placements are possible AND
    # separation is possible (a 14:00-18:00 window would cap Δ at 105 < 135,
    # making separation infeasible — the link must not fight physics).
    wide = [{"dayOfWeek": 6, "start": "14:00", "end": "22:30"}]
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1"), to_place("m2", "t2")],
            venues=[venue("v1", windows=wide), venue("v2", windows=wide)],
            teams=[team("t1"), team("t2")],
            teamLinks=[{"teamAId": "t1", "teamBId": "t2", "type": "NOT_SIMULTANEOUS"}],
        )
    )
    minutes = lambda s: int(s[:2]) * 60 + int(s[3:])  # noqa: E731
    assert abs(minutes(kickoff_of(result, "m1")) - minutes(kickoff_of(result, "m2"))) >= 135


def test_back_to_back_link_chains_on_the_same_venue() -> None:
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1"), to_place("m2", "t2")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "22:30"}])],
            teams=[team("t1"), team("t2")],
            teamLinks=[{"teamAId": "t1", "teamBId": "t2", "type": "BACK_TO_BACK"}],
        )
    )
    minutes = lambda s: int(s[:2]) * 60 + int(s[3:])  # noqa: E731
    assert abs(minutes(kickoff_of(result, "m1")) - minutes(kickoff_of(result, "m2"))) == 135


def test_rotation_time_and_venue_attract_the_placement() -> None:
    # RMM-5: t1 belongs to a Saturday 15:30 rotation at v2 and has NO habit — the
    # rotation attracts its HOME match to (v2, 15:30), at parity with a habit.
    result = solve_match_placement(
        payload(
            matches=[to_place()],
            venues=[venue("v1"), venue("v2")],
            teams=[team("t1"), team("t2")],
            slotRotations=[{"venueId": "v2", "dayOfWeek": 6, "kickoff": "15:30", "teamIds": ["t1", "t2"]}],
        )
    )
    placement = result["placements"][0]
    assert placement["venueId"] == "v2"
    assert placement["kickoff"].strftime("%H:%M") == "15:30"


def test_rotation_window_is_protected_when_no_member_plays() -> None:
    # The rotation slot (Saturday 15:30 at v1) is defended on a date where NEITHER
    # member (t2, t3) has a match — t1 (an outsider) lands outside 15:00-17:15.
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "20:00"}])],
            teams=[team("t1"), team("t2"), team("t3")],
            slotRotations=[{"venueId": "v1", "dayOfWeek": 6, "kickoff": "15:30", "teamIds": ["t2", "t3"]}],
        )
    )
    k = kickoff_of(result, "m1")
    minutes = int(k[:2]) * 60 + int(k[3:])
    assert minutes + 105 <= 15 * 60 or minutes - 30 >= 17 * 60 + 15


def test_empty_rotation_block_is_a_noop() -> None:
    # An absent/empty slotRotations block must not perturb the objective — the
    # world before RMM-5 is byte-identical (pattern teamLinks).
    base = {
        "matches": [to_place()],
        "venues": [venue("v1"), venue("v2")],
        "teams": [team(habits=[{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "v2"}])],
    }
    without = solve_match_placement(payload(**base))
    with_empty = solve_match_placement(payload(slotRotations=[], **base))
    assert without["placements"] == with_empty["placements"]


def test_protected_habit_window_repels_other_matches() -> None:
    # t2 has a Saturday 15:30 habit at v1 and NO match that day: its window
    # 15:00-17:15 is defended — m1 (other team) lands outside it.
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "20:00"}])],
            teams=[team("t1"), team("t2", habits=[{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "v1"}])],
        )
    )
    k = kickoff_of(result, "m1")
    minutes = int(k[:2]) * 60 + int(k[3:])
    # Footprint [k-30, k+105] must not cross [15:00, 17:15].
    assert minutes + 105 <= 15 * 60 or minutes - 30 >= 17 * 60 + 15
