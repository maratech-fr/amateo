"""NR axe *constraint semantics* : « tant qu'un coach tient la séance, c'est OK » (fondateur 2026-10-30).

Seul, un coach principal est indispensable ; à trois, un seul suffit, comme pour les mutualisations.
Le moteur ne doit donc plus occuper TOUS les coachs principaux d'une équipe à chaque séance : chaque
séance a AU MOINS un coach qui la tient, et une personne ne tient qu'une séance à la fois, sauf dans
le même gymnase (D-14). ``isRequired`` n'est ni lu ni retiré.

Cas réel (coachs anonymisés) : U15 = A + B, U13 = A. Les deux séances simultanées dans deux gymnases
sont légitimes, B tient les U15 et A les U13.

Angle MOTEUR, à travers le vrai pipeline ``solve_payload``. Jumeaux VOULUS (point sensible solveur) :
``CrossStack/OneCoachHoldsTheSessionGateTest`` (HTTP contre le vrai moteur, le step qui gate) ;
``test_sovereign_reservations.py::test_the_only_coach_off_on_the_reserved_case_is_a_named_violation_with_the_reservation``
(un coach SEUL indisponible sur sa case réservée reste un écart nommé) ;
``test_shared_block_coach_relay.py`` (la disponibilité : « un coach suffit » existait déjà pour les
indisponibilités, ici il s'étend à l'occupation par une autre séance).
"""

from __future__ import annotations

from typing import Any

import pytest

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_coach

MONDAY = 1
THURSDAY = 4


def _coaches(*ids: str) -> list[dict[str, Any]]:
    return [{"id": coach_id, "firstName": "Coach", "lastName": coach_id, "isEmployee": False} for coach_id in ids]


def _links(**coaches_by_team: list[str]) -> list[dict[str, Any]]:
    return [
        team_coach(f"tc-{team_id}-{coach_id}", team_id, coach_id)
        for team_id, coach_ids in coaches_by_team.items()
        for coach_id in coach_ids
    ]


def _plays_in(team_id: str, person_id: str) -> dict[str, Any]:
    return {
        "id": f"cp-{team_id}-{person_id}",
        "type": "COACH_PLAYER_UNAVAILABILITY",
        "isActive": True,
        "metadata": {"teamId": team_id, "coachId": person_id},
    }


def _coach_off(rule_id: str, coach_id: str, day: int) -> dict[str, Any]:
    return {
        "id": rule_id,
        "scope": "COACH",
        "scopeTargetId": coach_id,
        "family": "COACH_AVAILABILITY",
        "ruleType": "HARD",
        "name": f"Indispo de {coach_id}",
        "config": {"unavailableDays": [day]},
        "sortOrder": 0,
        "isActive": True,
    }


def _lock(team_id: str, venue_id: str, day: int = MONDAY, start: str = "18:00") -> dict[str, Any]:
    return {
        "id": f"lock-{team_id}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }


def _simultaneous_gyms(*venue_ids: str, capacity: int = 1) -> list[dict[str, Any]]:
    """Des gymnases ouverts sur UNE seule case, la même : tout placer impose la simultanéité."""
    return [make_venue(venue_id, [(MONDAY, "18:00")], capacity=capacity) for venue_id in venue_ids]


def _solve(
    team_ids: list[str],
    venues: list[dict[str, Any]],
    constraints: list[dict[str, Any]],
    coach_ids: tuple[str, ...] = ("A", "B", "C"),
    locks: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    return solve_payload(
        make_payload(
            teams=[make_team(team_id, sessions_per_week=1) for team_id in team_ids],
            venues=venues,
            coaches=_coaches(*coach_ids),
            constraints=constraints,
            slot_templates=locks or [],
        )
    )


def _placed(result: dict[str, Any]) -> set[str]:
    return {str(slot["teamId"]) for slot in result["slots"]}


def _coach_of(result: dict[str, Any], team_id: str) -> Any:
    return next(slot["coachId"] for slot in result["slots"] if slot["teamId"] == team_id)


def _reservation_signals(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [d for d in result["diagnostics"] if d.get("type") in {"rule_sacrificed", "blocked_by_reservation"}]


# ── Un coach suffit, y compris quand l'autre est ailleurs ────────────────────────────────────────


def test_the_second_coach_holds_the_session_while_the_first_trains_elsewhere() -> None:
    """Le cas réel : U15 = A + B, U13 = A, deux gymnases, la seule case est simultanée."""
    result = _solve(["U15", "U13"], _simultaneous_gyms("V", "W"), _links(U15=["A", "B"], U13=["A"]))

    assert _placed(result) == {"U15", "U13"}, f"B tient les U15, A les U13 : {result['diagnostics']}"


def test_a_coach_alone_on_two_teams_still_cannot_be_in_two_gyms() -> None:
    """TÉMOIN : seul, le coach est indispensable. Sans lui, un moteur qui ne contraindrait plus
    personne passerait le test ci-dessus."""
    result = _solve(["U15", "U13"], _simultaneous_gyms("V", "W"), _links(U15=["A"], U13=["A"]))

    assert len(_placed(result)) == 1, f"A ne peut pas tenir deux gymnases : placés {_placed(result)}"


def test_three_simultaneous_sessions_cannot_be_held_by_two_coaches() -> None:
    """La tenue se compte : deux personnes ne tiennent pas trois séances dans trois gymnases, même si
    chaque paire d'équipes, prise seule, trouverait un coach."""
    result = _solve(
        ["T1", "T2", "T3"],
        _simultaneous_gyms("V", "W", "X"),
        _links(T1=["A", "B"], T2=["A", "B"], T3=["A", "B"]),
    )

    assert len(_placed(result)) == 2, f"deux coachs, deux séances au plus : placés {_placed(result)}"


def test_with_three_coaches_one_free_is_enough() -> None:
    """« À 3, un seul suffit » : A et B sont pris ailleurs, C tient T3."""
    result = _solve(
        ["T1", "T2", "T3"],
        _simultaneous_gyms("V", "W", "X"),
        _links(T1=["A"], T2=["B"], T3=["A", "B", "C"]),
    )

    assert _placed(result) == {"T1", "T2", "T3"}, f"C tient T3 : {result['diagnostics']}"


def test_one_coach_may_hold_two_sessions_in_the_same_gym() -> None:
    """D-14 conservée : le même gymnase n'est pas un dédoublement."""
    result = _solve(["U15", "U13"], _simultaneous_gyms("V", capacity=2), _links(U15=["A"], U13=["A"]))

    assert _placed(result) == {"U15", "U13"}


def test_a_team_without_any_coach_is_placed_without_a_holder() -> None:
    """Une équipe sans coach principal (Loisirs, Éveil) n'exige personne : Σ tenues ≥ x ne doit pas la
    fermer."""
    result = _solve(["Loisirs"], _simultaneous_gyms("V"), [])

    assert _placed(result) == {"Loisirs"}


def test_an_assistant_does_not_hold_a_session_and_is_not_occupied() -> None:
    """Un adjoint n'est ni une tenue ni une ressource exclusive : A principal de U13 et adjoint de U15
    ne fait pas obstacle, et les deux équipes sont placées."""
    constraints = [team_coach("tc-u13", "U13", "A"), team_coach("tc-u15", "U15", "A", role="ASSISTANT")]
    result = _solve(["U15", "U13"], _simultaneous_gyms("V", "W"), constraints)

    assert _placed(result) == {"U15", "U13"}


def test_is_required_is_not_read() -> None:
    """Décision fondateur : la règle vaut pour toutes les équipes, ``isRequired`` n'entre pas en jeu.
    Le même décor que le témoin « coach seul », lien marqué non obligatoire : A reste indispensable."""
    constraints = _links(U15=["A"], U13=["A"])
    for link in constraints:
        link["metadata"]["isRequired"] = False

    result = _solve(["U15", "U13"], _simultaneous_gyms("V", "W"), constraints)

    assert len(_placed(result)) == 1, f"isRequired=false ne libère pas le coach : placés {_placed(result)}"


# ── Le rôle de joueur occupe la personne ─────────────────────────────────────────────────────────


def test_a_coach_playing_elsewhere_leaves_the_session_to_the_other_coach() -> None:
    """A coache U18 avec B et joue en SM1 au même moment, ailleurs : B tient les U18."""
    constraints = [*_links(U18=["A", "B"]), _plays_in("SM1", "A")]
    result = _solve(["U18", "SM1"], _simultaneous_gyms("V", "W"), constraints)

    assert _placed(result) == {"U18", "SM1"}, f"B tient les U18 : {result['diagnostics']}"
    assert _coach_of(result, "U18") == "B", "A joue en SM1, il ne tient pas les U18"


def test_a_coach_playing_elsewhere_with_nobody_else_cannot_hold_the_session() -> None:
    """TÉMOIN : A seul coach de U18 joue ailleurs au même moment, la séance n'a personne."""
    constraints = [*_links(U18=["A"]), _plays_in("SM1", "A")]
    result = _solve(["U18", "SM1"], _simultaneous_gyms("V", "W"), constraints)

    assert len(_placed(result)) == 1, f"personne pour tenir les U18 : placés {_placed(result)}"


# ── Le planning nomme celui qui tient VRAIMENT la séance ─────────────────────────────────────────


def test_the_output_names_the_coach_who_really_holds_the_session() -> None:
    """Le ``coachId`` de sortie est écrit tel quel sur la séance (``ScheduleResultImporter``) et le
    radar le lit. S'il restait le PREMIER coach de la liste, la séance tenue par B serait attribuée à A,
    et le radar crierait au conflit juste après une génération correcte."""
    result = _solve(["U15", "U13"], _simultaneous_gyms("V", "W"), _links(U15=["A", "B"], U13=["A"]))

    assert _coach_of(result, "U13") == "A"
    assert _coach_of(result, "U15") == "B", "A est aux U13 : c'est B qui tient les U15"


# ── Un verrou consomme UN coach ──────────────────────────────────────────────────────────────────


def test_two_locks_sharing_a_coach_are_not_a_violation_when_the_other_coach_holds() -> None:
    """La réservation voulue du fondateur (« c'est la réalité ») : aucun écart annoncé, chacun son coach."""
    result = _solve(
        ["U15", "U13"],
        _simultaneous_gyms("V", "W"),
        _links(U15=["A", "B"], U13=["A"]),
        locks=[_lock("U15", "V"), _lock("U13", "W")],
    )

    assert result["status"] == "completed"
    assert _reservation_signals(result) == [], f"un autre coach tient la séance : {_reservation_signals(result)}"
    assert (_coach_of(result, "U15"), _coach_of(result, "U13")) == ("B", "A")


def test_two_locks_on_a_coach_alone_are_a_named_violation() -> None:
    """TÉMOIN : A seul sur deux réservations simultanées, la réservation gagne et l'écart est nommé."""
    result = _solve(
        ["U15", "U13"],
        _simultaneous_gyms("V", "W"),
        _links(U15=["A"], U13=["A"]),
        locks=[_lock("U15", "V"), _lock("U13", "W")],
    )

    assert result["status"] == "completed"
    assert _placed(result) == {"U15", "U13"}, "les deux réservations restent posées"
    assert [d["type"] for d in _reservation_signals(result)] == ["rule_sacrificed"], result["diagnostics"]


def test_a_lock_leaves_its_second_coach_free_for_another_team() -> None:
    """Le verrou de T1 (A + B) ne consomme qu'un coach : T2, seulement coachée par A, se place ailleurs
    sur la même heure, T1 étant tenue par B."""
    result = _solve(
        ["T1", "T2"],
        _simultaneous_gyms("V", "W"),
        _links(T1=["A", "B"], T2=["A"]),
        locks=[_lock("T1", "V")],
    )

    assert _placed(result) == {"T1", "T2"}, f"B tient T1, A est libre pour T2 : {result['diagnostics']}"


def test_two_locks_consume_both_coaches() -> None:
    """TÉMOIN de la borne : deux verrous tenus par A et B ne laissent personne à T3 au même moment. Un
    verrou qui ne consommerait AUCUN coach passerait le test précédent."""
    result = _solve(
        ["T1", "T2", "T3"],
        _simultaneous_gyms("V", "W", "X"),
        _links(T1=["A", "B"], T2=["A", "B"], T3=["A", "B"]),
        locks=[_lock("T1", "V"), _lock("T2", "W")],
    )

    assert _placed(result) == {"T1", "T2"}, f"plus personne pour T3 : placés {_placed(result)}"


def test_a_lock_with_a_second_coach_available_has_no_violation() -> None:
    """Interaction réservation souveraine : A est indisponible sur la case réservée, B la tient. Aucun
    écart, à la différence du coach SEUL (``test_sovereign_reservations.py``, cité en tête)."""
    result = _solve(
        ["T"],
        [make_venue("V", [(THURSDAY, "18:00")])],
        [*_links(T=["A", "B"]), _coach_off("off", "A", THURSDAY)],
        locks=[_lock("T", "V", day=THURSDAY)],
    )

    assert result["status"] == "completed"
    assert _reservation_signals(result) == [], f"B est disponible, rien à signaler : {_reservation_signals(result)}"
    assert _coach_of(result, "T") == "B", "A est indisponible : c'est B qui tient la séance"


def test_a_lock_whose_second_coach_is_taken_is_a_named_violation() -> None:
    """A indisponible, B pris par une autre réservation au même moment : T n'a plus personne, la
    réservation gagne et l'écart est nommé."""
    result = _solve(
        ["T", "U"],
        [make_venue("V", [(THURSDAY, "18:00")]), make_venue("W", [(THURSDAY, "18:00")])],
        [*_links(T=["A", "B"], U=["B"]), _coach_off("off", "A", THURSDAY)],
        locks=[_lock("T", "V", day=THURSDAY), _lock("U", "W", day=THURSDAY)],
    )

    assert result["status"] == "completed"
    assert _placed(result) == {"T", "U"}
    signals = _reservation_signals(result)
    assert signals, f"T n'a plus personne, l'écart doit être nommé : {result['diagnostics']}"
    assert {d["type"] for d in signals} == {"rule_sacrificed"}


# ── Parité génération ⇄ verdict de déplacement ───────────────────────────────────────────────────


def _verdict(u15_coaches: list[str]) -> dict[str, Any]:
    """Le verdict d'un geste NEUTRE (T3 vers une case libre) sur une base où U15 et U13 sont simultanées."""
    payload: dict[str, Any] = {
        "clubId": "club",
        "seasonId": "season",
        "venues": [
            make_venue("V", [(MONDAY, "18:00")]),
            make_venue("W", [(MONDAY, "18:00")]),
            make_venue("X", [(THURSDAY, "18:00")]),
        ],
        "teams": [make_team(team_id, sessions_per_week=1) for team_id in ("U15", "U13", "T3")],
        "coaches": _coaches("A", "B"),
        "constraints": _links(U15=u15_coaches, U13=["A"]),
        "slotTemplates": [
            {
                "id": "s-u15",
                "teamId": "U15",
                "venueId": "V",
                "dayOfWeek": MONDAY,
                "startTime": "18:00",
                "durationMinutes": 90,
            },
            {
                "id": "s-u13",
                "teamId": "U13",
                "venueId": "W",
                "dayOfWeek": MONDAY,
                "startTime": "18:00",
                "durationMinutes": 90,
            },
        ],
        "candidates": [
            {"teamId": "T3", "venueId": "X", "dayOfWeek": THURSDAY, "startTime": "18:00", "durationMinutes": 90}
        ],
    }

    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(payload))


def test_the_move_verdict_accepts_a_baseline_held_by_the_second_coach() -> None:
    """Un planning que ``/generate`` a produit doit rester acceptable pour le verdict d'un déplacement :
    la couche HARD partage la règle, sinon tout geste serait refusé sur ce planning."""
    result = _verdict(["A", "B"])

    assert result["valid"] is True, f"B tient les U15, la base est faisable : {result}"
    assert result.get("indeterminate") is not True


def test_the_move_verdict_still_refuses_a_baseline_with_a_coach_alone_in_two_gyms() -> None:
    """TÉMOIN : sans second coach, la même base reste infaisable pour le verdict."""
    assert _verdict(["A"])["valid"] is False


# ── Le verdict : un geste ne retire pas le dernier coach d'une séance fixée ──────────────────────────
#
# Décor commun : T est fixée sur V le lundi à 18 h. A entraîne U13, figée sur W à la même heure. Le geste
# amène M, dont B est le coach, sur X à la même heure. Elle venait du jeudi (référence).


def _move_onto_the_hour_of_a_lock(t_coaches: list[str], *, with_reference: bool) -> dict[str, Any]:
    """Le verdict du geste sur M. D, s'il entraîne T, est déjà pris par U9 : un écart antérieur au geste."""
    candidate = {"teamId": "M", "venueId": "X", "dayOfWeek": MONDAY, "startTime": "18:00", "durationMinutes": 90}
    reference = {"teamId": "M", "venueId": "Y", "dayOfWeek": THURSDAY, "startTime": "18:00", "durationMinutes": 90}
    frozen = [
        {
            "id": f"s-{team}",
            "teamId": team,
            "venueId": venue,
            "dayOfWeek": MONDAY,
            "startTime": "18:00",
            "durationMinutes": 90,
        }
        for team, venue in (("U13", "W"), ("U9", "Z"))
    ]
    payload: dict[str, Any] = {
        "clubId": "club",
        "seasonId": "season",
        "venues": [
            *_simultaneous_gyms("V", "W", "X", "Z"),
            make_venue("Y", [(THURSDAY, "18:00")]),
        ],
        "teams": [make_team(team_id, sessions_per_week=1) for team_id in ("T", "U13", "U9", "M")],
        "coaches": _coaches("A", "B", "C", "D"),
        "constraints": _links(T=t_coaches, U13=["A"], U9=["D"], M=["B"]),
        "slotTemplates": [_lock("T", "V"), *frozen],
        "candidates": [candidate],
        "references": [reference] if with_reference else [],
    }

    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(payload))


@pytest.mark.parametrize("with_reference", [True, False])
def test_taking_the_last_coach_of_a_fixed_session_is_refused_and_named(with_reference: bool) -> None:
    """A est pris, B tenait T : le geste qui prend B laisse T sans personne, le refus nomme la séance.
    Sans ce contrôle la tenue du verrou cède en silence et le verdict accepte."""
    result = _move_onto_the_hour_of_a_lock(["A", "B"], with_reference=with_reference)

    assert result["valid"] is False, result
    assert result.get("indeterminate") is not True
    [violation] = result["violations"]
    assert violation["rule"] == "session_without_coach"
    assert violation["team_id"] == "M"
    assert violation["conflicting_team_id"] == "T"
    assert (violation["venue_id"], violation["day_of_week"], violation["start_time"]) == ("V", MONDAY, "18:00")
    assert "dernier coach" in violation["message"]
    assert "séance fixée de T le lundi à 18:00" in violation["message"]


@pytest.mark.parametrize("with_reference", [True, False])
def test_a_second_free_coach_keeps_the_move_accepted(with_reference: bool) -> None:
    """TÉMOIN : même geste, C est libre pour tenir T, rien ne manque."""
    result = _move_onto_the_hour_of_a_lock(["A", "B", "C"], with_reference=with_reference)

    assert result["valid"] is True, result


@pytest.mark.parametrize("with_reference", [True, False])
def test_a_fixed_session_already_without_coach_never_blocks_a_move(with_reference: bool) -> None:
    """T n'a déjà personne avant le geste (A et D sont pris) : jamais de blocage sur un écart déjà là,
    même si le contrôle voit bien T sans tenue après le geste."""
    result = _move_onto_the_hour_of_a_lock(["A", "D"], with_reference=with_reference)

    assert result["valid"] is True, result


# ── La tenue d'un verrou cède : jamais d'INFEASIBLE muet (contre-revue, décision ADR-0001 amendée) ──
#
# Les deux coachs de l'équipe A+B sont déjà au plafond par leurs PROPRES réservations : qui que ce soit
# qui tienne la réservation de A+B, une règle dure tombe. La réservation gagne : planning produit, écart
# nommé. Ces décors comptent des verrous SEULS, ils ne peuvent donc jamais justifier un ``failed``.


def _assert_produced_and_named(result: dict[str, Any]) -> None:
    assert result["status"] == "completed", f"des verrous seuls n'échouent jamais : {result['diagnostics']}"
    signals = _reservation_signals(result)
    assert signals, f"la règle tombée doit être nommée : {result['diagnostics']}"
    assert {d["type"] for d in signals} == {"rule_sacrificed"}


def _lock_on(team_id: str, venue_id: str, day: int, start: str = "18:00") -> dict[str, Any]:
    return {**_lock(team_id, venue_id, day=day, start=start), "id": f"lock-{team_id}-{day}-{start}"}


def test_a_lock_whose_coaches_both_reached_their_rest_cap_is_named_not_infeasible() -> None:
    """A et B réservés seuls du lundi au jeudi, A+B réservée le vendredi, repos coach dur."""
    days = [1, 2, 3, 4, 5]
    result = solve_payload(
        make_payload(
            teams=[
                make_team("TA", sessions_per_week=4),
                make_team("TB", sessions_per_week=4),
                make_team("AB", sessions_per_week=1),
            ],
            venues=[make_venue(v, [(d, "18:00") for d in days]) for v in ("V", "W", "X")],
            coaches=_coaches("A", "B"),
            constraints=_links(TA=["A"], TB=["B"], AB=["A", "B"]),
            slot_templates=[
                *[_lock_on("TA", "V", d) for d in days[:4]],
                *[_lock_on("TB", "W", d) for d in days[:4]],
                _lock_on("AB", "X", 5),
            ],
            implicit_rules={"coachRestDay": {"intensity": "HARD"}},
        )
    )

    _assert_produced_and_named(result)
    assert "AB" in _placed(result), "la réservation reste posée"


def test_a_lock_whose_coaches_cannot_arrive_in_time_is_named_not_infeasible() -> None:
    """Trajet obligatoire : A et B finissent ailleurs à 20:00, A+B réservée à 20:00 dans un autre gymnase."""
    payload = make_payload(
        teams=[make_team(t, sessions_per_week=1) for t in ("TA", "TB", "AB")],
        venues=[make_venue("V1", [(1, "18:30")]), make_venue("V3", [(1, "18:30")]), make_venue("V2", [(1, "20:00")])],
        coaches=_coaches("A", "B"),
        constraints=_links(TA=["A"], TB=["B"], AB=["A", "B"]),
        slot_templates=[
            _lock_on("TA", "V1", 1, "18:30"),
            _lock_on("TB", "V3", 1, "18:30"),
            _lock_on("AB", "V2", 1, "20:00"),
        ],
        implicit_rules={"travelTime": {"intensity": "MANDATORY", "toleranceMinutes": 0}},
    )
    payload["venueTravelTimes"] = [
        {"venueAId": "V1", "venueBId": "V2", "drivingMinutes": 30, "walkingMinutes": 30},
        {"venueAId": "V2", "venueBId": "V3", "drivingMinutes": 30, "walkingMinutes": 30},
    ]

    _assert_produced_and_named(solve_payload(payload))


def test_a_lock_that_would_extend_both_coaches_chains_is_named_not_infeasible() -> None:
    """Enchaînement dur : A et B ont chacun deux réservations de suite, A+B réservée juste après."""
    starts = ["17:00", "18:30"]
    result = solve_payload(
        make_payload(
            teams=[
                make_team("TA1", sessions_per_week=1),
                make_team("TA2", sessions_per_week=1),
                make_team("TB1", sessions_per_week=1),
                make_team("TB2", sessions_per_week=1),
                make_team("AB", sessions_per_week=1),
            ],
            venues=[
                make_venue("V", [(1, s) for s in starts]),
                make_venue("W", [(1, s) for s in starts]),
                make_venue("X", [(1, "20:00")]),
            ],
            coaches=_coaches("A", "B"),
            constraints=_links(TA1=["A"], TA2=["A"], TB1=["B"], TB2=["B"], AB=["A", "B"]),
            slot_templates=[
                _lock_on("TA1", "V", 1, "17:00"),
                _lock_on("TA2", "V", 1, "18:30"),
                _lock_on("TB1", "W", 1, "17:00"),
                _lock_on("TB2", "W", 1, "18:30"),
                _lock_on("AB", "X", 1, "20:00"),
            ],
            implicit_rules={"maxConsecutiveSessions": {"intensity": "HARD", "maxConsecutive": 3}},
        )
    )

    _assert_produced_and_named(result)


# ── Trajets et plafonds durs : ils ne valent que pour le coach qui tient ─────────────────────────


def _rest_decor(ab_coaches: list[str]) -> dict[str, Any]:
    """A réservé seul du lundi au jeudi ; AB, libre, n'a de case que le vendredi."""
    return make_payload(
        teams=[make_team("TA", sessions_per_week=4), make_team("AB", sessions_per_week=1)],
        venues=[make_venue("V", [(d, "18:00") for d in (1, 2, 3, 4)]), make_venue("X", [(5, "18:00")])],
        coaches=_coaches("A", "B"),
        constraints=_links(TA=["A"], AB=ab_coaches),
        slot_templates=[_lock_on("TA", "V", d) for d in (1, 2, 3, 4)],
        implicit_rules={"coachRestDay": {"intensity": "HARD"}},
    )


def test_the_rest_cap_binds_only_the_coach_who_holds() -> None:
    result = solve_payload(_rest_decor(["A", "B"]))

    assert "AB" in _placed(result), f"B tient AB, A garde son repos : {result['diagnostics']}"
    assert _coach_of(result, "AB") == "B"


def test_the_rest_cap_still_binds_a_coach_alone() -> None:
    """TÉMOIN : sans B, le vendredi priverait A de son repos."""
    assert "AB" not in _placed(solve_payload(_rest_decor(["A"])))


def _travel_decor(ab_coaches: list[str]) -> dict[str, Any]:
    payload = make_payload(
        teams=[make_team("TA", sessions_per_week=1), make_team("AB", sessions_per_week=1)],
        venues=[make_venue("V1", [(1, "18:30")]), make_venue("V2", [(1, "20:00")])],
        coaches=_coaches("A", "B"),
        constraints=_links(TA=["A"], AB=ab_coaches),
        slot_templates=[_lock_on("TA", "V1", 1, "18:30")],
        implicit_rules={"travelTime": {"intensity": "MANDATORY", "toleranceMinutes": 0}},
    )
    payload["venueTravelTimes"] = [{"venueAId": "V1", "venueBId": "V2", "drivingMinutes": 30, "walkingMinutes": 30}]
    return payload


def test_the_mandatory_travel_binds_only_the_coach_who_holds() -> None:
    result = solve_payload(_travel_decor(["A", "B"]))

    assert "AB" in _placed(result), f"B tient AB, A n'a pas à traverser : {result['diagnostics']}"
    assert _coach_of(result, "AB") == "B"


def test_the_mandatory_travel_still_binds_a_coach_alone() -> None:
    """TÉMOIN : A seul ne peut pas enchaîner sans battement."""
    assert "AB" not in _placed(solve_payload(_travel_decor(["A"])))


def _chain_decor(ab_coaches: list[str]) -> dict[str, Any]:
    return make_payload(
        teams=[make_team(t, sessions_per_week=1) for t in ("T1", "T2", "AB")],
        venues=[make_venue("V", [(1, "17:00"), (1, "18:30"), (1, "20:00")])],
        coaches=_coaches("A", "B"),
        constraints=_links(T1=["A"], T2=["A"], AB=ab_coaches),
        slot_templates=[_lock_on("T1", "V", 1, "17:00"), _lock_on("T2", "V", 1, "18:30")],
        implicit_rules={"maxConsecutiveSessions": {"intensity": "HARD", "maxConsecutive": 3}},
    )


def test_the_hard_chain_binds_only_the_coach_who_holds() -> None:
    result = solve_payload(_chain_decor(["A", "B"]))

    assert "AB" in _placed(result), f"B tient AB, A n'enchaîne pas trois séances : {result['diagnostics']}"
    assert _coach_of(result, "AB") == "B"


def test_the_hard_chain_still_binds_a_coach_alone() -> None:
    """TÉMOIN : A seul enchaînerait trois séances."""
    assert "AB" not in _placed(solve_payload(_chain_decor(["A"])))


# ── Même case : seul un groupe DÉCLARÉ prête ses coachs (décision fondateur) ─────────────────────


def _same_case_locks(*, declared_group: bool) -> dict[str, Any]:
    """T1 (A) et T2 (B) réservées sur la même case, T3 (A) ailleurs au même moment."""
    payload = make_payload(
        teams=[make_team(t, sessions_per_week=1) for t in ("T1", "T2", "T3")],
        venues=[make_venue("V", [(MONDAY, "19:00")], capacity=2), make_venue("W", [(MONDAY, "19:00")])],
        coaches=_coaches("A", "B"),
        constraints=_links(T1=["A"], T2=["B"], T3=["A"]),
        slot_templates=[
            _lock_on("T1", "V", MONDAY, "19:00"),
            _lock_on("T2", "V", MONDAY, "19:00"),
            _lock_on("T3", "W", MONDAY, "19:00"),
        ],
    )
    if declared_group:
        payload["sharedBlocks"] = [{"id": "g", "teamIds": ["T1", "T2"], "commonSessions": 1}]
    return payload


def test_the_same_case_without_a_group_lends_no_coach() -> None:
    """A est à deux endroits : l'écart est nommé, B ne tient pas T1 par simple coïncidence de case."""
    _assert_produced_and_named(solve_payload(_same_case_locks(declared_group=False)))


def test_a_declared_group_lends_its_coaches_even_on_locks() -> None:
    """Vrai groupe : B tient la séance commune, A est libre pour T3. Rien à signaler."""
    result = solve_payload(_same_case_locks(declared_group=True))

    assert result["status"] == "completed"
    assert _reservation_signals(result) == [], f"B tient le groupe : {_reservation_signals(result)}"
    assert _coach_of(result, "T3") == "A"
    assert _coach_of(result, "T1") == "B", "A est à T3 : le groupe est tenu par B, et la sortie le dit"


# ── Le coach stocké est stable : le tenant imposé, sinon le premier de l'équipe ──────────────────


def _free_team(coaches: list[str], seed: int = 42) -> dict[str, Any]:
    return make_payload(
        teams=[make_team("T", sessions_per_week=1)],
        venues=[make_venue("V", [(MONDAY, "18:00")])],
        coaches=_coaches("A", "B"),
        constraints=_links(T=coaches),
        seed=seed,
    )


def test_a_free_choice_stores_the_first_coach_of_the_team() -> None:
    """Rien n'impose B : le coach stocké reste le premier de l'équipe, jamais un choix du solveur."""
    assert _coach_of(solve_payload(_free_team(["A", "B"])), "T") == "A"
    assert _coach_of(solve_payload(_free_team(["B", "A"])), "T") == "B"


def test_the_stored_coach_does_not_depend_on_the_seed() -> None:
    assert {_coach_of(solve_payload(_free_team(["A", "B"], seed=seed)), "T") for seed in (1, 2, 3, 42, 99)} == {"A"}


def _stable_decor() -> dict[str, Any]:
    """Un club à co-coachs, libre : assez de séances pour qu'un choix de solveur puisse varier."""
    teams = [f"T{i}" for i in range(8)]
    return make_payload(
        teams=[make_team(t, sessions_per_week=2) for t in teams],
        venues=[make_venue(v, [(d, s) for d in (1, 2, 3, 4) for s in ("18:00", "19:30")]) for v in ("V", "W")],
        coaches=_coaches("A", "B", "C"),
        constraints=_links(**{t: (["A", "B"] if i % 2 else ["B", "C"]) for i, t in enumerate(teams)}),
    )


def _coach_by_team_and_case(result: dict[str, Any]) -> dict[tuple[str, int, str], Any]:
    return {(s["teamId"], s["dayOfWeek"], s["startTime"]): s["coachId"] for s in result["slots"]}


def test_two_solves_of_the_same_payload_store_the_same_coaches() -> None:
    assert _coach_by_team_and_case(solve_payload(_stable_decor())) == _coach_by_team_and_case(
        solve_payload(_stable_decor())
    )


def test_the_stored_coach_is_the_first_unless_imposed_with_eight_workers(monkeypatch: pytest.MonkeyPatch) -> None:
    """Le parallélisme change la solution, jamais la règle : hors tenue imposée, le premier de l'équipe.
    Dans ce décor sans verrou, un coach pris ailleurs peut imposer le second : la vérification porte
    donc sur chaque séance dont le premier coach n'a aucune autre séance qui la chevauche."""
    monkeypatch.setattr("app.main._adaptive_workers", lambda _teams, _venues: 8)
    payload = _stable_decor()
    result = solve_payload(payload)
    first_by_team = {t["id"]: ("A" if int(t["id"][1:]) % 2 else "B") for t in payload["teams"]}

    for slot in result["slots"]:
        first = first_by_team[slot["teamId"]]
        same_time_elsewhere = [
            s
            for s in result["slots"]
            if s is not slot
            and (s["dayOfWeek"], s["startTime"]) == (slot["dayOfWeek"], slot["startTime"])
            and s["venueId"] != slot["venueId"]
        ]
        if not any(s["coachId"] == first for s in same_time_elsewhere):
            assert slot["coachId"] == first, f"rien n'impose un autre coach : {slot}"


# ── Le filet post-solve ne crie pas sur une séance de bloc tenue ailleurs ────────────────────────


@pytest.mark.parametrize("third_team_locked", [True, False])
def test_a_block_member_whose_coach_is_elsewhere_raises_no_false_alarm(third_team_locked: bool) -> None:
    """Bloc {t1 (A), t2 (B)}, t1 réservée, B ailleurs avec T3 : A tient la séance commune. Le membre
    libre t2 ne doit pas ressortir au nom de B (« affecté à plusieurs équipes » à tort)."""
    payload = make_payload(
        teams=[make_team(t, sessions_per_week=1) for t in ("t1", "t2", "T3")],
        venues=[make_venue("V", [(MONDAY, "18:00")], capacity=2), make_venue("W", [(MONDAY, "18:00")])],
        coaches=_coaches("A", "B"),
        constraints=_links(t1=["A"], t2=["B"], T3=["B"]),
        slot_templates=[_lock_on("t1", "V", MONDAY), *([_lock_on("T3", "W", MONDAY)] if third_team_locked else [])],
    )
    payload["sharedBlocks"] = [{"id": "g", "teamIds": ["t1", "t2"], "commonSessions": 1}]

    result = solve_payload(payload)

    assert _placed(result) == {"t1", "t2", "T3"}, result["diagnostics"]
    false_alarms = [d for d in result["diagnostics"] if "plusieurs équipes" in str(d.get("message"))]
    assert false_alarms == [], f"B est légitimement à T3, A tient le groupe : {false_alarms}"
    assert _coach_of(result, "t2") != "B", "B est à T3 : la séance commune n'est pas à son nom"


# ── Salarié : la présence comptée est celle que le planning affiche ──────────────────────────────


def test_a_salaried_presence_required_by_a_hard_rule_is_the_coach_shown() -> None:
    """Un salarié est second coach de chaque équipe, et la règle dure exige un salarié chaque soir :
    il ne peut être compté présent sans ressortir comme coach de la séance (présence fantôme)."""
    days = (1, 2, 3, 4, 5)
    teams = [f"D{d}" for d in days]
    coaches = [
        {"id": "X", "firstName": "Coach", "lastName": "X", "isEmployee": False},
        {"id": "S1", "firstName": "Coach", "lastName": "S1", "isEmployee": True},
        {"id": "S2", "firstName": "Coach", "lastName": "S2", "isEmployee": True},
    ]
    result = solve_payload(
        make_payload(
            teams=[make_team(t, sessions_per_week=1) for t in teams],
            venues=[make_venue("V", [(d, "18:00") for d in days])],
            coaches=coaches,
            # Le vendredi passe par S2 : S1 garde son jour de repos.
            constraints=_links(**{t: ["X", "S2" if t == "D5" else "S1"] for t in teams}),
            implicit_rules={
                "salarieDistribution": {"intensity": "HARD"},
                "coachRestDay": {"intensity": "HARD"},
            },
        )
    )

    assert result["status"] == "completed", result["diagnostics"]
    shown = {s["dayOfWeek"] for s in result["slots"] if s["coachId"] in {"S1", "S2"}}
    placed_days = {s["dayOfWeek"] for s in result["slots"]}
    assert shown == placed_days, f"chaque soir compté doit afficher son salarié : {result['slots']}"
