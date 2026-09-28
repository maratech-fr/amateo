"""Real-data acceptance of ``/place-matches`` (P4-240).

Derived from a whole FFBB season of the real BCCL club
(``specs/initiales/rechercherRencontre.xlsx`` → ``fixtures/bccl_home_matches_2026.json``
via ``derive_rechercher_rencontre.py``): every HOME match the club hosts is a
``TO_PLACE`` match, on the real venues, with access windows built as the envelope
of the kickoffs actually observed per (venue, ISO weekday) — so the fixture is
100 % placeable BY CONSTRUCTION. The gate asserts the solver places at least 90 %
of them inside the 60 s budget and NEVER violates a HARD rule.

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
# Founder decision: ≥ 90 % of the home matches placeable at 60 s on the real fixture.
MIN_PLACED_RATIO = 0.9
# Wall budget of the test: a 60 s solver budget + build + I/O, with CI headroom.
BUDGET_SECONDS = 90.0


@pytest.mark.perf
def test_real_bccl_home_matches_are_at_least_90_percent_placeable() -> None:
    data = json.loads(FIXTURE.read_text(encoding="utf-8"))
    input_data = MatchPlacementInputSchema.model_validate(data)
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
    assert elapsed < BUDGET_SECONDS, f"real place-matches took {elapsed:.1f}s, over the {BUDGET_SECONDS:.0f}s budget"
