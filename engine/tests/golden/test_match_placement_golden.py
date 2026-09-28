"""Golden of the match placement (P1-4 PR D): seed 42 + 1 worker ⇒ bit-stable
output. Pins the EXACT placements of the realistic weekend so any change to
the weights (D5), the candidate geometry or the solver parameters shows up as
a diff a human must acknowledge — the weights are product decisions (ADR-0003).

The pinned optimum reads: DF2 opening the day at 13:45, then PNM ON its exact
15:30 habit, SF1 chained back-to-back right behind it (their declared link, SF1
kicking off exactly when PNM's 105-min match ends → 17:15), the manual 20:30
anchor closing on Mateo. Under D1 (P4-203) the venue holds the match ONLY
(105 min, no warm-up).

⚠ P4-240 ③ (décision B) re-pinned this golden: the AWAY match m-rf3 shares coach
emerick with PNM, and BEFORE B its person window pushed PNM off its 15:30 habit
(the old optimum placed PNM at 18:45). The placement solver now IGNORES every
AWAY person footprint (« c'est la vie »), so emerick is free that afternoon and
PNM reclaims its habit — the desired effect of B, not a regression.
"""

from __future__ import annotations

from datetime import time

from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.solver.match_placement import solve_match_placement
from tests.semantic.test_match_placement_semantics import wire_payload


def test_realistic_weekend_golden_placements() -> None:
    result = solve_match_placement(MatchPlacementInputSchema.model_validate(wire_payload()))

    assert result["unplaced"] == []
    placements = {p["matchId"]: (p["venueId"], p["kickoff"]) for p in result["placements"]}
    assert placements == {
        "m-df2": ("mateo", time(13, 45)),
        "m-pnm": ("mateo", time(15, 30)),
        "m-sf1": ("mateo", time(17, 15)),
    }
