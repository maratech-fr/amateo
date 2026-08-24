"""NR constraint-semantics of the match placement (axe §7.1) — a constraint the
manager ENTERED must be honoured by the output, checked through the REAL wire
schemas (camelCase payloads exactly as the backend sends them).

The invariant checker is the last line: NO output placement may ever violate a
HARD rule, whatever the SOFT terms say (ADR-0003 × ADR-0001 — nothing is
relaxed, the impossible is named in `unplaced`).
"""

from __future__ import annotations

from datetime import date, time, timedelta
from typing import Any

from app.main import read_contract_version
from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.schemas.match_output_schema import MatchPlacementOutputSchema
from app.solver.match_placement import AFTER_KICKOFF_MIN, BEFORE_KICKOFF_MIN, solve_match_placement

SATURDAY = "2026-10-03"


def _minutes(value: time) -> int:
    return value.hour * 60 + value.minute


def assert_no_hard_violation(input_data: MatchPlacementInputSchema, output: MatchPlacementOutputSchema) -> None:
    """The HARD invariant: every placement sits in an access window, inside the
    league window when the team maps, on an available venue, and no two
    footprints overlap in one (venue, date)."""
    matches = {m.id: m for m in input_data.matches}
    venues = {v.id: v for v in input_data.venues}
    teams = {t.id: t for t in input_data.teams}
    occupied: dict[tuple[str, date], list[tuple[int, int]]] = {}
    for match in input_data.matches:
        if match.kind == "FIXED" and match.venue_id is not None and match.kickoff is not None:
            kick = _minutes(match.kickoff)
            occupied.setdefault((match.venue_id, match.match_date), []).append(
                (kick - BEFORE_KICKOFF_MIN, kick + AFTER_KICKOFF_MIN)
            )

    for placement in output.placements:
        match = matches[placement.match_id]
        venue = venues[placement.venue_id]
        kick = _minutes(placement.kickoff)
        day = match.match_date.isoweekday()

        assert not any(u.start_date <= match.match_date <= u.end_date for u in venue.unavailabilities), (
            f"{placement.match_id}: placed on an unavailable venue"
        )

        assert any(
            w.day_of_week == day
            and _minutes(w.start) + BEFORE_KICKOFF_MIN <= kick
            and kick + AFTER_KICKOFF_MIN <= _minutes(w.end)
            for w in venue.match_windows
        ), f"{placement.match_id}: footprint outside every access window"

        league = teams[match.team_id].league_windows
        if league:
            assert any(
                w.day_of_week == day and _minutes(w.kickoff_min) <= kick <= _minutes(w.kickoff_max) for w in league
            ), f"{placement.match_id}: kickoff outside the league window"

        window = (kick - BEFORE_KICKOFF_MIN, kick + AFTER_KICKOFF_MIN)
        for other in occupied.get((placement.venue_id, match.match_date), []):
            assert not (window[0] < other[1] and other[0] < window[1]), f"{placement.match_id}: venue overlap"
        occupied.setdefault((placement.venue_id, match.match_date), []).append(window)


def wire_payload() -> dict[str, Any]:
    """A realistic club weekend, in WIRE form (camelCase — the backend's exact
    shape): 4 home matches to place, 1 manual anchor, 1 away, habits, a link,
    a MAIN-coach training, an unavailability and a league envelope."""
    return {
        # Version DÉRIVÉE de la source de vérité (engine/CONTRACT_VERSION).
        "version": read_contract_version(),
        "clubId": "club-bccl",
        "seasonId": "season-2026",
        "solverSeed": 42,
        "solverTimeoutSeconds": 30,
        "matches": [
            # 3 TO_PLACE + 1 FIXED = 4 footprints × 135 min = 540 ≤ the 570-min
            # window (13:00-22:30) — full but feasible; a 5th would overflow.
            {"id": "m-pnm", "teamId": "pnm", "date": SATURDAY, "kind": "TO_PLACE"},
            {"id": "m-sf1", "teamId": "sf1", "date": SATURDAY, "kind": "TO_PLACE"},
            {"id": "m-df2", "teamId": "df2", "date": SATURDAY, "kind": "TO_PLACE"},
            {"id": "m-rm2", "teamId": "rm2", "date": SATURDAY, "kind": "FIXED", "venueId": "mateo", "kickoff": "20:30"},
            {"id": "m-rf3", "teamId": "rf3", "date": SATURDAY, "kind": "AWAY", "kickoff": "15:30"},
        ],
        "venues": [
            {
                "id": "mateo",
                "name": "Mateo",
                "matchWindows": [{"dayOfWeek": 6, "start": "13:00", "end": "22:30"}],
                "unavailabilities": [],
            },
            {
                "id": "armand",
                "name": "Armand",
                "matchWindows": [{"dayOfWeek": 6, "start": "13:00", "end": "22:30"}],
                "unavailabilities": [{"startDate": "2026-10-01", "endDate": "2026-10-05"}],
            },
        ],
        "teams": [
            {
                "id": "pnm",
                "name": "PNM",
                "leagueWindows": [{"dayOfWeek": 6, "kickoffMin": "15:30", "kickoffMax": "21:00"}],
                "habits": [{"dayOfWeek": 6, "kickoff": "15:30", "venueId": "mateo"}],
                "coaches": [{"coachId": "emerick", "role": "MAIN"}],
            },
            {
                "id": "sf1",
                "name": "SF1",
                "leagueWindows": [],
                "habits": [{"dayOfWeek": 6, "kickoff": "20:30", "venueId": "mateo"}],
                "coaches": [],
            },
            {"id": "df2", "name": "DF2", "leagueWindows": [], "habits": [], "coaches": []},
            {
                "id": "u13",
                "name": "U13",
                "leagueWindows": [{"dayOfWeek": 6, "kickoffMin": "13:00", "kickoffMax": "18:00"}],
                "habits": [],
                "coaches": [],
            },
            {"id": "rm2", "name": "RM2", "leagueWindows": [], "habits": [], "coaches": []},
            {
                "id": "rf3",
                "name": "RF3",
                "leagueWindows": [],
                "habits": [],
                "coaches": [{"coachId": "emerick", "role": "MAIN"}],
            },
        ],
        "teamLinks": [{"teamAId": "pnm", "teamBId": "sf1", "type": "BACK_TO_BACK"}],
        "trainingOccupancies": [{"date": SATURDAY, "start": "13:00", "end": "14:30", "coachId": "emerick"}],
    }


def test_realistic_weekend_honours_every_hard_rule() -> None:
    input_data = MatchPlacementInputSchema.model_validate(wire_payload())
    output = MatchPlacementOutputSchema.model_validate(solve_match_placement(input_data))

    assert output.status == "completed"
    # Armand is closed → everything lands on Mateo; the 13:00-22:30 window
    # holds the 3 placements + the FIXED anchor without overlap.
    assert {p.venue_id for p in output.placements} == {"mateo"}
    assert len(output.placements) == 3
    assert output.unplaced == []
    assert_no_hard_violation(input_data, output)


def test_access_window_is_hard_no_kickoff_ever_leaks_out() -> None:
    # NR sémantique du cadrage : fenêtre samedi 14:00-18:00 → AUCUN coup
    # d'envoi hors 14:30-16:15, quelles que soient les préférences.
    payload = wire_payload()
    payload["venues"] = [
        {
            "id": "mateo",
            "name": "Mateo",
            "matchWindows": [{"dayOfWeek": 6, "start": "14:00", "end": "18:00"}],
            "unavailabilities": [],
        }
    ]
    payload["matches"] = [m for m in payload["matches"] if m["kind"] == "TO_PLACE"][:1]
    # A habit OUTSIDE the window (20:30) must not drag the kickoff out.
    payload["teams"] = [
        {
            "id": "pnm",
            "name": "PNM",
            "leagueWindows": [],
            "habits": [{"dayOfWeek": 6, "kickoff": "20:30", "venueId": "mateo"}],
            "coaches": [],
        }
    ]
    payload["teamLinks"] = []
    payload["trainingOccupancies"] = []

    input_data = MatchPlacementInputSchema.model_validate(payload)
    output = MatchPlacementOutputSchema.model_validate(solve_match_placement(input_data))

    assert len(output.placements) == 1
    kick = output.placements[0].kickoff
    assert time(14, 30) <= kick <= time(16, 15)
    assert_no_hard_violation(input_data, output)


def test_a_manual_anchor_is_never_moved_nor_double_booked() -> None:
    input_data = MatchPlacementInputSchema.model_validate(wire_payload())
    output = MatchPlacementOutputSchema.model_validate(solve_match_placement(input_data))

    # The FIXED match never appears in placements…
    assert all(p.match_id != "m-rm2" for p in output.placements)
    # …and nothing overlaps its footprint (20:30 kickoff → 20:00-22:15) on Mateo.
    anchor_start = 20 * 60 + 30 - BEFORE_KICKOFF_MIN
    anchor_end = 20 * 60 + 30 + AFTER_KICKOFF_MIN
    for placement in output.placements:
        kick = _minutes(placement.kickoff)
        start, end = kick - BEFORE_KICKOFF_MIN, kick + AFTER_KICKOFF_MIN
        assert not (start < anchor_end and anchor_start < end), f"{placement.match_id} overlaps the manual anchor"


def test_ab_rotation_image_is_honoured_across_two_weekends() -> None:
    # RMM-5 constraint-semantics (§7.1): the SM1/SM2 shared slot (Mateo, Saturday
    # 20:30). Two federal weekends: week A only SM1 receives, week B only SM2 —
    # the alternation of the model. Each member's HOME match must land ON the
    # slot (day + hour + venue), and no HARD rule is ever violated.
    next_saturday = (date.fromisoformat(SATURDAY) + timedelta(days=7)).isoformat()
    payload: dict[str, Any] = {
        "version": read_contract_version(),
        "clubId": "club-bccl",
        "seasonId": "season-2026",
        "solverSeed": 42,
        "solverTimeoutSeconds": 30,
        "matches": [
            {"id": "m-sm1", "teamId": "sm1", "date": SATURDAY, "kind": "TO_PLACE"},
            {"id": "m-sm2", "teamId": "sm2", "date": next_saturday, "kind": "TO_PLACE"},
        ],
        "venues": [
            {
                "id": "mateo",
                "name": "Mateo",
                "matchWindows": [{"dayOfWeek": 6, "start": "13:00", "end": "22:30"}],
                "unavailabilities": [],
            },
            {
                "id": "armand",
                "name": "Armand",
                "matchWindows": [{"dayOfWeek": 6, "start": "13:00", "end": "22:30"}],
                "unavailabilities": [],
            },
        ],
        "teams": [
            {"id": "sm1", "name": "SM1", "leagueWindows": [], "habits": [], "coaches": []},
            {"id": "sm2", "name": "SM2", "leagueWindows": [], "habits": [], "coaches": []},
        ],
        "teamLinks": [],
        "slotRotations": [{"venueId": "mateo", "dayOfWeek": 6, "kickoff": "20:30", "teamIds": ["sm1", "sm2"]}],
        "trainingOccupancies": [],
    }
    input_data = MatchPlacementInputSchema.model_validate(payload)
    output = MatchPlacementOutputSchema.model_validate(solve_match_placement(input_data))

    assert output.unplaced == []
    placed = {p.match_id: (p.venue_id, p.kickoff) for p in output.placements}
    # Each member receives ON the shared slot on its own weekend.
    assert placed == {
        "m-sm1": ("mateo", time(20, 30)),
        "m-sm2": ("mateo", time(20, 30)),
    }
    assert_no_hard_violation(input_data, output)


def test_horizon_spans_weeks_and_stays_consistent() -> None:
    # Two successive Saturdays solve in ONE call (the whole known horizon).
    payload = wire_payload()
    next_saturday = (date.fromisoformat(SATURDAY) + timedelta(days=7)).isoformat()
    payload["matches"] = [
        {"id": "w1", "teamId": "pnm", "date": SATURDAY, "kind": "TO_PLACE"},
        {"id": "w2", "teamId": "pnm", "date": next_saturday, "kind": "TO_PLACE"},
    ]
    payload["teamLinks"] = []
    payload["trainingOccupancies"] = []
    input_data = MatchPlacementInputSchema.model_validate(payload)
    output = MatchPlacementOutputSchema.model_validate(solve_match_placement(input_data))

    assert len(output.placements) == 2
    # The habit (15:30) attracts BOTH weeks — the semaine type holds across weeks.
    assert {p.kickoff for p in output.placements} == {time(15, 30)}
    assert_no_hard_violation(input_data, output)
