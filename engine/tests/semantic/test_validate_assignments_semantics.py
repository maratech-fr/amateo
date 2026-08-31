"""NR constraint-semantics de /validate-assignments (axe §7.1, P2-2 F2a).

Le verdict doit refleter ce que le solveur applique VRAIMENT : une contrainte
REELLEMENT violee par le candidat produit un « non » QUI LA NOMME — pas un « faux »
muet (un non sans motif est inutilisable). On couvre les trois familles que le rail
one-time ignorait — la structure (double-booking coach, l'exemple fondateur), les
fenetres, l'indisponibilite coach — chacune verifiee sur le VRAI chemin (build_model
+ add_fixed_slots + add_level_1_hard_constraints), pas une approximation.

⚠ Ce test est aussi le pivot des deux falsifications F2a :
  * rendre « valide » un candidat qui viole une HARD -> il rougit en nommant la regle ;
  * neutraliser add_fixed_slots (baseline non figee) -> le double-booking redevient
    « valide » (le solveur deplace la seance figee), il rougit.
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import (
    as_validate_payload,
    coach_availability,
    make_team,
    make_venue,
    team_coach,
    team_constraint,
)


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(as_validate_payload(payload)))


def _coach(coach_id: str, first: str, last: str) -> dict[str, Any]:
    return {"id": coach_id, "firstName": first, "lastName": last}


def test_coach_double_booking_is_invalid_and_names_the_coach() -> None:
    """L'exemple fondateur : glisser les U13 vers un creneau ou leur coach tient
    deja les U15 dans UN AUTRE gymnase -> non, en nommant le coach ET l'equipe
    deja en place. C'est le coeur de la valeur : « le coach Dupont a deja les U15
    a 20h dans un autre gymnase »."""
    payload = {
        "clubId": "club",
        "seasonId": "season",
        "venues": [make_venue("A", [(4, "20:00")]), make_venue("B", [(4, "20:00")])],
        "teams": [make_team("U13"), make_team("U15")],
        "coaches": [_coach("C", "Marc", "Dupont")],
        "constraints": [team_coach("tc13", "U13", "C"), team_coach("tc15", "U15", "C")],
        # U15 deja placee jeudi 20h dans le gymnase B (baseline FIGEE).
        "slotTemplates": [
            {"id": "s1", "teamId": "U15", "venueId": "B", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        ],
        # Candidat : U13 vers le gymnase A, meme jeudi 20h.
        "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
    }
    result = _run(payload)

    assert result["valid"] is False, "un coach ne peut pas etre dans deux gymnases a 20h — verdict NEGATIF attendu"
    coach_conflicts = [v for v in result["violations"] if v["rule"] == "coach_no_overlap"]
    assert coach_conflicts, f"le « non » doit NOMMER coach_no_overlap, recu : {result['violations']}"
    violation = coach_conflicts[0]
    assert violation["coach_id"] == "C"
    assert violation["conflicting_team_id"] == "U15"
    assert "Dupont" in violation["message"]


def test_same_gym_double_booking_is_allowed() -> None:
    """D-14 : le MEME gymnase est autorise (un coach surveille deux groupes au meme
    endroit). Le verdict doit le refleter — sinon on refuse ce que l'UI offre."""
    payload = {
        "clubId": "club",
        "seasonId": "season",
        "venues": [make_venue("A", [(4, "20:00")], capacity=2)],
        "teams": [make_team("U13"), make_team("U15")],
        "coaches": [_coach("C", "Marc", "Dupont")],
        "constraints": [team_coach("tc13", "U13", "C"), team_coach("tc15", "U15", "C")],
        "slotTemplates": [
            {"id": "s1", "teamId": "U15", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        ],
        "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
    }
    result = _run(payload)
    assert result["valid"] is True, f"meme gymnase = autorise (D-14), recu : {result['violations']}"


def test_candidate_outside_time_window_is_invalid_and_named() -> None:
    """Un candidat hors de la fenetre horaire HARD de son equipe -> non, time_window."""
    payload = {
        "clubId": "club",
        "seasonId": "season",
        "venues": [make_venue("A", [(4, "18:00"), (4, "20:00")])],
        "teams": [make_team("U13")],
        "coaches": [],
        "constraints": [
            team_constraint(
                constraint_id="w1",
                team_id="U13",
                family="TIME",
                rule_type="HARD",
                config={"maxStartTime": "19:00"},
            )
        ],
        "slotTemplates": [],
        "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
    }
    result = _run(payload)
    assert result["valid"] is False
    assert "time_window" in {v["rule"] for v in result["violations"]}, result["violations"]


def test_candidate_when_coach_unavailable_is_invalid_and_named() -> None:
    """Le coach de l'equipe est indisponible ce jour -> non, coach_unavailable."""
    payload = {
        "clubId": "club",
        "seasonId": "season",
        "venues": [make_venue("A", [(4, "20:00")])],
        "teams": [make_team("U13")],
        "coaches": [_coach("C", "Marc", "Dupont")],
        "constraints": [
            team_coach("tc13", "U13", "C"),
            coach_availability("ca", "C", unavailable_days=[4]),
        ],
        "slotTemplates": [],
        "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
    }
    result = _run(payload)
    assert result["valid"] is False
    conflicts = {v["rule"] for v in result["violations"]}
    assert "coach_unavailable" in conflicts, result["violations"]
