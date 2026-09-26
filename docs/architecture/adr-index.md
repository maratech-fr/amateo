# Architecture Decision Records — Index

This index is the entry point for the repo's ADRs; add one numbered file per structural decision as it is made (or back-filled), and link it from the table below.

## Convention
- File: `docs/architecture/adr-NNNN-short-title.md` (zero-padded, incrementing).
- Status: `proposed` · `accepted` · `superseded by adr-XXXX` · `deprecated`.
- An ADR is warranted when a decision is **structural** (boundaries, data model, cross-zone contract, security model, infra topology) — not for routine changes.

## Template
```
# ADR-NNNN — <title>
- Status: <proposed|accepted|…>   Date: <YYYY-MM-DD>
- Context: <forces, constraints, what made this a decision>
- Decision: <what was chosen>
- Consequences: <trade-offs, follow-ups, what this rules out>
- Alternatives considered: <options + why rejected>
```

## Index
| ADR | Title | Status |
|-----|-------|--------|
| [ADR-0001](adr-0001-single-pass-solve.md) | Single-pass solve, no silent fallback | Accepted — amended: a two-phase lexicographic optimisation (placement, then chaining, adaptive budget/workers) plus two tie-break tiers (stability, proximity to the previous placement) order among feasible solutions — none of it ever relaxes a HARD constraint |
| [ADR-0002](adr-0002-pattern-plan.md) | Pattern « Plan » : un plan nommé, des versions, un pointeur | Accepted — amended : le Plan (SEASON/CLOSURE/HOLIDAY) porte le nom, les versions (`Schedule`) et le pointeur choisi ; une période POSSÈDE sa grille de gymnases et ses 4 règles de bien-être en COPIE (jamais union) ; deux plans ne se chevauchent jamais (le SEGMENT en est l'unité hors socle) ; reprendre le socle détruit tout plan de période pas encore commencée |
| [ADR-0003](adr-0003-match-placement-solve.md) | Le solve de placement des matchs : second problème engine (`/place-matches`), rail synchrone, best-effort à poids dominant, ancres `placementSource` | Accepted (2026-08-03, P1-4 PR D) |
| [ADR-0004](adr-0004-period-plan-birth-as-socle-copy.md) | L'adaptation naît comme une COPIE du socle : la V1 d'un plan de période transcrit (sans solveur) la version pointée du socle, filtrée de la sélection de période ; verrous HARD révocables, pas de pointage auto, réduction déterministe | Accepted — amended : un solve PARTIEL comble les séances « à replacer » (référence socle au moteur), la transcription devient le défaut à l'arrivée sur une fermeture vierge (HOLIDAY exclu), et une route de lecture nomme les écarts (déplacée/non replacée) vs le socle |

*Non-ADR hosted here*: [`constraint-matrix.md`](constraint-matrix.md) — the UI ↔ engine constraint matrix (structuring axis §7.1 "constraint semantics"), including why a HARD lock overrides an entered HARD constraint and how it now says so.

## Candidate decisions to formalize
These are existing, load-bearing decisions found during onboarding that are currently implicit. Promote to ADRs when touched (do not invent rationale retroactively without confirming intent):

1. **Multi-tenant isolation = Doctrine `TenantFilter` + `ClubUser` membership check + PostgreSQL RLS** (GUC `app.club_id` set through `set_config`, session-scoped — **never `SET LOCAL`**, which is a no-op outside a transaction). Security-critical, guarded by `TenantIsolationTest` and `RlsIsolationTest`. Refs: `backend/docs/TENANT.md`, `backend/docs/RLS.md`, `../security/rls.md`.
2. **Backend↔engine contract is hand-synced (no codegen)**, versioned via `engine/CONTRACT_VERSION`, guarded by `ContractSchemaTest`. Why no codegen?
3. **Async generation via Symfony Messenger + Redis + per-club lock**, progress over Mercure (`club:{id}:schedule:{id}`). Why this topology over synchronous generation.
*Formalized / resolved:* the two-pass fallback decision, the **solver budget** (adaptive 60/180/600 s tiers, the payload's `solver_timeout_seconds` acting as a ceiling only, plus the generation-complexity cap) → [ADR-0001](adr-0001-single-pass-solve.md); the Rector 8.3-vs-8.4 mismatch was fixed in code (Rector now targets 8.4), not via ADR — see roadmap §Dette (B1, résolu).
