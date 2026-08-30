# Amateo — Engine Agent Context

> Python 3.12 + FastAPI + OR-Tools CP-SAT. Reactive solver microservice.
> **Pointer file** — commands, CI, boundaries, solver principles: see root [`CLAUDE.md`](../CLAUDE.md), [`docs/project-map.md`](../docs/project-map.md) §3 and [`docs/architecture/adr-0001-single-pass-solve.md`](../docs/architecture/adr-0001-single-pass-solve.md). Do not duplicate them here.

## Where things live (no counts — they rot)

- `app/main.py` FastAPI endpoints + solve orchestration · `app/schemas/` Pydantic v2 input/output · `app/solver/` model / constraints / objective / result_builder · `tests/` golden + invariants + hypothesis, fixtures under `tests/fixtures/`.
- Solver detail (constraint families, weights, locks): `docs/engine-inventory.md` §4-5 + [`docs/constraint-vocabulary.md`](docs/constraint-vocabulary.md). There is deliberately no nested `app/solver/AGENTS.md` — it duplicated this file and drifted.
- Contract source of truth: `CONTRACT_VERSION` file at engine root (returned in `/` and metrics).

## Endpoints (verify in `app/main.py` before relying on this)

`GET /` (health + contract) · `GET /health` · `POST /generate` (main) · `POST /implicit-constraints` (validation warnings for the wizard) · `POST /place-matches` (dated match placement, P1-4 PR D — solver in `app/solver/match_placement.py`, schemas `match_input_schema.py`/`match_output_schema.py`, ADR-0003; single worker + seed, golden-pinned like the weekly solve).

## Zone gotchas (facts not in the root docs)

1. **All commands run in the engine container** — `engine/Makefile` wraps `docker compose exec`. Host `pytest`/`ruff` fail without a local venv.
2. **Output `status` literals** are `"queued" | "generating" | "completed" | "failed"` (`app/schemas/output_schema.py` — `Literal`, source of truth).
3. **Score formula** — `SCORE_FORMULA_VERSION` (`app/solver/objective/weights.py` — la constante fait foi, ne pas la recopier ici : la copie V10 a survécu à un bump et menti jusqu'au 2026-08-22 ; `objective.py` est devenu le paquet `app/solver/objective/` depuis, ne pas re-citer un fichier plat). Changing any level-2 weight requires bumping it. Weights table lives in the root spec / `objective/weights.py`, not here.
4. **Two-phase solve** — phase 1 optimal placement (locked), phase 2 bounded 10 s chaining bonus with warm-start. Both phases get the payload seed. See `app/main.py`.
5. **Timeout is payload-driven** — `solver_timeout_seconds` (default 650 s) is a **ceiling only**; the real budget is the adaptive tier computed in `main.py` (`_adaptive_timeout`: 60 / 180 / 600 s by `n_teams × n_venues`).
6. **Workers are adaptive too** — `_adaptive_workers` (`main.py`): complexity ≤200 → 1 worker (deterministic, the golden fixtures depend on it), else → 8 (closes the optimality proof on the stall-prone tier). Above the threshold the objective *value* stays reproducible, the exact assignment does not.
7. **Per-club `asyncio.Lock`** (`_club_locks` in `main.py`) serialises requests per club. ENG-03 is fixed: `_solve` runs in a worker thread (`asyncio.to_thread`), so `/health` answers during a solve, and a global `_solve_semaphore` (`ENGINE_MAX_CONCURRENT_SOLVES`, default 1) bounds CPU contention.
8. **A HARD lock has no variable** — `model.py` skips the `x[...]` key for a HARD-locked slot, so no constraint can reach it (a constraint works by forcing that variable to 0). The lock stays sovereign (ALIGN-07); since P2-9 `diagnose_locked_slot_violations` (`constraints.py`) emits one `constraint_not_honored` **INFO** per (constraint, team, lock) it overrode. Guarded by `tests/semantic/test_hard_lock_announces_violations.py` — structuring axis "constraint semantics".
9. **Uvicorn runs without reload** — after editing engine code, restart the container before any e2e test (stale code otherwise).
10. **Hypothesis** — `.hypothesis/` directory may grow large; safe to delete.
