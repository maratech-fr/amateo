# Amateo — Agent Context

> **The canonical operational index has moved to [`CLAUDE.md`](CLAUDE.md).**
> This file is kept as a pointer so non-Claude agents still find their way in.

Read order for any agent working in this repo:

1. [`CLAUDE.md`](CLAUDE.md) — short operational index (stack, zones, boundaries, commands, conventions, workflow rules, scope checklist).
2. [`docs/project-map.md`](docs/project-map.md) — detailed repo map (entities, controllers, services, messaging, infra).
3. [`specs/README.md`](specs/README.md) — the 3-tier living specs system; execution specs live in `specs/courantes/`.

Package-level detail stays next to the code:

- [`backend/AGENTS.md`](backend/AGENTS.md) — backend (PHP / Symfony) specifics.
- [`engine/AGENTS.md`](engine/AGENTS.md) — engine / solver specifics.
- [`frontend/AGENTS.md`](frontend/AGENTS.md) — frontend (React / Vite) specifics.

Other context files:

- [`docs/glossary.md`](docs/glossary.md) — business terms and payload keys (one concept = one word).
- [`docs/testing/testing-strategy.md`](docs/testing/testing-strategy.md) — CI pipeline, blocking gate, how to run tests locally.
- [`docs/architecture/adr-index.md`](docs/architecture/adr-index.md) — ADRs + the constraint matrix.
- [`docs/security/`](docs/security/) — RLS and its exceptions · Mercure hardening · RGPD register.
- [`docs/ops/`](docs/ops/) — prod stack (`docker-compose.prod.yml`) · deploy runbook · backups & restore.
- [`docs/technique/DEVELOPMENT.md`](docs/technique/DEVELOPMENT.md) — human quick-start.

Debt, backlog and safe deletions have a **single home**: [`specs/evolution/roadmap.md`](specs/evolution/roadmap.md) — which holds **only what is still open**. What is already delivered, and the decisions deliberately closed against an obvious-looking option, live in [`specs/courantes/etat-des-lieux.md`](specs/courantes/etat-des-lieux.md). Asking "is X done?" is answered there, never by the roadmap. Point-in-time documents that no longer describe the product live in [`docs/archive/`](docs/archive/) — historical trace only, never a source of truth.
