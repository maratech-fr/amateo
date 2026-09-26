# ADR-0001 — Single-pass solve, no silent fallback

- **Status**: accepted — Date: 2026-07-01
- Zone: engine (`app/main.py`, `app/solver/`)

## Context

The CP-SAT model includes two "social" hard constraints that can make an
otherwise-feasible instance INFEASIBLE: coach rest-day (every coach ≥ 1 rest day)
and salarié distribution (≥ 1 employed coach per day).

The codebase carries the plumbing for a **two-pass fallback**: a second solve that
relaxes those two constraints (`skip_rest_day_and_distribution=True` in
`add_level_1_hard_constraints`, surfaced via `fallback_used` in `build_result`).
That fallback is **not wired into production** — `main.py` always calls the solver
once with all hard constraints and passes `fallback_used=False`. Only the tests
exercise the relaxed path.

## Decision

**A single solve pass with all HARD constraints active, no relaxation fallback.**
If the solver returns INFEASIBLE, `build_result` produces `status="failed"` with
conflict diagnostics — the engine never silently drops rest-day or distribution to
force a result. The fallback parameters (`skip_rest_day_and_distribution`,
`fallback_used`) stay in the signatures as a documented, tested, **dormant**
extension point; turning it on is a deliberate, separate feature decision (its own
ADR), never a cleanup.

**"Single pass" means one attempt with all hard constraints active — not literally
one solver call.** Production runs a **two-phase lexicographic optimisation**
inside that single attempt (`main.py`, "Phase 1/Phase 2" comments): phase 1 solves
the PLACEMENT objective (chaining terms built but excluded); phase 2 locks the
phase-1 objective value as a hard floor (`placement_expression >= value`),
warm-starts from the phase-1 solution and maximises the chaining bonus under a hard
`CHAINING_PHASE_MAX_SECONDS` (10 s) cap. Phase 2 runs on OPTIMAL **or FEASIBLE**
phase-1 outcomes: after a phase-1 timeout the locked bound is the best-FOUND
placement value, not a proven optimum — chaining can then only improve on that
incumbent, never degrade it. Neither phase drops a HARD constraint; INFEASIBLE
still fails loudly with diagnostics regardless of which phase is running.

Two further lexicographic tiers order among otherwise-equal solutions — both keyed
by the same optional `previousAssignments` field (a prior generation's placements,
absent/empty ⇒ byte-identical output) and neither ever relaxing a HARD constraint:

- **Stability** (phase 2, weight 1): at an EXACT tie on (placement, chaining), the
  solver prefers the placement the previous generation already chose.
- **Proximity to the previous placement** (phase 1's own placement objective,
  weight 9): a session the previous generation held stays held even when that
  source scored *below* the phase-1 optimum (a validated schedule retouched by
  hand has nothing else to converge to) — without ever beating an entered rule
  worth ≥ 10 or inverting the S/A/B/C tier ordering; the one gap it does close
  (C−D = 9) is an exact tie that the phase-2 stability sub-band then settles.

Both tiers report the score at the **original** weights (their mass excluded from
`ObjectiveValue()`), so `SCORE_FORMULA_VERSION` stays meaningful. The stacking
proof (why weight 9, never deletes a session, never inverts a tier) lives in
`weights.py` (`PLACEMENT_PROXIMITY_WEIGHT`) and its guards
(`engine/tests/semantic/test_stability_semantics.py`,
`engine/tests/test_stability_convergence.py`, `engine/tests/test_objective.py`) —
detail: `engine/docs/engine-inventory.md` §5 Solver.

**Search effort is adaptive alongside the time budget**: `_adaptive_workers`
mirrors the timeout tiers — 1 worker below a complexity of 200 (deterministic, the
golden fixtures depend on it), 8 above (finds the optimum fast on dense
soft-preference problems, needs the portfolio to *prove* it). Accepted
consequence: above the threshold the **assignment** is not deterministic — the
objective **value** is.

**The phase-1 budget is adaptive**: 60/180/600 s tiers by `n_teams×n_venues`, the
payload's `solver_timeout_seconds` (default 650 s) acting as a ceiling only; the
chaining phase adds at most `CHAINING_PHASE_MAX_SECONDS` (10 s).

**A generation-complexity cap bounds the problem before it reaches the solver** —
a defensive input bound, not a relaxation, so it neither touches solver behaviour
nor the no-relaxation guarantee: a backend pre-check (`GenerationComplexityGuard`,
called before dispatch) counts the club/season's teams, venues, coaches,
availability slots and permanent constraints, rejecting with **422** before the
message is ever queued (teams ≤ 200, venues ≤ 50, coaches ≤ 200, slots ≤ 3000,
constraints ≤ 500, and `teams × venues ≤ 2000` — the dominant CP-SAT model-size
driver); the engine input schema (`input_schema.py`, `max_length` on every request
list plus a bound on total availability slots) is defense-in-depth for dimensions
the backend does not count, rejecting instantly at validation so the generation
lock is released in milliseconds.

## Consequences

- An over-constrained instance **fails loudly** (with diagnostics) instead of
  returning a schedule that quietly violates the social constraints — honest and
  predictable for the caller.
- Turning the fallback on is a deliberate, separate change (its own ADR): it would
  alter solver behaviour, output semantics and diagnostics.
- The lexicographic tiers (placement, chaining, stability/proximity) only order
  among feasible optima; none of them manufacture one where CP-SAT found none.

## Alternatives considered

- **Auto two-pass fallback** (relax on INFEASIBLE, produce a degraded plan):
  rejected as the default — it silently drops constraints the club asked for, and
  a degraded plan presented as success is misleading. Left as an opt-in extension
  point for a future, explicit decision.
