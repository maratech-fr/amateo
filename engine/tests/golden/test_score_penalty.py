"""TDD golden test for BUG-5: HARD-only teams must not receive -UNPLACED_PENALTY.

Scenario:
  - 1 venue, 2 training slots.
  - 3 teams, all priorityTier S (weight 10000).
  - Team A has sessionsPerWeek=1 and a HARD lock that already satisfies it
    (0 solver variables freed — remaining_sessions = 0).
  - Teams B and C can be placed freely.

Before the fix (V2): Team A's solver vars are all forced to 0 by the
remaining_sessions constraint, so ``placed`` is False and the objective
applies ``-UNPLACED_PENALTY`` (-100 000), producing a negative score even
though all teams are placed.

After the fix (V3): Teams whose sessionsPerWeek is fully covered by HARD
locks are excluded from the ``placed.Not() * -UNPLACED_PENALTY`` term,
so the score is >= 0.
"""

from __future__ import annotations

import asyncio
import json
import pathlib
from typing import Any

import pytest

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema
from app.solver.objective import SCORE_FORMULA_VERSION, UNPLACED_PENALTY

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"


def _load_fixture(name: str) -> dict[str, Any]:
    path = FIXTURES_DIR / f"{name}.json"
    with open(path, encoding="utf-8") as f:
        return json.load(f)


@pytest.mark.timeout(30)
def test_hard_only_team_no_penalty() -> None:
    """HARD-only team fully satisfied by HARD locks must not incur -UNPLACED_PENALTY."""
    data = _load_fixture("score_hard_only_teams")
    result = asyncio.run(build_schedule(ScheduleInputSchema.model_validate(data)))

    assert result.status == "completed", f"expected completed, got {result.status}"
    assert result.score is not None, "score must be set for completed status"
    assert result.score >= 0, f"score should be >= 0 (HARD-only team not penalized), got {result.score}"


def test_score_formula_version_is_v13() -> None:
    """Guard: version bumped to V13 when the PR-3 socle-reference bonus
    (``add_socle_reference_bonus``) entered the placement objective — dès qu'un terme peut faire
    bouger le score rapporté, l'identifiant du barème change (règle V10/V11/V12)."""
    assert SCORE_FORMULA_VERSION == "T24_LEVEL_2_FIXED_WEIGHTS_V13", f"expected V13, got {SCORE_FORMULA_VERSION!r}"


def test_unplaced_penalty_unchanged() -> None:
    """Guard: UNPLACED_PENALTY must stay 100000 for genuinely unplaced teams."""
    assert UNPLACED_PENALTY == 100000
