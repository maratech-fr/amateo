"""Unit tests of the dated match-placement solve (P1-4 PR D, ADR-0003).

Every payload goes through MatchPlacementInputSchema (camelCase aliases = the
wire contract). Deterministic: 1 worker + fixed seed baked in the solver.
"""

from __future__ import annotations

from typing import Any

import pytest
from ortools.sat.python import cp_model
from pydantic import ValidationError

from app.main import read_contract_version
from app.schemas.match_input_schema import MAX_LEAGUE_WINDOWS_PER_TEAM, MatchPlacementInputSchema
from app.solver import match_placement
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
    # Window 14:00-18:00, match 105 min (venue-only, D1) → legal kickoffs
    # 14:00..16:15 (kickoff + 105 ≤ 18:00).
    result = solve_match_placement(payload(matches=[to_place()], venues=[venue()], teams=[team()]))
    assert result["unplaced"] == []
    assert "14:00" <= kickoff_of(result, "m1") <= "16:15"


def test_kickoff_at_the_window_opening_is_legal() -> None:
    # D1 (P4-203): the access window no longer reserves 30 min of warm-up before
    # kickoff, so a match may start at the very opening. Window 14:00-15:45 holds
    # exactly one 105-min match → kickoff 14:00.
    result = solve_match_placement(
        payload(
            matches=[to_place()],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "15:45"}])],
            teams=[team()],
        )
    )
    assert result["unplaced"] == []
    assert kickoff_of(result, "m1") == "14:00"


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


def test_league_windows_over_cap_rejected() -> None:
    # A10 defense-in-depth: a team's league envelope is bounded (mirror of the
    # per-venue window cap) so an oversized payload is rejected at the boundary.
    windows = [{"dayOfWeek": 6, "kickoffMin": "10:00", "kickoffMax": "12:00"}] * (MAX_LEAGUE_WINDOWS_PER_TEAM + 1)
    with pytest.raises(ValidationError):
        payload(matches=[to_place()], venues=[venue()], teams=[team(leagueWindows=windows)])


def test_league_windows_at_cap_accepted() -> None:
    windows = [{"dayOfWeek": 6, "kickoffMin": "10:00", "kickoffMax": "12:00"}] * MAX_LEAGUE_WINDOWS_PER_TEAM
    model = payload(matches=[to_place()], venues=[venue()], teams=[team(leagueWindows=windows)])
    assert len(model.teams[0].league_windows) == MAX_LEAGUE_WINDOWS_PER_TEAM


def test_build_budget_exhausted_fails_with_a_diagnostic(monkeypatch: pytest.MonkeyPatch) -> None:
    # A pathologically large placement problem must not spin CP-SAT for minutes
    # inside the model BUILD (before the solver's own time limit even applies).
    # With the build budget forced to ~0, the solve aborts early, returns
    # status="failed" and a single actionable diagnostic — never a silent hang.
    monkeypatch.setattr(match_placement, "BUILD_BUDGET_SECONDS", 0.0, raising=False)
    result = solve_match_placement(
        payload(
            matches=[to_place(match_id=f"m{i}", team_id="t1") for i in range(20)],
            venues=[venue()],
            teams=[team()],
        )
    )
    assert result["status"] == "failed", result
    assert result["placements"] == []
    assert result["metrics"] is None
    diags = [d for d in result["diagnostics"] if d["type"] == "placement_problem_too_large"]
    assert len(diags) == 1, result["diagnostics"]
    assert diags[0]["severity"] == "error"


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
    # Venue window = match only (105 min, D1) → they may sit 105 min apart.
    assert abs(minutes(k1) - minutes(k2)) >= 105


def test_two_seniors_chain_two_hours_apart_in_one_venue() -> None:
    # D1 (P4-203): the court is held for the match only (105 min), the warm-up no
    # longer occupies it — two league matches back-to-back both fit. Window
    # 14:00-17:30 holds EXACTLY two 105-min matches (the old 2h15 footprint would
    # have unplaced one). Both placed, on the one venue, 105 min apart.
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1"), to_place("m2", "t2")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "17:30"}])],
            teams=[team("t1"), team("t2")],
        )
    )
    assert result["unplaced"] == []
    assert {p["venueId"] for p in result["placements"]} == {"v1"}
    k1 = kickoff_of(result, "m1")
    k2 = kickoff_of(result, "m2")
    minutes = lambda s: int(s[:2]) * 60 + int(s[3:])  # noqa: E731
    assert abs(minutes(k1) - minutes(k2)) == 105


def test_fixed_match_consumes_its_slot_and_never_moves() -> None:
    # FIXED at 15:30 occupies the venue 15:30-17:15 (match only, D1) → the
    # TO_PLACE lands 17:15+.
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
    assert kickoff_of(result, "m1") >= "17:15"


def test_full_venue_is_named() -> None:
    # Window fits exactly ONE 105-min match (14:00-15:45 → single kickoff 14:00),
    # already taken by a FIXED match.
    result = solve_match_placement(
        payload(
            matches=[
                {"id": "fx", "teamId": "t1", "date": SATURDAY, "kind": "FIXED", "venueId": "v1", "kickoff": "14:00"},
                to_place("m1", "t2"),
            ],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "15:45"}])],
            teams=[team("t1"), team("t2")],
        )
    )
    assert result["unplaced"][0]["reason"] == "venue_full"


def test_two_seniors_one_hour_apart_leave_one_unplaced() -> None:
    # A window holding only ONE 105-min match can host a single senior game: a
    # second senior would need to sit ≥ 105 min away and overflows → venue_full.
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1"), to_place("m2", "t2")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "15:45"}])],
            teams=[team("t1"), team("t2")],
        )
    )
    assert len(result["placements"]) == 1
    assert result["unplaced"][0]["reason"] == "venue_full"


def test_category_duration_is_honoured() -> None:
    # A 75-min category fits a 75-min window; a 105-min one does not (P4-203).
    fits = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "15:15"}])],
            teams=[team("t1", matchMinutes=75, warmupMinutes=30)],
        )
    )
    assert fits["unplaced"] == []
    assert kickoff_of(fits, "m1") == "14:00"

    overflows = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "15:15"}])],
            teams=[team("t1", matchMinutes=105, warmupMinutes=30)],
        )
    )
    assert overflows["placements"] == []
    assert overflows["unplaced"][0]["reason"] == "no_access_window"


def test_absent_durations_default_to_105_and_30() -> None:
    # A team WITHOUT matchMinutes/warmupMinutes behaves as 105 / 30 (the omitted
    # fields keep the previous behaviour). Window 14:00-15:45 = one 105-min match.
    result = solve_match_placement(
        payload(
            matches=[to_place()],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "15:45"}])],
            teams=[team()],
        )
    )
    assert result["unplaced"] == []
    assert kickoff_of(result, "m1") == "14:00"


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
    # Sunday window 14:00-18:00, match 105 → legal kickoffs 14:00..16:15.
    assert "14:00" <= kickoff_of(result, "m1") <= "16:15"


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
    # MAIN coach trains 15:00-17:15. Lot M — the person window is warm-up-free
    # ([kickoff, kickoff + 105]), so a kickoff at 17:15 (window 17:15-19:00) already
    # clears the session ; before lot M the warm-up pushed it to 17:45.
    result = solve_match_placement(
        payload(
            matches=[to_place()],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "20:00"}])],
            teams=[team(coaches=[{"coachId": "c1", "role": "MAIN"}])],
            trainingOccupancies=[{"date": SATURDAY, "start": "15:00", "end": "17:15", "coachId": "c1"}],
        )
    )
    assert kickoff_of(result, "m1") >= "17:15"


def test_not_simultaneous_link_separates_the_two_teams() -> None:
    # Two venues, wide windows: overlapping placements are possible AND separation
    # is possible. Lot M — the NOT_SIMULTANEOUS window is warm-up-free like every
    # person window, so « not simultaneous » means the MATCH windows do not overlap:
    # the two are pushed ≥ one match (105 min) apart (before lot M: ≥ 135).
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
    assert abs(minutes(kickoff_of(result, "m1")) - minutes(kickoff_of(result, "m2"))) >= 105


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
    # Chained = the second kicks off exactly when the first's match ends (105 min).
    assert abs(minutes(kickoff_of(result, "m1")) - minutes(kickoff_of(result, "m2"))) == 105


def test_shared_coach_no_longer_forces_extra_warmup_spacing() -> None:
    # INVERSÉ (lot M) — the PERSON (coach) window is now warm-up-FREE. Two matches of
    # one MAIN coach in a SINGLE venue pack BACK-TO-BACK (exactly one match, 105 min):
    # the HARD venue no-overlap already keeps the match windows apart, and there is no
    # warm-up left to penalise. Before lot M the warm-up pushed them to 135 min apart.
    # (Single venue so day-compaction forces the tight packing deterministically.)
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1"), to_place("m2", "t2")],
            venues=[venue("v1", windows=[{"dayOfWeek": 6, "start": "14:00", "end": "22:30"}])],
            teams=[
                team("t1", coaches=[{"coachId": "c1", "role": "MAIN"}]),
                team("t2", coaches=[{"coachId": "c1", "role": "MAIN"}]),
            ],
        )
    )
    minutes = lambda s: int(s[:2]) * 60 + int(s[3:])  # noqa: E731
    assert abs(minutes(kickoff_of(result, "m1")) - minutes(kickoff_of(result, "m2"))) == 105


def test_inactive_ab_partner_does_not_chase_a_team_off_its_own_ideal() -> None:
    # P4-271 (fix) — t1 and its A/B partner t2 declare the SAME ideal slot (Saturday
    # 15:30 at v1). This weekend t1 receives (a match to place) and t2 does NOT, so
    # t2's habit window [15:30, 17:15] is protected. Without the own-ideal skip, t1's
    # 15:30 candidate scores +15 +5 −25 = −5 and loses to a neutral 17:15 slot (0);
    # the fix keeps t1 ON its declared ideal. FALSIFIED without the skip (lands 17:15).
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "20:00"}])],
            teams=[
                team("t1", habits=[{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "v1"}]),
                team("t2", habits=[{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "v1"}]),
            ],
        )
    )
    placement = result["placements"][0]
    assert placement["venueId"] == "v1"
    assert placement["kickoff"].strftime("%H:%M") == "15:30"


def test_two_homes_sharing_an_ideal_slot_place_one_there_the_other_elsewhere() -> None:
    # P4-271 — two teams declare the SAME ideal slot (Saturday 15:30 at v1) and BOTH
    # receive this weekend. The HARD venue no-overlap lets only ONE sit at 15:30; the
    # other is placed elsewhere in the window (never dropped). Both play → neither
    # protects a window, so the shared ideal is not double-penalised.
    result = solve_match_placement(
        payload(
            matches=[to_place("m1", "t1"), to_place("m2", "t2")],
            venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "20:00"}])],
            teams=[
                team("t1", habits=[{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "v1"}]),
                team("t2", habits=[{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "v1"}]),
            ],
        )
    )
    assert result["unplaced"] == []
    kickoffs = {kickoff_of(result, "m1"), kickoff_of(result, "m2")}
    assert "15:30" in kickoffs  # one team gets the shared ideal slot
    minutes = lambda s: int(s[:2]) * 60 + int(s[3:])  # noqa: E731
    # The two MATCH windows [k, k+105] must not overlap (HARD venue no-overlap at v1).
    assert abs(minutes(kickoff_of(result, "m1")) - minutes(kickoff_of(result, "m2"))) >= 105


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
    # Protected MATCH window [15:30, 17:15] (D1) — the candidate's match window
    # [k, k+105] must not cross it.
    assert minutes + 105 <= 15 * 60 + 30 or minutes >= 17 * 60 + 15


def test_away_match_never_pushes_the_home_match_of_a_shared_coach() -> None:
    # INVERSÉ (P4-240 ③, décision B) — the placement solver IGNORES every person
    # footprint of an AWAY match (« c'est la vie ; le radar signale le conflit »).
    # Shared coach c1: t1 plays HOME (to place, habit 15:00), t2 plays AWAY at 15:00.
    # Whatever the round trip, the AWAY window never exists for the solver, so nothing
    # penalises t1's 15:00 habit slot — it wins in EVERY case (before B a big round
    # trip pushed it away). Durations 60/0 for clean math.
    def run(round_trip: int) -> dict[str, Any]:
        return solve_match_placement(
            payload(
                matches=[
                    to_place("m1", "t1"),
                    {
                        "id": "away1",
                        "teamId": "t2",
                        "date": SATURDAY,
                        "kind": "AWAY",
                        "kickoff": "15:00",
                    },
                ],
                venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "17:00"}])],
                teams=[
                    team(
                        "t1",
                        coaches=[{"coachId": "c1", "role": "MAIN"}],
                        habits=[{"dayOfWeek": 6, "kickoff": "15:00", "venueId": "v1"}],
                        matchMinutes=60,
                        warmupMinutes=0,
                    ),
                    team("t2", coaches=[{"coachId": "c1", "role": "MAIN"}], matchMinutes=60, warmupMinutes=0),
                ],
            )
        )

    # No round trip AND a large one both leave the habit slot free: the AWAY footprint
    # is never consumed, so the placement is identical (15:00) in both.
    assert kickoff_of(run(0), "m1") == "15:00"
    assert kickoff_of(run(240), "m1") == "15:00"


def test_an_away_match_still_frees_its_teams_habit_protection() -> None:
    # P4-240 ③ (décision B) — the AWAY match is ignored as a PERSON footprint, but it
    # is STILL emitted and STILL feeds `team_dates`: a team playing away a given day
    # has a match that day, so its habitual MATCH window is NOT defended against other
    # teams. t2 has a Saturday 15:30 habit at v1 and no coaches. WITHOUT a match that
    # day, its window [15:30, 17:15] is protected → m1 (t1) is repelled outside it
    # (kickoff ≥ 17:15, cf. test_protected_habit_window_repels_other_matches). WITH t2
    # playing AWAY that Saturday, the protection lifts → m1's match window is free to
    # cross [15:30, 17:15].
    def run(with_away: bool) -> dict[str, Any]:
        matches: list[dict[str, Any]] = [to_place("m1", "t1")]
        if with_away:
            matches.append({"id": "away2", "teamId": "t2", "date": SATURDAY, "kind": "AWAY", "kickoff": "10:00"})
        return solve_match_placement(
            payload(
                matches=matches,
                venues=[venue(windows=[{"dayOfWeek": 6, "start": "14:00", "end": "20:00"}])],
                teams=[team("t1"), team("t2", habits=[{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "v1"}])],
            )
        )

    def crosses_protected(kickoff: str) -> bool:
        start = int(kickoff[:2]) * 60 + int(kickoff[3:])
        return start < 17 * 60 + 15 and start + 105 > 15 * 60 + 30

    # No away match → the habit window is protected, m1 is repelled outside it.
    assert not crosses_protected(kickoff_of(run(False), "m1"))
    # Away match that day → protection lifted, m1's window may cross it.
    assert crosses_protected(kickoff_of(run(True), "m1"))


def test_shared_player_posed_at_once_is_penalised() -> None:
    # P4-240 ③ (décision A) — a PLAYER shared by two teams is a person: two HOME
    # matches of teams sharing an active player, posed at the same time, cost
    # W_COACH_MAIN (60). Falsified BOTH ways: without the shared player the greedy
    # packs both at the earliest slot (14:00, different venues, no venue clash);
    # WITH the shared player the person clash pushes their MATCH windows ≥ one match
    # (105 min) apart.
    wide = [{"dayOfWeek": 6, "start": "14:00", "end": "22:30"}]

    def run(shared: bool) -> dict[str, Any]:
        return solve_match_placement(
            payload(
                matches=[to_place("m1", "t1"), to_place("m2", "t2")],
                venues=[venue("v1", windows=wide), venue("v2", windows=wide)],
                teams=[
                    team("t1", players=["p1"]),
                    team("t2", players=["p1"] if shared else ["p2"]),
                ],
            )
        )

    minutes = lambda s: int(s[:2]) * 60 + int(s[3:])  # noqa: E731
    not_shared = run(False)
    assert minutes(kickoff_of(not_shared, "m1")) == minutes(kickoff_of(not_shared, "m2"))
    shared = run(True)
    assert abs(minutes(kickoff_of(shared, "m1")) - minutes(kickoff_of(shared, "m2"))) >= 105


def test_a_person_coaching_and_playing_the_same_team_is_counted_once() -> None:
    # P4-240 ③ (décision A) — the coach role wins on her own team: a person listed as
    # BOTH a coach and a player of the same team is ONE person, ONE malus. Even if a
    # payload defensively double-lists her, the solve is byte-identical to listing her
    # as a coach only (the engine drops the duplicate player, `_team_players`). Shared
    # MAIN coach c1 across two teams on two wide venues → separated ≥ 105 either way,
    # and the exact placements match.
    wide = [{"dayOfWeek": 6, "start": "14:00", "end": "22:30"}]
    base = {
        "matches": [to_place("m1", "t1"), to_place("m2", "t2")],
        "venues": [venue("v1", windows=wide), venue("v2", windows=wide)],
    }
    coach_only = solve_match_placement(
        payload(
            teams=[
                team("t1", coaches=[{"coachId": "c1", "role": "MAIN"}]),
                team("t2", coaches=[{"coachId": "c1", "role": "MAIN"}]),
            ],
            **base,
        )
    )
    also_player = solve_match_placement(
        payload(
            teams=[
                team("t1", coaches=[{"coachId": "c1", "role": "MAIN"}], players=["c1"]),
                team("t2", coaches=[{"coachId": "c1", "role": "MAIN"}], players=["c1"]),
            ],
            **base,
        )
    )
    assert coach_only["placements"] == also_player["placements"]


def test_fixed_match_finishing_after_midnight_still_places_the_others() -> None:
    # ENG-48 — a FIXED home match kicking off at 22:30 for 120 min ENDS at 00:30
    # (past midnight; the league may allow it, "no surprise"). The day-compaction
    # span/gap domains must reach that end, else `span_end >= 1470` is infeasible
    # against the old 24:00 cap and the WHOLE (venue, date) group unplaces — so a
    # perfectly placeable match beside it is left `not_selected`. After the horizon
    # fix the fixed anchor is absorbed and the free match is placed normally.
    result = solve_match_placement(
        payload(
            matches=[
                to_place("m1", "t1"),
                {"id": "fx", "teamId": "t2", "date": SATURDAY, "kind": "FIXED", "venueId": "v1", "kickoff": "22:30"},
            ],
            venues=[venue()],  # Saturday 14:00-18:00
            teams=[team("t1"), team("t2", matchMinutes=120)],
        )
    )
    assert result["unplaced"] == []
    assert kickoff_of(result, "m1") <= "16:15"


def test_unknown_solver_status_names_each_unplaced_match(monkeypatch: pytest.MonkeyPatch) -> None:
    # ENG-48 — the post-solve `else` branch is reachable on UNKNOWN (the budget ran
    # out before any proof). It must leave `placements=[]` and still name a reason
    # per match from the final occupancy, never a silent empty response.
    real_solve = cp_model.CpSolver.solve

    def fake_solve(self: cp_model.CpSolver, model: cp_model.CpModel) -> int:
        real_solve(self, model)
        return cp_model.UNKNOWN

    monkeypatch.setattr(cp_model.CpSolver, "solve", fake_solve)
    result = solve_match_placement(payload(matches=[to_place()], venues=[venue()], teams=[team()]))
    assert result["placements"] == []
    assert [item["reason"] for item in result["unplaced"]] == ["not_selected"]
