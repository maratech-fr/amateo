# Amateo — Development Guide

## Quick Start

```bash
# 1. Start all services — on a fresh clone this builds every Docker image (including
#    the frontend bundle), installs backend/engine dependencies, generates the JWT
#    keypair and creates+migrates the dev database. It does not use host Node.js.
#    Subsequent calls only start the Docker services.
make start

# 2. Check health
curl http://localhost:8080/api/health

# 3. Run tests
make test
```

Node.js and npm are never required on the host. Frontend development, build,
lint and tests run through Docker with `make -C frontend dev|build|lint|test`.

The database starts empty; demo data is opt-in (`make -C backend fixtures`). After a `git pull`
that brings new migrations, run `make bootstrap` — `make start` never migrates on its own.

## Architecture

Amateo is a monorepo with three main stacks:

- **backend/** — Symfony 7 + API Platform 4 (PHP 8.4)
- **engine/** — Python 3.12 + FastAPI + OR-Tools CP-SAT
- **frontend/** — React 19 + Vite + Tailwind 4

## Services

| Service | Port | Description |
|---------|------|-------------|
| nginx | 8080 | Reverse proxy |
| php-fpm | — | Symfony API |
| postgres | 5432 | PostgreSQL 16 |
| redis | 6379 | Cache + Messenger transport |
| engine | 8000 | Python solver microservice |
| mercure | 3000 | SSE hub for real-time updates |
| frontend | 8081 | SPA (nginx) — also proxies `/api`, `/exports`, `/bundles`, `/.well-known/mercure`, and `/engine` **in dev only** (the prod edge conf drops that location: the frontend never calls the engine) |
| messenger-worker | — | consumes the Redis queue (generation, PDF export) — **without it a generation stays `PENDING`** |
| cron-runner | — | runs `app:jobs:run-due` every minute (reminders, purges, holiday imports) |
| pdf-worker | — | PDF/PNG rendering (Node) |
| frontend-dev / frontend-tooling | 5173 | Dockerized Vite / npm tooling |
| mailpit | 8025 | Email catcher |

Every port is published on `127.0.0.1` only. To expose the running stack for a remote demo
(HTTPS, no deploy, no port forwarding), see [demo-tunnel-cloudflare.md](demo-tunnel-cloudflare.md).

## Commands

Root orchestration only. Zone commands (`phpstan`, `cs-fix`, `rector`, `phpunit`, migrations…)
live in `backend/Makefile` and `engine/Makefile` — run `make -C backend help` or
`make -C engine help` rather than trusting a copy of the list here.

```bash
make help        # Show all root commands
make start       # Start Docker services (bootstraps a fresh clone)
make bootstrap   # JWT keypair + create/migrate the dev DB (idempotent)
make stop        # Stop Docker services
make test        # Run all tests
make lint        # Run all linters
```

## Production

The dev stack is **not** the prod stack. `docker-compose.prod.yml` is a standalone file (not an
overlay): immutable images pulled by tag from ghcr.io, zero code bind-mount, no dev services
(mailpit, frontend-dev, frontend-tooling). The VM only ever holds `docker-compose.prod.yml`,
`.env.prod` and `jwt/` — never the source code.

```bash
make deploy VERSION=vX.Y.Z   # tag v* → build+push ghcr → deploy over SSH
```

The SSH half stays dormant until the repo variable `DEPLOY_ENABLED=true`, so nothing breaks while
no VM exists. Stack detail: [`../ops/prod-stack.md`](../ops/prod-stack.md) · first-deploy runbook:
[`../ops/deploy.md`](../ops/deploy.md) · backups & Sentry: [`../ops/backup-restore.md`](../ops/backup-restore.md).

## Multi-Tenant Architecture

Most business entities carry `club_id` (and usually `season_id`). The invariant: **a table is
outside RLS if and only if it has no `club_id` column** — the exceptions and accepted residuals are
enumerated in [`../security/rls.md`](../security/rls.md). Three layers of isolation:

1. **Application layer**: Doctrine `TenantFilter` appends `WHERE club_id = ?` to every query on a
   `club_id`-owning entity.
2. **Database layer**: PostgreSQL RLS `FORCE` policies — `app_user` only ever sees its own club's
   rows; no GUC → zero rows, no error (fail-closed).
3. **Application scoping** for the tables without `club_id` (Club, User) in their providers/processors.

The `TenantFilterListener` (priority **7 — AFTER the firewall**) activates the filter on each HTTP
request and sets the GUC via `SELECT set_config('app.club_id', …, false)` — **never `SET LOCAL`**,
which is a no-op outside a transaction (see `../security/rls.md`).

## Tests

The blocking CI gate is roughly a dozen and a half `--group phase1` suites — tenant/season
isolation, RLS, Mercure hardening, management roles, API rate limit, superadmin SA0, engaged-team
perimeter, period-plan birth, backend↔engine contract. **The canonical list is `docs/testing/blocking-tests.md`** —
don't copy it here, it grows.

```bash
make -C backend phpunit          # the blocking gate (--group phase1)
make -C backend tests-complete   # exact CI mirror — run this before pushing
```

## Contributing

1. Create a feature branch
2. Run `make test` before committing
3. Open a PR — CI will run all checks
