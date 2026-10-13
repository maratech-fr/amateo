"""Real-data acceptance of ``/place-matches`` (P4-240).

Derived from a whole FFBB season of the real BCCL club
(``specs/initiales/rechercherRencontre.xlsx`` → ``fixtures/bccl_home_matches_2026.json``
via ``derive_rechercher_rencontre.py``): every HOME match the club hosts is a
``TO_PLACE`` match, on the real venues, with access windows built as the envelope
of the kickoffs actually observed per (venue, ISO weekday) — so the fixture is
100 % placeable BY CONSTRUCTION. The gate asserts the solver places at least 90 %
of them under the PRODUCTION per-ISO-week budget and NEVER violates a HARD rule.

Marked ``perf`` (main-only, EXCLUDED from the default suite) like its synthetic
sibling: real volume (141 matches · 5 gyms) under the full solver budget is too
heavy for the PR-tier. Names deliberately avoid ``dense_club`` so the PR-tier
``-k dense_club`` filter leaves it to the main-only ``pytest -m perf`` run.
"""

from __future__ import annotations

import json
import pathlib
import time

import pytest

from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.schemas.match_output_schema import MatchPlacementOutputSchema
from app.solver.match_placement import solve_match_placement
from tests.semantic.test_match_placement_semantics import assert_no_hard_violation

FIXTURE = pathlib.Path(__file__).resolve().parents[1] / "fixtures" / "bccl_home_matches_2026.json"
# Founder decision: ≥ 90 % of the home matches placeable under the production per-ISO-week
# budget on the real fixture.
MIN_PLACED_RATIO = 0.9
# Per-ISO-week solver budget, PINNED to what production actually sends so the test measures
# the real experience and not the fixture's own figure:
# ``MatchPlacementPayloadBuilder::WEEK_BUDGET_SECONDS`` = 35 s
# (``backend/src/Service/MatchPlacementPayloadBuilder.php``). It overrides the fixture's
# ``solverTimeoutSeconds`` below.
PROD_WEEK_BUDGET_SECONDS = 35
# Wall budget of the WHOLE test — a ceiling RECALIBRATED on the measured wall time, NOT a
# target to speed up to. ``solve_match_placement`` in
# ``app/solver/match_placement/__init__.py`` (see its docstring, ~l.690-703) solves the
# request ISO week by ISO week, spending ``solver_timeout_seconds`` on EACH week; the
# fixture covers 24 ISO weeks carrying TO_PLACE matches, so the total wall time is the SUM
# of the per-week solves plus model build and I/O.
# Local measurement at 35 s/week: ~94 s wall, with only the 2 densest weeks capped at 35 s.
BUDGET_SECONDS = 150.0


@pytest.mark.perf
def test_real_bccl_home_matches_are_at_least_90_percent_placeable() -> None:
    data = json.loads(FIXTURE.read_text(encoding="utf-8"))
    input_data = MatchPlacementInputSchema.model_validate(data).model_copy(
        update={"solver_timeout_seconds": PROD_WEEK_BUDGET_SECONDS}
    )
    to_place = sum(1 for m in input_data.matches if m.kind == "TO_PLACE")
    assert to_place == 141, f"the real fixture should carry 141 home matches, got {to_place}"

    start = time.monotonic()
    result = solve_match_placement(input_data)
    elapsed = time.monotonic() - start

    assert result["status"] == "completed", result
    output = MatchPlacementOutputSchema.model_validate(result)

    placed = len(output.placements)
    floor = MIN_PLACED_RATIO * to_place
    assert placed >= floor, f"placed {placed}/{to_place} ({placed / to_place:.0%}), expected ≥ {floor:.0f} (90 %)"

    # The whole point: no HARD rule is ever broken, whatever the SOFT arbitration.
    assert_no_hard_violation(input_data, output)
    engine_wall_ms = output.metrics.wall_time_ms if output.metrics else None
    assert elapsed < BUDGET_SECONDS, (
        f"real place-matches took {elapsed:.1f}s wall "
        f"(engine solver wall_time_ms={engine_wall_ms}), over the {BUDGET_SECONDS:.0f}s budget"
    )
