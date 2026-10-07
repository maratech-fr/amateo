"""Performance gate (PERF-01): the reference dense clubs must solve within budget.

Marked ``perf`` so it is EXCLUDED from the default suite (addopts -m 'not perf').
Runs on CI main (both tiers, ``engine-perf`` job) and on PR (dense tier only, when
``engine/`` moved — ``engine-perf-pr`` job, P4-167). Without this gate a solver
regression (a new quadratic encoding, an unbounded constraint) would silently blow
the 3-minute MVP exit criterion until a client complains.

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


# P4-96 (repli D4) — garde dédiée contre la régression des HYPOTHÈSES AU PREMIER SOLVE. La BCCL
# porte 118 contraintes HARD source (49 TIME, 35 DAY, 28 FACILITY, 6 COACH_AVAILABILITY) : posées
# en hypothèses CP-SAT dès le premier solve, elles bridaient le présolve et faisaient stagner la
# preuve d'optimalité. Mesuré sur cette fixture : ~2 s sans hypothèse (nominal = `main`), ~26 s
# avec (le réel BCCL, lui, montait à 614 s). Le solve nominal ne devant plus porter aucune
# hypothèse, il reste dans l'ordre de grandeur de `main`. Budget serré (20 s) pour que tout retour
# du mécanisme au premier solve ÉCHOUE la garde (≈10× la marge sur le ~2 s réel, bien sous le ~26 s
# régressé) — là où la garde 60 s ci-dessus laissait passer les 26 s. La preuve STRUCTURELLE (le
# modèle nominal ne porte aucune littérale) vit, elle, dans `test_nominal_solve_has_no_assumptions`.
BCCL_NOMINAL_ORDER_BUDGET_SECONDS = 20.0


@pytest.mark.perf
def test_bccl_nominal_solve_stays_in_main_order() -> None:
    """La génération nominale de la BCCL reste dans l'ordre de grandeur de ``main`` (~2 s),
    PRÈS du dixième du budget — une régression aux hypothèses-au-premier-solve (~26 s) échoue."""
    with open(FIXTURES_DIR / "bccl_2026_08_15.json", encoding="utf-8") as f:
        data = json.load(f)

    start = time.monotonic()
    result = solve_payload(data, timeout=int(BCCL_NOMINAL_ORDER_BUDGET_SECONDS))
    elapsed = time.monotonic() - start

    assert result["status"] == "completed"
    assert len(result["slots"]) == 90, f"la BCCL doit remplir 90/90, obtenu {len(result['slots'])}"
    assert elapsed < BCCL_NOMINAL_ORDER_BUDGET_SECONDS, (
        f"BCCL nominale en {elapsed:.1f}s, au-dessus de {BCCL_NOMINAL_ORDER_BUDGET_SECONDS:.0f}s : "
        "régression probable aux hypothèses CP-SAT posées dès le premier solve (présolve bridé)."
    )
