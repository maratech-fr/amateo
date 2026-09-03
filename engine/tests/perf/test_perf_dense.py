"""Performance gate (PERF-01): the reference dense clubs must solve within budget.

Marked ``perf`` so it is EXCLUDED from the default suite (addopts -m 'not perf')
and run only on CI main. Without this gate a solver regression (a new quadratic
encoding, an unbounded constraint) would silently blow the 3-minute MVP exit
criterion until a client complains.

Ratchet (2026-07-07): the multi-worker fix (``_adaptive_workers``) closes the
optimality proof on the stall-prone tier in seconds instead of burning the full
600 s budget (dense: 296 complexity, BCCL: 441). The budgets below are tightened
to 60 s so a regression back to the single-worker prove-stall (measured 612 s on
BCCL) fails the gate instead of merely running long. 60 s (vs the ~2-10 s real
solve) leaves ample margin for a slow/variable CI runner.
"""

from __future__ import annotations

import json
import os
import pathlib
import time

import pytest

from tests.support import solve_payload

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"

# Post-fix ratchet: the large tier (complexity > 200) proves optimal in seconds
# with the 8-worker portfolio. 60 s catches any regression to the prove-stall.
LARGE_CLUB_BUDGET_SECONDS = 60.0


def _budget_seconds() -> float:
    """Effective budget for the perf gate, read from the environment at call time.

    Default 60.0 s — the value ``main`` keeps: the main-only ``engine-perf`` job
    sets nothing, so its ratchet to 60 s (a regression to the single-worker
    prove-stall, 612 s, must fail) is unchanged. The PR-tier gate
    (``engine-perf-pr``, dense only, run only when ``engine/`` moved — P4-167,
    decisions C1-C3) raises the budget through ``PERF_BUDGET_SECONDS`` because a
    PR budget is ≥ 1.5 × the measured median (C2): a slower or more variable CI
    runner on a PR then does not flake, while ``main`` still gates at 60 s. Read
    here rather than as a module constant so the override is consulted per test,
    not frozen at import.

    A malformed value (non-numeric, ≤ 0) raises ``ValueError`` — a bad knob fails
    loudly instead of silently reverting to a default that hides the misconfig.
    """
    raw = os.environ.get("PERF_BUDGET_SECONDS")
    if raw is None:
        return LARGE_CLUB_BUDGET_SECONDS
    try:
        value = float(raw)
    except ValueError as exc:
        raise ValueError(f"PERF_BUDGET_SECONDS must be a float, got {raw!r}") from exc
    if value <= 0:
        raise ValueError(f"PERF_BUDGET_SECONDS must be > 0, got {value}")
    return value


@pytest.mark.perf
def test_dense_club_completes_under_budget() -> None:
    """Dense club (37 teams · 8 gyms = 296): large tier, 8 workers."""
    budget = _budget_seconds()
    with open(FIXTURES_DIR / "dense_club.json", encoding="utf-8") as f:
        data = json.load(f)

    start = time.monotonic()
    result = solve_payload(data, timeout=int(budget))
    elapsed = time.monotonic() - start

    assert result["status"] == "completed"
    assert len(result["slots"]) > 0
    assert elapsed < budget, f"dense club took {elapsed:.1f}s, over the {budget:.0f}s budget"


@pytest.mark.perf
def test_bccl_completes_under_budget() -> None:
    """BCCL real payload (50 teams · 9 gyms = 450, Σ sessionsPerWeek = 90 for 90 places) —
    the densest club in the repo and the profile that stalled the single default worker
    for 612 s. Must finish well under budget with the multi-worker optimality proof."""
    budget = _budget_seconds()
    with open(FIXTURES_DIR / "bccl_2026_08_15.json", encoding="utf-8") as f:
        data = json.load(f)

    start = time.monotonic()
    result = solve_payload(data, timeout=int(budget))
    elapsed = time.monotonic() - start

    assert result["status"] == "completed"
    assert len(result["slots"]) > 0
    assert elapsed < budget, (
        f"BCCL took {elapsed:.1f}s, over the {budget:.0f}s budget (regression to the single-worker prove-stall?)"
    )
