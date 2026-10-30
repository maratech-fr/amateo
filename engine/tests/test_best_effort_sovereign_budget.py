"""Réservation souveraine (besoin fondateur 2026-10-30) — le 3ᵉ solve « best-effort » est BORNÉ,
et il ne tourne QUE lorsqu'un verrou est en cause.

« La réservation gagne envers tout, mais c'est la SEULE exception. » Quand un verrou HARD rend une
règle dure insatisfiable, un TROISIÈME solve (best-effort) produit le planning et nomme la règle
sacrifiée. Deux garde-fous unitaires, sans chronomètre (donc sans flakiness) :

  1. **Budget borné** — ``BEST_EFFORT_SOLVE_MAX_SECONDS`` existe et vaut 60 s. Un budget non borné
     (ou None) laisserait le best-effort tourner sans limite sur une grille pathologique ; la
     décision fondateur (Q2) fixe 60 s. Si le best-effort n'aboutit pas dans ce budget, le chemin
     ``failed`` reste inchangé (jamais un planning à moitié résolu).

  2. **Gâté sur les verrous** — SANS aucun verrou (``model.locked_slots`` vide), le best-effort ne
     tourne PAS : deux règles dures qui se contredisent restent un ÉCHEC (``failed``), jamais un
     ``rule_sacrificed``. C'est la règle « la réservation est la SEULE exception » à l'étage unitaire
     (le bout-en-bout est gardé par ``SovereignReservationProducesScheduleTest`` côté backend et
     ``test_sovereign_reservations.py`` côté moteur).

RED avant l'implémentation : ``BEST_EFFORT_SOLVE_MAX_SECONDS`` n'existe pas encore dans ``app.main``
(ImportError à la collecte).
"""

from __future__ import annotations

from typing import Any

from app.main import BEST_EFFORT_SOLVE_MAX_SECONDS
from tests.support.pipeline import (
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_coach,
    team_constraint,
)

FRIDAY = 5


def test_best_effort_budget_is_bounded_at_sixty_seconds() -> None:
    assert isinstance(BEST_EFFORT_SOLVE_MAX_SECONDS, int)
    assert BEST_EFFORT_SOLVE_MAX_SECONDS == 60, (
        "le budget du 3ᵉ solve best-effort est fixé à 60 s (décision fondateur Q2) ; "
        "un budget non borné laisserait le best-effort tourner sans limite"
    )


def _rule_vs_rule_without_locks() -> dict[str, Any]:
    """Deux règles DURES contradictoires et AUCUN verrou : coach indispo vendredi + équipe dont le
    vendredi est imposé, gymnase ouvert le vendredi SEULEMENT. Aucun ``slotTemplates`` ⇒
    ``model.locked_slots`` vide ⇒ le best-effort ne doit PAS se déclencher."""
    team = make_team("U21M1", sessions_per_week=1)
    coach = {"id": "coach-marc", "firstName": "Marc", "lastName": "D.", "isEmployee": False}
    venue = make_venue("gymA", [(FRIDAY, "18:00")])
    return make_payload(
        teams=[team],
        venues=[venue],
        coaches=[coach],
        constraints=[
            team_coach("tc-1", "U21M1", "coach-marc"),
            team_constraint(
                constraint_id="day-1",
                team_id="U21M1",
                family="DAY",
                rule_type="HARD",
                config={"forcedDays": [FRIDAY]},
                name="Vendredi imposé",
            ),
            {
                "id": "coach-1",
                "scope": "COACH",
                "scopeTargetId": "coach-marc",
                "family": "COACH_AVAILABILITY",
                "ruleType": "HARD",
                "name": "Indispo du vendredi",
                "config": {"unavailableDays": [FRIDAY]},
                "sortOrder": 0,
                "isActive": True,
            },
        ],
    )


def test_without_locks_an_infeasible_payload_stays_failed_without_best_effort() -> None:
    result = solve_payload(_rule_vs_rule_without_locks())

    assert result["status"] == "failed", (
        "sans verrou en cause, le best-effort ne tourne pas : deux règles contradictoires ÉCHOUENT"
    )
    sacrificed = [d for d in result["diagnostics"] if d.get("type") == "rule_sacrificed"]
    assert sacrificed == [], (
        "aucune règle n'est « sacrifiée » sans verrou souverain — la réservation est la SEULE "
        f"exception (obtenu : {sacrificed})"
    )
