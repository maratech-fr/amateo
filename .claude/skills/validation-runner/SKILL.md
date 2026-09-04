---
name: validation-runner
description: After an implementation, runs the targeted tests for the changed zone PLUS the cross-zone integration/contract tests, and explicitly justifies any test that could not be run. Its value over a plain `make test` is choosing the right targeted + cross-zone tests and reporting non-runnable ones. Invoke manually.
---

## Validation Runner

Run **only when the user asks**, after an implementation that stayed within a validated plan's scope.

### Why this exists (vs a bare `make test`)
It does not blindly run everything. It (a) selects the **targeted** tests for the zone that actually changed, (b) adds the **cross-zone** integration/contract tests when a boundary is touched, and (c) **justifies** every test it could not run instead of silently skipping.

### Steps
1. **Detect changed zone(s)** — engine / backend / frontend — from the diff (use `detect_changes` from `code-review-graph` if helpful).
2. **Targeted tests for the changed zone:**
   - **Backend:** `cd backend && make test` (lint + phpunit `--group phase1`). For security/contract-touching changes, also run the blocking tests: `tests/Security/TenantIsolationTest.php`, `tests/Security/TenantCacheIsolationTest.php`, `tests/Queue/ConcurrentGenerationTest.php`, `tests/CrossStack/ContractSchemaTest.php`.
   - **Engine:** `cd engine && make test` (pytest + ruff + mypy).
3. **Cross-zone tests** — when a change touches the backend↔engine contract (Pydantic schemas ⇄ API Platform resources), run `ContractSchemaTest` — it is the guardrail for the manually-synced contract.
4. **Report** — pass/fail per suite, plus an explicit justification for any suite that could not run (stack not up, missing service, etc.).
5. **Behat functional tests — MANDATORY when the change touches `engine/` or `backend/`.** Run `make -C backend behat` (`with-sandbox.sh make -C backend behat` in play mode) — plays all 5 features (`backend/features/`) against the real stack; the Makefile target has no suite filter (add one only by invoking `vendor/bin/behat --suite=<name>` directly inside the php-fpm container, sandbox-guarded manually). The bash smokes they replaced are gone (P4-165 — no `.sh` left in `backend/scripts/`). Full run is cheap even against the real stack — no strong reason to isolate a suite, but the mapping below tells you which feature is the direct proof for the touched zone:

   | Touched zone | Feature | Proves |
   |---|---|---|
   | engine / backend generation pipeline | `generation-du-planning-de-saison.feature` | rail async → `COMPLETED`, restores the season plan pointer in `@AfterScenario` |
   | register / onboarding | `inscription-et-premier-planning.feature` | register → email verify → minimum data → generate → `COMPLETED` |
   | match module (fixtures, placement, `/place-matches`, contract match schemas) | `placement-des-matchs.feature` | a Saturday match lands `PLACED` inside its access window, a Sunday one comes back `UNPLACED` with the named reason `no_access_window` |
   | cockpit / period rail (CalendarEntry, SchedulePlan, overlay build) | `plan-de-periode-en-overlay.feature` | period → plan → overlay generation → `COMPLETED`; a freed shared-block member re-lands on its pinned partner's slot |
   | coach-wishes rail (campaigns, tokens, public page) | `voeux-des-coachs.feature` | campaign → public token → unauthenticated public page → persisted `CoachWish` |

   Each feature is self-sufficient (mints its own JWT, creates and cleans its own data, reopens then restores the season-plan pointer where relevant) and runs alone or in any order. All 5 also run in CI (`functional-tests` job) — a local run is still the faster feedback. Diagnostics/warnings in the result are acceptable — the pass criterion is the scenario's own `Alors` assertions (e.g. the CP-SAT solver responded and a plan was produced). ⚠️ `generate-schedule-test.sh` is a *mock* unit test of `generate-schedule.sh` (fake `curl`) — it does NOT exercise the solver; never use it as a functional-test substitute.

### Rules
- Backend and engine tests run **inside Docker** (their Makefiles wrap `docker compose exec`). Ensure the stack is up (`make start`) or report clearly that it is not — do not pretend a skipped suite passed.
- Do not invent test paths. The PHPUnit binary is `vendor/bin/phpunit` (PHPUnit 11), wired into `make phpunit` (which includes `--group phase1`). The suite needs the test DB — run `make db-init-test` first if it is not set up.
- `blocking-tests` must pass before the rest of the PHP suite is meaningful (CI order).
- The Behat feature needs async generation to run: `make -C backend behat` starts/restarts `messenger-worker` itself (a queued message left unconsumed keeps the schedule `PENDING`).
