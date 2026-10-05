"""NR — ENG-17 : le coach d'une séance GÉNÉRÉE sort dans le résultat.

Le défaut (audit 2026-07-08, différé jusqu'ici) : `coachId` n'était renseigné
qu'à partir des `slotTemplates`. Or le chemin DOMINANT est la contrainte
`TEAM_COACH` — c'est ainsi que le backend sérialise les liens équipe→coach.
Une équipe sans créneau épinglé sortait donc `coachId=None` sur TOUTES ses
séances, et les diagnostics coach (double-réservation, surcharge, jour de
repos) restaient **muets** pour elle : la contrainte était bien appliquée par
le solveur, mais invisible en sortie — « déclaré ≠ montré ».

⚠ Ce qui rend ces cas discriminants : AUCUN `slotTemplate` n'est fourni. Avec
un créneau épinglé, l'ancien code trouvait déjà un coach et le test passerait
au vert sans rien prouver.
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_coach

DAY = 2


def _placed(result: dict[str, Any], team_id: str) -> list[dict[str, Any]]:
    return [slot for slot in result["slots"] if slot["teamId"] == team_id]


def _payload(*constraints: dict[str, Any]) -> dict[str, Any]:
    return make_payload(
        teams=[make_team("t1", sessions_per_week=1)],
        venues=[make_venue("gym", [(DAY, "18:00")])],
        coaches=[
            {"id": "coach-1", "firstName": "Ana", "lastName": "K"},
            {"id": "coach-2", "firstName": "Bo", "lastName": "L"},
        ],
        constraints=list(constraints),
    )


def test_generated_slot_carries_the_team_main_coach() -> None:
    result = solve_payload(_payload(team_coach("tc", "t1", "coach-1")))

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    placed = _placed(result, "t1")
    assert placed, "team must be placed"
    assert all(slot["coachId"] == "coach-1" for slot in placed), (
        f"la séance générée doit NOMMER le coach de l'équipe, obtenu : {[s['coachId'] for s in placed]}"
    )


def test_assistant_only_team_still_reports_no_coach() -> None:
    # TÉMOIN de la RÈGLE, pas seulement du câblage : seul le coach MAIN est une
    # ressource exclusive pour le solveur (un ASSISTANT est optionnel). La sortie
    # doit suivre la même règle — sinon on nommerait comme responsable quelqu'un
    # que le modèle n'a jamais contraint.
    result = solve_payload(_payload(team_coach("tc", "t1", "coach-2", role="ASSISTANT")))

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    placed = _placed(result, "t1")
    assert placed, "team must be placed"
    assert all(slot["coachId"] is None for slot in placed), (
        "un ASSISTANT seul n'encadre pas la séance au sens du modèle"
    )


def test_no_coach_constraint_still_reports_no_coach() -> None:
    # Témoin du repli : sans lien équipe→coach, rien à nommer (comportement d'avant).
    result = solve_payload(_payload())

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    assert all(slot["coachId"] is None for slot in _placed(result, "t1"))
