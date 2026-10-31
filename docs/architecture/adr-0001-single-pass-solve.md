# ADR-0001 — Single-pass solve, no silent fallback

- **Status**: accepted — Date: 2026-07-01
- Zone: engine (`app/main.py`, `app/solver/`)

## Context

The CP-SAT model includes two "social" hard constraints that can make an
otherwise-feasible instance INFEASIBLE: coach rest-day (every coach ≥ 1 rest day)
and salarié distribution (≥ 1 employed coach per day) — the latter is no longer a blocker (amendment
2026-10-31 at the end of this file).

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

## Amendment (2026-10-07, P4-96) — an explicit diagnostic second solve names the conflict

On INFEASIBLE the engine now **names the conflicting entered rules** instead of a
generic message. The mechanism is a CP-SAT **assumption literal per entered SOURCE
rule** (time/day window, forced/forbidden venue, coach unavailability, MANDATORY
team link/travel), shared across every site that rule enforces — the posted
constraint AND the candidate closures (`model.Add(…).OnlyEnforceIf(lit)`), with
`AddAssumption(lit)` once — so that `solver.SufficientAssumptionsForInfeasibility()`
returns the literals that suffice to explain the contradiction. `result_builder`
turns that core into named `causes[]` on `diag-infeasible` (« Ces N règles se
contredisent : … »).

**This instrumentation does NOT run on the nominal solve.** It was first wired into
the single production solve, but posting the assumption literals up front kept the
presolve from fixing the closed candidates to 0 (an assumption literal is a free
variable the unsat-core machinery must preserve), which stalled the optimality
proof: measured **614 s (the 600 s budget exhausted) on the real BCCL club (50
teams)** against **8-40 s on main**, and ~26 s vs ~2 s on the `bccl_2026_08_15`
fixture — a 13× regression the dense-perf fixture reproduces. The async queue then
backed up behind those solves (17 red Behat scenarios).

**Decision — the second-solve fallback is RETAINED** (the extension point this ADR
reserved). The nominal solve is built with assumptions OFF and is therefore
byte-identical to main (no literals, no `AddAssumption`, no `OnlyEnforceIf` —
presolve unbridled; goldens unchanged, no re-baseline; proven by
`test_nominal_solve_has_no_assumptions`). Only **after a first INFEASIBLE** does
`build_schedule` build a second, **instrumented** model (`assumptions_enabled=True`)
and run it under a short, named budget (`DIAGNOSTIC_SOLVE_MAX_SECONDS = 15 s`) purely
to read the core. If that solve does not prove INFEASIBLE within the budget
(UNKNOWN/timeout) the generic message is kept. The diagnostic solve **produces no
schedule** — it is not a relaxation, no HARD constraint is ever dropped. It is
observable via a structured engine log line (the channel the regression itself was
caught on); it does not add an output-contract field (contract unchanged, 1.3).

**D2 (per-team aggregate of closed candidates) was RETIRED by the 2026-10-31 amendment below** — it
accused teams that were not the cause. HARD locks are deliberately NOT given assumption literals
(sovereign, no variable); they are not part of the unsat core.

The dormant two-pass *relaxation* fallback (the top of this ADR) is unchanged and
still OFF — the second solve here is diagnostic-only and shares nothing with it.

## Amendment (2026-10-30, decision fondateur) — « la réservation gagne », the ONE named exception

**Need.** A manager who reserves cases (HARD locks) must never get an INFEASIBLE mute or a failure
because a rule contradicts their own reservation: « bloquer un planning dont tous les créneaux sont
réservés, c'est bizarre ; au pire j'alerte que des règles se contredisent ». Founder wording: *« La
réservation gagne envers tout, mais c'est la SEULE exception. »*

**Decision.** A HARD lock (a reservation or a manual lock — the origin is NOT sent to the engine) that
contradicts a rule WINS: the engine PRODUCES the schedule (`status="completed"`), holds the lock, and
NAMES the gap. Two warning diagnostics (WARNING, contract 1.8):

- `rule_sacrificed` — the rule contradicts the reservation itself, or a group (shared block) is
  deprived of its case: the rule is relaxed for that case and named, with the reservation in cause.
- `blocked_by_reservation` — the reservation of ANOTHER team takes the only compatible case (window,
  imposed day, floor): the rule is NOT relaxed, the session stays UNPLACED (a session is never placed
  outside its rule), the message names the team, the rule and the reservation.

Only rules of *demand* (imposed day, venue floor, shared-block common sessions) are ever lifted;
*closure* rules (unavailability, window, forbidden day or venue) never are.

**Mechanism** (`app/main.py`, `_best_effort_solve`): it runs ONLY after a first INFEASIBLE and ONLY when
locks are present. (1) A **causality probe** replays the model with the locks RELEASED
(`BEST_EFFORT_PROBE_MAX_SECONDS`, 15 s): if it stays infeasible the conflict is rule-against-rule or
rule-against-grid, not a lock — nothing is relaxed. (2) Otherwise a **third, best-effort solve** frees
the demand rules the infeasibility core points at (`ScheduleCpModel.relaxed_source_keys`: the literal is
posed but never assumed, so it falls free) under `BEST_EFFORT_SOLVE_MAX_SECONDS` = 60 s, iterating on
the next core if needed. If it does not succeed in budget the `failed` path is unchanged — never a
half-plan. One gap = one message: once a reservation is in cause, the new diagnostic REPLACES the
historical `constraint_not_honored` / `session_below_effective_min` / `conflict` signals of that team.

**Refinements (founder, 2026-10-30, after the cross-review of the branch).**
- *Lifted floor*: when a reservation takes one of the cases of an "at least N at V" floor, the team
  KEEPS all its sessions (they go to the other non-forbidden venues, weighted
  `RELAXED_FLOOR_PLACEMENT_WEIGHT` just behind filling) and only the minimum is missing — "1 session at V
  instead of 2, X occupies the other case". The session count is reduced only when no allowed case exists.
  With an imposed day or a window, the session stays UNPLACED (`blocked_by_reservation`).
- *Probe*: a SINGLE solve (`placement_only`, no chaining phase), and the floor keeps its credit — a team
  served by its own locks does not claim those sessions a second time.
- *Budgets unchanged* (60/180/600 s adaptive, 15 s probe, 60 s best-effort). Worst path 700 s of engine
  budget: the Messenger message now carries 750 s (`GenerateScheduleMessage`) and the Redis lock 810 s
  (`GenerationLockOutlivesTheSolveTest` compares them to the engine).
- *Wording*: `blocked_by_reservation` and `rule_sacrificed` say "séance fixée" ("la séance fixée de X"),
  never "verrou", whatever the origin (reservation or manual lock).
- *Verdict*: a manual placement of an unplaced session outside its rule is ALLOWED and flagged (see
  `engine/docs/engine-inventory.md`, `/validate-assignments`).

**This is the ONLY exception.** With no lock in cause, contradicting rules stay a `failed` outcome
EXPLAINED (which rules, on what) exactly as the P4-96 amendment above; the nominal and diagnostic solves
are byte-identical to before. The dormant relaxation fallback of the top of this ADR stays OFF. A
future second exception needs its own amendment. Guards: `SovereignReservationProducesScheduleTest`
(blocking, `ci.yml`), `engine/tests/semantic/test_sovereign_reservations.py` (incl. the rule-conflict-
not-caused-by-a-lock witness), `engine/tests/test_best_effort_sovereign_budget.py`. Contract: bump
1.7 → 1.8 (two diagnostic types, one cause kind `min_sessions`).

## Amendment (2026-10-31, decision fondateur) — the salarié rule is an objective, a failure names its cause or says it found none

**Need.** A club whose every case was reserved got a `failed` plan because no salarié sat on one weekday:
the HARD « a salarié each day » rule contradicted the reservations. A manager cannot be blocked by a
fact the grid and the reservations impose.

**Decision 1 — the salarié distribution rule is an OBJECTIVE whatever its stored level.** The HARD branch
of `add_salarie_distribution_constraints` is gone: both levels push the weighted violation literal
(`SALARIE_VIOLATION_WEIGHT`) and a `CompromiseTermInfo(detail="salarie")`; `intensity` stays in the
signature with no effect for this rule (stored settings, payload and API unchanged). A weekday on which
no venue of the club has a case carries neither literal nor alert (same grid reading in the post-solve
diagnostic). The alert (`implicit_rule_not_honored`, `ruleKey` `salarieDistribution`, WARNING) is worded
as a fact — « Aucun salarié n'a de séance le jeudi. » — and `/validate-assignments` accepts a move that
empties a day of salariés and reports it as a compromise. The two other week-rules that a lock can defeat
(chains, ascending age) keep their HARD level; coach rest was already announced, not blocking.

**Decision 2 — the D2 « N candidats, tous fermés » lines are removed** (`diag-infeasible-team-*`,
`_collect_locked_out_teams`). They named teams that were innocent and told a manager to check « locked
slots », which the 2026-10-30 wording forbids. A real lack of room is read on the messages of the
`completed` path (unplaced session, `blocked_by_reservation`).

**Consequence — every remaining INFEASIBLE has a nameable demand rule.** Once the salarié rule cannot
fail, the only rules able to force an INFEASIBLE are the rules of *demand* (imposed day, venue floor,
shared-block common sessions): the other week-rules only post upper bounds or `== 0`, which « nobody
placed » satisfies, and the session floor is soft. So `diag-infeasible` has exactly these cases, each
with its own text and suggestion (`_infeasible_core_message`): (a) the core holds a group (shared block),
named by its teams « groupe SM1 + SM2 » with no « Corriger » link; (b) the diagnostic solve did not
conclude in its budget: the message says the search for the cause did not finish and suggests relaunching
or switching off the latest rules one by one; (c) a source rule without a label is cited as an unnamed
rule of its kind; (d) the core names only demand rules: the suggestion points to a wellness rule set to
« Obligatoire » or to a coach holding several teams. The generic « verify the locked slots » sentence is
gone. A fully reserved team gets no « not enough room » message, even inside a group: only the
reservation that breaks a rule is named (`rule_sacrificed`). The guards upstream of the engine
(`CoachInTwoPlacesGuard`, the Récap blockers) are unchanged: « the reservation wins » holds inside the
engine, not against them; an over-pinned shared block stays `failed`, named by its teams.

**Known gap, not an exception.** `maxConsecutiveDays` only reads free variables, so it never blocks, and
no post-solve diagnostic covers it: a violation caused by locks is SILENT (roadmap line). No contract change (1.8), no new diagnostic kind. Guards:
`engine/tests/semantic/test_salarie_rule_is_an_objective.py`,
`test_fully_reserved_grid_two_employees.py`, `test_infeasibility_names_the_rules.py`,
`engine/tests/test_result_builder.py`, `engine/tests/golden/test_two_pass.py`.
