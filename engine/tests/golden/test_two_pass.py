"""TDD test for BUG-4: two-pass solver fallback silently drops rest-day constraint.

When ``build_schedule`` cannot find a feasible solution with all HARD constraints
(Pass 1), it currently retries without the coach rest-day and salarie distribution
constraints (Pass 2).  This test ensures that either:

* the solver returns ``status="failed"`` (constraints are genuinely impossible), or
* the solver returns ``status="completed"`` **and** Enzo has at least 1 rest day
  among Mon-Fri (days 1-5).

Before the fix, Pass 2 silently drops the rest-day constraint and returns
``status="completed"`` with Enzo coaching on all 5 weekdays — the test fails.
After the fix, only one pass runs with all HARD constraints; INFEASIBLE is
surfaced as ``status="failed"``.
"""

from __future__ import annotations

import asyncio
import json
import pathlib
from typing import Any

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"

ENZO_COACH_ID = "coach-enzo"


def _load_fixture(name: str) -> dict[str, Any]:
    with open(FIXTURES_DIR / f"{name}.json", encoding="utf-8") as f:
        return json.load(f)


def _enzo_working_days(result: Any) -> set[int]:
    """Return the set of Mon-Fri weekdays on which Enzo is coaching."""
    days: set[int] = set()
    for slot in result.slots:
        if slot.coach_id == ENZO_COACH_ID and int(slot.day_of_week) in range(1, 6):
            days.add(int(slot.day_of_week))
    return days


def test_forced_days_against_the_rest_day_point_to_the_wellness_rule() -> None:
    """Le repos coach n'est pas dans le noyau, qui ne nomme que les jours imposés : la suggestion
    doit désigner la règle de bien-être, seule piste qui mène le gestionnaire à la vraie cause."""
    result = asyncio.run(build_schedule(ScheduleInputSchema.model_validate(_load_fixture("no_rest_enzo"))))
    assert result.status == "failed"
    infeasible = next(d for d in result.diagnostics if d.id == "diag-infeasible")
    assert any("règle de bien-être réglée « Obligatoire »" in s for s in infeasible.suggestions), infeasible.suggestions
    assert not any("LOCK" in s for s in infeasible.suggestions), infeasible.suggestions


def test_no_rest_enzo_hard_only() -> None:
    """Enzo coaches 5 teams (one per weekday, HARD-forced days) with no rest day.

    With the rest-day HARD constraint (max 4 working days), Pass 1 is INFEASIBLE
    because all 5 teams are forced to 5 different days and Enzo is the only coach.

    Before the fix: Pass 2 drops the rest-day constraint → ``completed`` with
    Enzo working all 5 days (0 rest days) → assertion fails.

    After the fix: only one pass runs → INFEASIBLE → ``failed`` (or ``completed``
    with Enzo having ≥ 1 rest day if the solver finds a partial solution).
    """
    data = _load_fixture("no_rest_enzo")
    result = asyncio.run(build_schedule(ScheduleInputSchema.model_validate(data)))

    if result.status == "completed":
        working_days = _enzo_working_days(result)
        assert len(working_days) <= 4, (
            f"Enzo must have at least 1 rest day (Mon-Fri), "
            f"but is coaching on {len(working_days)} days: {sorted(working_days)}. "
            "The two-pass fallback silently dropped the rest-day constraint."
        )
    else:
        assert result.status == "failed", f"Unexpected status: {result.status}"
