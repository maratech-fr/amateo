"""La présence d'un salarié chaque jour où le club tourne est un OBJECTIF, jamais un blocage.

Axe §7.1 *constraint semantics* (décision fondateur 2026-10-31). Le cran saisi est sans effet pour
cette règle : un jour sans salarié donne un planning et une alerte qui énonce le fait. Le solveur
continue pourtant de viser un salarié chaque jour, et un jour où le club n'a aucun créneau ne compte
pas. Le jumeau sur un club réel entièrement réservé : ``test_fully_reserved_grid_two_employees.py``.
"""

from __future__ import annotations

from typing import Any

import pytest

from app.solver.compromise import CompromiseTermInfo
from app.solver.constraints import add_salarie_distribution_constraints
from app.solver.model import build_model
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_coach, team_constraint

INTENSITIES = ["HARD", "PREFERRED"]


def _coach(coach_id: str, *, employee: bool = False) -> dict[str, Any]:
    return {"id": coach_id, "firstName": coach_id, "lastName": "X", "isEmployee": employee}


def _one_team_a_day(
    coach_by_day: dict[int, str], *, intensity: str, grid_days: list[int] | None = None
) -> dict[str, Any]:
    """Une équipe par jour, son jour imposé, son coach donné. La grille couvre ``grid_days``."""
    days = sorted(coach_by_day)
    constraints: list[dict[str, Any]] = []
    for day in days:
        constraints.append(team_coach(f"tc-{day}", f"t-{day}", coach_by_day[day]))
        constraints.append(
            team_constraint(
                constraint_id=f"day-{day}",
                team_id=f"t-{day}",
                family="DAY",
                rule_type="HARD",
                config={"forcedDays": [day]},
                name=f"Jour {day} imposé",
            )
        )
    return make_payload(
        teams=[make_team(f"t-{day}") for day in days],
        venues=[make_venue("v", [(day, "18:00") for day in (grid_days or days)], duration_minutes=60)],
        coaches=[_coach("e1", employee=True), _coach("e2", employee=True), _coach("vol")],
        constraints=constraints,
        implicit_rules={"salarieDistribution": {"intensity": intensity}},
        timeout=10,
    )


def _alerts(result: dict[str, Any]) -> list[str]:
    return [
        d["message"]
        for d in result["diagnostics"]
        if d.get("type") == "implicit_rule_not_honored" and d.get("ruleKey") == "salarieDistribution"
    ]


def _placements(result: dict[str, Any]) -> list[tuple[str, str, int, str]]:
    return sorted((s["teamId"], s["venueId"], s["dayOfWeek"], s["startTime"]) for s in result["slots"])


def test_hard_and_preferred_give_the_same_schedule() -> None:
    """Le cran n'a aucun effet : même planning et mêmes alertes, sur un vendredi sans salarié."""
    coach_by_day = {1: "e1", 2: "e1", 3: "e2", 4: "e2", 5: "vol"}
    hard = solve_payload(_one_team_a_day(coach_by_day, intensity="HARD"))
    preferred = solve_payload(_one_team_a_day(coach_by_day, intensity="PREFERRED"))

    assert hard["status"] == "completed"
    assert preferred["status"] == "completed"
    assert _placements(hard) == _placements(preferred)
    assert _alerts(hard) == _alerts(preferred)


@pytest.mark.parametrize("intensity", INTENSITIES)
def test_the_alert_states_the_fact_without_blaming_anyone(intensity: str) -> None:
    """L'alerte énonce un fait, sans dire qu'une règle a été assouplie ou n'a pu être honorée."""
    result = solve_payload(_one_team_a_day({1: "e1", 2: "vol", 3: "e2", 4: "vol", 5: "e1"}, intensity=intensity))

    assert result["status"] == "completed"
    assert _alerts(result) == ["Aucun salarié n'a de séance le mardi et le jeudi."]


@pytest.mark.parametrize("intensity", INTENSITIES)
def test_the_solver_still_aims_for_an_employee_every_day(intensity: str) -> None:
    """Rien de réservé : l'objectif répartit les salariés sur les cinq jours, aux deux crans. Sans ce
    témoin, supprimer tout simplement la règle laisserait les autres tests de ce fichier verts."""
    teams = [make_team(f"t-{i}") for i in range(1, 6)]
    # Une seule équipe par coach serait faisable sans objectif : deux salariés, cinq équipes, et le
    # vacataire ne tient rien. Le solveur doit étaler les séances sur les cinq jours.
    constraints = [team_coach(f"tc-{i}", f"t-{i}", "e1" if i <= 2 else "e2") for i in range(1, 6)]
    payload = make_payload(
        teams=teams,
        venues=[make_venue("v", [(day, "18:00") for day in range(1, 6)], duration_minutes=60, capacity=5)],
        coaches=[_coach("e1", employee=True), _coach("e2", employee=True)],
        constraints=constraints,
        implicit_rules={"salarieDistribution": {"intensity": intensity}},
        timeout=10,
    )

    result = solve_payload(payload)

    assert result["status"] == "completed"
    assert {s["dayOfWeek"] for s in result["slots"]} == {1, 2, 3, 4, 5}
    assert _alerts(result) == []


@pytest.mark.parametrize("intensity", INTENSITIES)
def test_a_day_without_any_club_slot_raises_no_alert(intensity: str) -> None:
    """Un club fermé le vendredi n'est pas alerté à chaque génération d'un fait qu'il ne peut changer."""
    result = solve_payload(_one_team_a_day({1: "e1", 2: "e1", 3: "e2", 4: "e2"}, intensity=intensity))

    assert result["status"] == "completed"
    assert _alerts(result) == []


@pytest.mark.parametrize("intensity", INTENSITIES)
def test_a_day_with_a_slot_and_no_possible_employee_raises_the_alert(intensity: str) -> None:
    """Témoin du précédent : le vendredi existe à la grille, seul un vacataire y tient une équipe."""
    result = solve_payload(_one_team_a_day({1: "e1", 2: "e1", 3: "e2", 4: "e2", 5: "vol"}, intensity=intensity))

    assert result["status"] == "completed"
    assert _alerts(result) == ["Aucun salarié n'a de séance le vendredi."]


def _objective_days(grid_days: list[int], intensity: str) -> set[int]:
    payload = make_payload(
        teams=[make_team("t")],
        venues=[make_venue("v", [(day, "18:00") for day in grid_days])],
        coaches=[_coach("e1", employee=True), _coach("e2", employee=True)],
    )
    info: list[CompromiseTermInfo] = []
    add_salarie_distribution_constraints(
        build_model(payload),
        [],
        coaches=payload["coaches"],
        team_coach_map={},
        team_player_map={},
        intensity=intensity,
        soft_terms_out=[],
        soft_term_info_out=info,
    )
    return {int(term.day_of_week) for term in info if term.day_of_week is not None}


@pytest.mark.parametrize("intensity", INTENSITIES)
def test_a_day_without_any_club_slot_has_no_objective_term(intensity: str) -> None:
    """Aucun terme d'objectif pour un jour sans créneau : il coûterait un malus constant."""
    assert _objective_days([1, 2, 3, 4], intensity) == {1, 2, 3, 4}


@pytest.mark.parametrize("intensity", INTENSITIES)
def test_every_day_with_a_club_slot_has_its_objective_term(intensity: str) -> None:
    """Témoin : chaque jour ouvré de la grille porte son terme, et le samedi n'en porte aucun."""
    assert _objective_days([1, 2, 3, 4, 5, 6], intensity) == {1, 2, 3, 4, 5}
