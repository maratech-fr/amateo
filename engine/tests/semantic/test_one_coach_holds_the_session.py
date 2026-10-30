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
