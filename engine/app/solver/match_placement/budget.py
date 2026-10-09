from __future__ import annotations

import uuid
from typing import Any


class _BuildBudgetExceeded(Exception):
    """Raised when building the CP-SAT model overruns ``BUILD_BUDGET_SECONDS``.

    Carries the measured shape (matches + candidate variables built so far) so the
    caller can name the problem to the manager instead of failing mutely."""

    def __init__(self, n_matches: int, n_candidates: int) -> None:
        self.n_matches = n_matches
        self.n_candidates = n_candidates
        super().__init__(f"match placement build budget exceeded ({n_matches} matches, {n_candidates} candidates)")


def _too_large_result(exc: _BuildBudgetExceeded) -> dict[str, Any]:
    """The failed response for a placement problem too large to build in budget."""
    return {
        "status": "failed",
        "placements": [],
        "unplaced": [],
        "diagnostics": [
            {
                "id": str(uuid.uuid5(uuid.NAMESPACE_URL, "placement-too-large")),
                "type": "placement_problem_too_large",
                "severity": "error",
                "message": (
                    f"Le placement des matchs est trop volumineux pour être calculé "
                    f"({exc.n_matches} matchs, {exc.n_candidates} créneaux candidats) : "
                    "réduisez le volume de matchs à placer ou les fenêtres d'accès."
                ),
                "suggestions": [
                    "Placez les matchs par lots plus petits, ou resserrez les fenêtres d'accès des gymnases.",
                ],
            }
        ],
        "metrics": None,
    }
