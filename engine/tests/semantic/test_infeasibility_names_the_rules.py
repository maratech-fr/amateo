"""P4-96 (axe §7.1 *constraint semantics*) — quand le moteur dit non, il NOMME les règles.

Smoke sémantique de non-régression du besoin P4-96, D1 : deux contraintes SOURCE qui se
contredisent (coach indisponible le vendredi + équipe dont le vendredi est imposé) font sortir la
génération INFEASIBLE avec un diagnostic ``diag-infeasible`` qui CITE les deux règles par leur
libellé (comme dans l'écran de contraintes). Le nommage passe par le SECOND solve DIAGNOSTIQUE
(repli D4) : le solve nominal ne porte aucune hypothèse (byte-identique à ``main``), puis, sur
INFEASIBLE seulement, un modèle instrumenté (``assumptions_enabled=True``, budget court) est
rejoué et ``SufficientAssumptionsForInfeasibility`` nomme le noyau. Avant P4-96 le message était
générique (« contraintes impossibles à satisfaire toutes ensemble ») et ``causes`` restait vide :
ce test échouait (RED). Garde l'invariant ADR-0001 : INFEASIBLE échoue BRUYAMMENT, jamais un repli
relaxé (le diagnostic ne produit AUCUN planning).
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import (
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_coach,
    team_constraint,
)

FRIDAY = 5
DAY_RULE_NAME = "Vendredi imposé"
COACH_RULE_NAME = "Indispo du vendredi"


def _coach_unavailable_friday(constraint_id: str, coach_id: str) -> dict[str, Any]:
    """COACH_AVAILABILITY rendant le coach indisponible tout le vendredi, avec un libellé explicite
    (le helper ``coach_availability`` fige le nom ; on veut l'assertion sur un nom reconnaissable)."""
    return {
        "id": constraint_id,
        "scope": "COACH",
        "scopeTargetId": coach_id,
        "family": "COACH_AVAILABILITY",
        "ruleType": "HARD",
        "name": COACH_RULE_NAME,
        "config": {"unavailableDays": [FRIDAY]},
        "sortOrder": 0,
        "isActive": True,
    }


def _infeasible_payload() -> dict[str, Any]:
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
                name=DAY_RULE_NAME,
            ),
            _coach_unavailable_friday("coach-1", "coach-marc"),
        ],
    )


def test_infeasibility_names_both_conflicting_rules() -> None:
    result = solve_payload(_infeasible_payload())
    assert result["status"] == "failed", f"attendu failed (INFEASIBLE), obtenu {result['status']}"

    infeasible = next((d for d in result["diagnostics"] if d["id"] == "diag-infeasible"), None)
    assert infeasible is not None, "un diagnostic diag-infeasible doit être émis sur INFEASIBLE"

    # D1 — le message CITE les deux règles par leur libellé (écran de contraintes).
    message = infeasible["message"]
    assert "se contredisent" in message, f"message générique inattendu : {message!r}"
    assert DAY_RULE_NAME in message, f"la règle de jour imposé doit être nommée : {message!r}"
    assert COACH_RULE_NAME in message, f"la règle d'indispo coach doit être nommée : {message!r}"

    # D1 — causes STRUCTURÉES, kinds EXISTANTS, les deux familles présentes.
    kinds = {c["kind"] for c in infeasible["causes"]}
    assert "coach_unavailability" in kinds, f"cause coach manquante : {infeasible['causes']}"
    assert "day_conflict" in kinds, f"cause jour imposé manquante : {infeasible['causes']}"
    labels = {c["label"] for c in infeasible["causes"]}
    assert {DAY_RULE_NAME, COACH_RULE_NAME} <= labels, f"libellés attendus dans les causes : {labels}"


def test_locked_out_team_is_aggregated() -> None:
    """D2 — l'équipe dont TOUS les candidats sont fermés reçoit son agrégat nommé (sans solver.Value)."""
    result = solve_payload(_infeasible_payload())
    team_diag = next((d for d in result["diagnostics"] if d["id"] == "diag-infeasible-team-U21M1"), None)
    assert team_diag is not None, "l'équipe à 0 candidat ouvert doit être rapportée (D2)"
    assert team_diag["teamId"] == "U21M1"
    assert "tous fermés" in team_diag["message"], team_diag["message"]
    # Le coach ferme le(s) vendredi(s) : la cause coach_unavailability figure dans l'agrégat.
    assert any(c["kind"] == "coach_unavailability" for c in team_diag["causes"]), team_diag["causes"]
