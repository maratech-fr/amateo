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
from app.schemas.match_input_schema import MatchPlacementInputSchema, MatchTeamSchema
from app.schemas.match_output_schema import MatchPlacementOutputSchema
from app.solver.match_placement import DEFAULT_MATCH_MIN, solve_match_placement

SATURDAY = "2026-10-03"


def _minutes(value: time) -> int:
    return value.hour * 60 + value.minute


def _match_min(teams: dict[str, MatchTeamSchema], team_id: str) -> int:
    team = teams.get(team_id)
    return team.match_minutes if team is not None else DEFAULT_MATCH_MIN


def assert_no_hard_violation(input_data: MatchPlacementInputSchema, output: MatchPlacementOutputSchema) -> None:
    """The HARD invariant: every placement sits in an access window (its MATCH
    fits — no warm-up reserved, D1), inside the league window when the team maps,
    on an available venue, and no two MATCH windows overlap in one (venue, date)."""
    matches = {m.id: m for m in input_data.matches}
    venues = {v.id: v for v in input_data.venues}
    teams = {t.id: t for t in input_data.teams}
    occupied: dict[tuple[str, date], list[tuple[int, int]]] = {}
    for match in input_data.matches:
        if match.kind == "FIXED" and match.venue_id is not None and match.kickoff is not None:
            kick = _minutes(match.kickoff)
            occupied.setdefault((match.venue_id, match.match_date), []).append(
                (kick, kick + _match_min(teams, match.team_id))
            )

    for placement in output.placements:
        match = matches[placement.match_id]
        venue = venues[placement.venue_id]
        kick = _minutes(placement.kickoff)
        day = match.match_date.isoweekday()
        m_min = _match_min(teams, match.team_id)

        assert not any(u.start_date <= match.match_date <= u.end_date for u in venue.unavailabilities), (
            f"{placement.match_id}: placed on an unavailable venue"
        )

        assert any(
            w.day_of_week == day and _minutes(w.start) <= kick and kick + m_min <= _minutes(w.end)
            for w in venue.match_windows
        ), f"{placement.match_id}: match outside every access window"

        league = teams[match.team_id].league_windows
        if league:
            assert any(
                w.day_of_week == day and _minutes(w.kickoff_min) <= kick <= _minutes(w.kickoff_max) for w in league
            ), f"{placement.match_id}: kickoff outside the league window"

        window = (kick, kick + m_min)
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
            # 3 TO_PLACE + 1 FIXED = 4 MATCH windows × 105 min = 420 ≤ the 570-min
            # window (13:00-22:30) — feasible (D1: the venue holds the match only).
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
    # NR sémantique du cadrage : fenêtre samedi 14:00-18:00, match 105 min →
    # AUCUN coup d'envoi hors 14:00-16:15, quelles que soient les préférences.
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
    assert time(14, 0) <= kick <= time(16, 15)
    assert_no_hard_violation(input_data, output)


def test_a_manual_anchor_is_never_moved_nor_double_booked() -> None:
    input_data = MatchPlacementInputSchema.model_validate(wire_payload())
    output = MatchPlacementOutputSchema.model_validate(solve_match_placement(input_data))
    teams = {t.id: t for t in input_data.teams}
    matches = {m.id: m for m in input_data.matches}

    # The FIXED match never appears in placements…
    assert all(p.match_id != "m-rm2" for p in output.placements)
    # …and no MATCH window overlaps its venue window (20:30 kickoff → 20:30-22:15,
    # match only, D1) on Mateo.
    anchor_start = 20 * 60 + 30
    anchor_end = 20 * 60 + 30 + _match_min(teams, matches["m-rm2"].team_id)
    for placement in output.placements:
        kick = _minutes(placement.kickoff)
        start, end = kick, kick + _match_min(teams, matches[placement.match_id].team_id)
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


def test_warmup_only_overlap_is_no_longer_penalised_lot_m() -> None:
    # NR sémantique lot M — l'échauffement sort de l'empreinte de PERSONNE : deux
    # matchs d'un MÊME coach dans le MÊME gymnase ne se pénalisent plus sur le seul
    # chevauchement d'échauffement. Le no-overlap de gymnase (HARD) force ≥ 105 min
    # d'écart ; la compaction de journée les rapproche. Avant lot M, l'empreinte
    # personne (avec échauffement) se recouvrait de 30 min en dos-à-dos → malus coach
    # 60 → le solveur les écartait de 135 min. Sans l'échauffement, le dos-à-dos exact
    # (105 min) ne recouvre plus rien → il est retenu. On MESURE cet écart de 105.
    payload: dict[str, Any] = {
        "version": read_contract_version(),
        "clubId": "club-bccl",
        "seasonId": "season-2026",
        "solverSeed": 42,
        "solverTimeoutSeconds": 30,
        "matches": [
            {"id": "m1", "teamId": "t1", "date": SATURDAY, "kind": "TO_PLACE"},
            {"id": "m2", "teamId": "t2", "date": SATURDAY, "kind": "TO_PLACE"},
        ],
        "venues": [
            {
                "id": "mateo",
                "name": "Mateo",
                "matchWindows": [{"dayOfWeek": 6, "start": "13:00", "end": "22:30"}],
                "unavailabilities": [],
            }
        ],
        "teams": [
            {
                "id": "t1",
                "name": "T1",
                "leagueWindows": [],
                "habits": [],
                "coaches": [{"coachId": "c", "role": "MAIN"}],
            },
            {
                "id": "t2",
                "name": "T2",
                "leagueWindows": [],
                "habits": [],
                "coaches": [{"coachId": "c", "role": "MAIN"}],
            },
        ],
        "teamLinks": [],
        "trainingOccupancies": [],
    }
    input_data = MatchPlacementInputSchema.model_validate(payload)
    output = MatchPlacementOutputSchema.model_validate(solve_match_placement(input_data))

    assert output.unplaced == []
    assert len(output.placements) == 2
    kicks = sorted(_minutes(p.kickoff) for p in output.placements)
    # Back-to-back EXACT (105 min) : le chevauchement d'échauffement ne coûte plus rien.
    assert kicks[1] - kicks[0] == 105, f"attendu dos-à-dos exact (105), obtenu {kicks[1] - kicks[0]} min"
    assert {p.venue_id for p in output.placements} == {"mateo"}
    assert_no_hard_violation(input_data, output)


def _real_overlap_payload(*, share_coach: bool) -> dict[str, Any]:
    """Un match à placer, attiré à 17:00 par une habitude, dont le coach porte une
    séance FIXE 18:00-19:45. Un match posé à 17:00 (17:00-18:45) recouvre RÉELLEMENT
    la séance → il est pénalisé. `share_coach=False` retire le lien de coach (témoin :
    plus aucune pénalité, l'habitude gagne)."""
    return {
        "version": read_contract_version(),
        "clubId": "club-bccl",
        "seasonId": "season-2026",
        "solverSeed": 42,
        "solverTimeoutSeconds": 30,
        "matches": [{"id": "m-a", "teamId": "a", "date": SATURDAY, "kind": "TO_PLACE"}],
        "venues": [
            {
                "id": "mateo",
                "name": "Mateo",
                "matchWindows": [{"dayOfWeek": 6, "start": "13:00", "end": "22:30"}],
                "unavailabilities": [],
            }
        ],
        "teams": [
            {
                "id": "a",
                "name": "A",
                "leagueWindows": [],
                "habits": [{"dayOfWeek": 6, "kickoff": "17:00", "venueId": "mateo"}],
                "coaches": [{"coachId": "c", "role": "MAIN"}] if share_coach else [],
            }
        ],
        "teamLinks": [],
        # La séance FIXE du coach c : sa fenêtre RÉELLE 18:00-19:45 (sans échauffement).
        "trainingOccupancies": [{"date": SATURDAY, "start": "18:00", "end": "19:45", "coachId": "c"}],
    }


def test_a_real_person_overlap_is_still_penalised_lot_m() -> None:
    # NR sémantique lot M (l'autre moitié) — un VRAI recouvrement reste pénalisé.
    # Avec le coach partagé, poser à 17:00 (le match 17:00-18:45 mord la séance
    # 18:00-19:45) coûte le malus coach 60, qui bat l'attrait d'habitude 20 → le
    # solveur DÉPLACE le match hors de son habitude, sur un créneau qui ne recouvre
    # plus la séance. TÉMOIN sans coach partagé : l'habitude 17:00 est honorée.
    with_coach = MatchPlacementOutputSchema.model_validate(
        solve_match_placement(MatchPlacementInputSchema.model_validate(_real_overlap_payload(share_coach=True)))
    )
    assert len(with_coach.placements) == 1
    chosen = _minutes(with_coach.placements[0].kickoff)
    assert chosen != _minutes(time(17, 0)), "un recouvrement réel doit repousser le match hors de son habitude"
    # Le créneau retenu ne recouvre plus la séance réelle 18:00-19:45 (fenêtre personne
    # sans échauffement = [kickoff, kickoff + 105]).
    assert chosen + DEFAULT_MATCH_MIN <= _minutes(time(18, 0)) or chosen >= _minutes(time(19, 45))

    witness = MatchPlacementOutputSchema.model_validate(
        solve_match_placement(MatchPlacementInputSchema.model_validate(_real_overlap_payload(share_coach=False)))
    )
    assert len(witness.placements) == 1
    assert _minutes(witness.placements[0].kickoff) == _minutes(time(17, 0)), (
        "sans coach partagé, rien ne pénalise l'habitude 17:00 — témoin cassé"
    )


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
