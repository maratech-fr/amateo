# Cleanup Candidates — ClubScheduler

> ⚠️ **ARCHIVED (2026-07-29)** — every candidate below was resolved on 2026-07-01, and the file:line
> references no longer point at anything (`objective.py` no longer holds the aliases, `helpers.py`
> now exists). Kept for historical trace only. **Safe deletions and debt now live in a single home:
> [`specs/evolution/roadmap.md`](../../specs/evolution/roadmap.md) §Dette.**

Concrete, **proof-backed** removal/simplification candidates surfaced by the onboarding audit. This is a shortlist of the safest items from `specs/evolution/roadmap.md` §Dette. **Update 2026-07-01: all candidates below are now resolved** — C1→E1 (aliases deleted), C2→E4 (comment removed), C3→E5 (doc fixed), C4→E2 (`helpers.py`), C5→E3 (documented, ADR-0001). Historique : git log de `docs/technical-debt.md` (absorbé roadmap §Dette, 2026-07-11). Each requires an explicit scoped plan + validation before action. Perimeter: backend + engine (frontend excluded).

| # | Candidate | Location | Proof | Suggested action | Risk |
|---|-----------|----------|-------|------------------|------|
| C1 | 3 redundant public aliases of `add_level_2_objective` | `engine/app/solver/objective.py:449–464` (+ `__all__` 728–731, `solver/__init__.py`) | No internal caller (grep); pure pass-throughs | Delete + trim exports | Low — verify no external importer first |
| C2 | False "unused" comment on a used param | `engine/app/solver/constraints.py:110` | Param used at `constraints.py:889–890` | Delete the comment | None |
| C3 | Stale "10s timeout" doc line | `engine/app/solver/AGENTS.md:26` | Real default 650s (`input_schema.py:134`, `main.py:269`) | Fix the line (via `documentation-update`) | None |
| C4 | Duplicated `_MISSING` sentinel | `engine/app/solver/constraints.py:28` & `objective.py:170` | Two distinct sentinel objects | Consolidate into shared `helpers.py` (with C2-area dedup) | Low |
| C5 | Dormant two-pass fallback code | `constraints.py:117`; `result_builder.py:33`; `main.py:122,125` | `fallback_used=False` hardcoded; only tests use the path | **Do not delete** — decide first (document or wire in) | Medium — exercised by tests |

## Explicitly NOT cleanup candidates (proven fine)
- `DevScheduleReportWriter` autowiring exclusion (`backend/config/services.yaml:22`) — intentional dev tool.
- The 6 solver helpers (roadmap §Dette, E2 résolu) — **refactor**, not deletion; they carry behavioural differences that must be reconciled deliberately, not dropped.
- The `phase1` blocking tests — keep (guardrails). `TenantCacheIsolationTest` is **implemented and runs in CI** (part of the blocking-tests gate — `CLAUDE.md` §4 is the canonical list). ⚠ Ne pas figer un compte ici (la liste bloquante s'allonge).

## Process reminder
Any deletion goes through the normal feature cycle: `/plan` with the scope checklist → optional `smith` → your validation → implement → `validation-runner` → `/code-review`. No opportunistic removal.
