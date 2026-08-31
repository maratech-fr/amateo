"""Un verdict ACCEPTÉ ne meurt jamais de son habillage explicatif (compromis).

Depuis P2-32, un candidat accepté déclenche DEUX solves supplémentaires (« avant »/« après »)
pour NOMMER les compromis de confort. Ces solves ont un coût (construction de modèle + budget)
et peuvent échouer ou dépasser leur budget sur un dataset dense — ce qui, jusqu'ici, faisait
mourir TOUT le endpoint (le backend abandonnait alors sur timeout transport et rendait un 502
alors que le déplacement était LÉGAL).

Le calcul des compromis est donc AU MIEUX : s'il lève (quelle que soit la cause — budget épuisé,
``solver.Value`` sur une solution absente, bug), le verdict sort quand même, avec ``compromises``
vide. Le contrat de réponse ne change PAS (mêmes clés) — seul le contenu de ``compromises`` est
vidé sur échec. Falsification : re-souder verdict et compromis (propager l'exception) rougit ici.
"""

from __future__ import annotations

from typing import Any

import pytest

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver import validate_assignments
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import as_validate_payload, make_team, make_venue


def _accepting_payload_with_a_broken_preference() -> dict[str, Any]:
    """Un déplacement LÉGAL (verdict « oui ») qui casse une préférence de gymnase — donc un
    candidat pour lequel le calcul de compromis a normalement quelque chose à dire."""
    return {
        "clubId": "c",
        "seasonId": "s",
        "venues": [make_venue("A", [(4, "20:00")]), make_venue("B", [(4, "20:00")])],
        "teams": [make_team("U13")],
        "constraints": [
            {
                "id": "pref-U13-A",
                "scope": "TEAM",
                "scopeTargetId": "U13",
                "family": "FACILITY",
                "ruleType": "PREFERRED",
                "name": "gymnase préféré",
                "config": {"preferredVenueId": "A"},
                "sortOrder": 0,
                "isActive": True,
            }
        ],
        "slotTemplates": [],
        "candidate": {"teamId": "U13", "venueId": "B", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        "reference": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
    }


def test_compromise_failure_still_yields_the_verdict(monkeypatch: pytest.MonkeyPatch) -> None:
    def _boom(*_args: Any, **_kwargs: Any) -> list[dict[str, Any]]:
        raise RuntimeError("compromise solve blew up (e.g. budget exhausted, no solution to read)")

    monkeypatch.setattr(validate_assignments, "_compromises_for", _boom)

    result = validate_assignment(
        ValidateAssignmentsInputSchema.model_validate(
            as_validate_payload(_accepting_payload_with_a_broken_preference())
        )
    )

    # Le verdict SORT quand même (accepté), et la réponse garde sa forme exacte.
    assert result["valid"] is True
    assert result["violations"] == []
    # L'habillage a échoué → compromis vidé, JAMAIS une clé manquante ni une exception propagée.
    assert result["compromises"] == []
    assert set(result.keys()) == {"valid", "violations", "compromises", "metrics"}


def test_compromises_are_skipped_when_the_verdict_already_ate_the_budget(monkeypatch: pytest.MonkeyPatch) -> None:
    """Le deuxième garde-fou : la LENTEUR, pas seulement la panne.

    Un calcul de compromis qui prend son temps sans jamais lever laissait la réponse s'allonger
    jusqu'au plafond transport du backend — le geste échouait alors qu'il était LÉGAL. Passé
    ``COMPROMISE_ELAPSED_BUDGET_SECONDS`` déjà consommées par le verdict, on N'ENTAME PAS
    l'habillage : réponse honnête, compromis vides. Falsification : retirer le garde-fou rougit
    ici (les compromis reviendraient malgré le budget épuisé).
    """
    called = False

    def _tracking(*_args: Any, **_kwargs: Any) -> list[dict[str, Any]]:
        nonlocal called
        called = True
        return [{"family": "venue_preference", "message": "peu importe"}]

    monkeypatch.setattr(validate_assignments, "_compromises_for", _tracking)
    # Budget nul = « le verdict a déjà tout mangé », quel que soit le temps réel de la machine.
    monkeypatch.setattr(validate_assignments, "COMPROMISE_ELAPSED_BUDGET_SECONDS", 0.0)

    result = validate_assignment(
        ValidateAssignmentsInputSchema.model_validate(
            as_validate_payload(_accepting_payload_with_a_broken_preference())
        )
    )

    assert result["valid"] is True
    assert result["compromises"] == []
    assert called is False, "le calcul des compromis ne doit même pas être tenté hors budget"
    assert set(result.keys()) == {"valid", "violations", "compromises", "metrics"}
