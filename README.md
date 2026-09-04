# ClubScheduler

**Automated training-schedule generation for basketball clubs (FFBB).**

ClubScheduler builds a club's **per-season weekly training schedule** — placing every
team into a gym time-slot — automatically, with a constraint solver, instead of by hand.

---

## The problem

Every season, a basketball club manager has to fit **dozens of teams** into a **handful of
gyms** across the week. Each placement must respect a tangle of rules at once:

- gyms are only open at certain days/times, and a court can hold a limited number of teams;
- a coach can't be in two places at once, and some coaches are unavailable on given days;
- important teams (senior/élite) get priority over recreational ones;
- teams need a set number of sessions per week, some prefer/avoid a specific gym or time…

Done in a spreadsheet, this is a slow, error-prone combinatorial puzzle. One change
(a gym closes, a coach leaves) forces a manual re-shuffle of everything.

## What ClubScheduler does

1. **Enter the club's data once** (guided wizard): teams + priority ranking, gyms + weekly
   availability, coaches + their teams, and any explicit constraints.
2. **Generate** a full schedule. A **CP-SAT constraint solver** (Google OR-Tools) places
   the teams under the hard rules and optimizes a soft scoring objective (spread, preferences…).
3. **Work the plan**: view it as a week grid (by gym, by coach, or by team), read the
   solver's diagnostics, **lock** the slots you like, tweak, and **regenerate** — the locked
   slots stay put. Promote a plan as the season's **baseline**, and generate secondary plans
   (e.g. holidays) alongside it.

The manager stays in control; the solver does the combinatorial heavy lifting.

## How it fits together

```
 Manager ─▶ Frontend ──/api/*──▶ Backend ──POST /generate──▶ Engine (CP-SAT solver)
   (React)              (Symfony, orchestrates + persists)      (Python, solves)
                            ▲                                        │
                            └──────── results imported ◀────────────┘
                        Backend ──Mercure SSE──▶ Frontend (live generation progress)
```

- **`backend/`** — Symfony 7.4 · API Platform 4.3 · Doctrine ORM. The API + source of truth:
  persists data, enforces **multi-tenant isolation** (one club never sees another's data),
  freezes an input snapshot, calls the engine, imports results, publishes progress.
- **`engine/`** — Python 3.12 · FastAPI · OR-Tools CP-SAT. A stateless solver exposed as
  `POST /generate`. It is **reactive**: it never calls the backend.
- **`frontend/`** — React 19 · Vite · Tailwind 4. The UI: authentication, the **wizard**
  (data entry) and the **work-loop** (view/adjust/regenerate the schedule).

**Hard boundaries:** `frontend → backend` only via `/api/*`; `backend → engine` only via
`POST /generate`; the frontend never calls the engine directly.

## Stack

| Zone | Runtime | Entry point |
|------|---------|-------------|
| `backend/` | PHP 8.4 · Symfony 7.4 · API Platform · Doctrine ORM 3.6 | `public/index.php` |
| `engine/`  | Python 3.12 · FastAPI · OR-Tools CP-SAT | `app/main.py` |
| `frontend/`| TypeScript · React 19 · Vite · Tailwind 4 | `src/main.tsx` |

## Getting started

Backend, engine and frontend tooling all run **inside Docker**.

```bash
make start      # bring up the stack (docker compose, reads .env)
make test       # run backend + engine + frontend test suites
make lint       # code-quality checks
make stop       # stop the stack

make -C frontend dev   # Dockerized Vite on http://localhost:5173 (proxies /api)
```

On a fresh clone `make start` is the only command needed: it builds the images and their
dependencies (including the frontend bundle served by its container on port 8081), installs
the backend and engine development dependencies, then runs `make bootstrap` — which generates
the JWT keypair and creates+migrates the dev database. No host Node.js installation is used by
this bootstrap. Subsequent `make start` calls only start the Docker services. The installation
and bootstrap steps are idempotent.

`make bootstrap` is also the repair command: `make start` never touches the database on its own,
so **re-run it after a `git pull` that brings new migrations**, otherwise the app hits
`permission denied for table app_user` (the RLS grants ride along with the migrations).

The database comes up empty. Demo data is opt-in: `make -C backend fixtures` seeds a club, its
teams and the holiday reference data — it purges the existing rows first.

Per-zone commands live in `backend/Makefile` and `engine/Makefile` (e.g.
`cd backend && make test`, `cd engine && make test`). A functional test drives a full
create → generate → completed run against the real stack (async rail, no browser, no
in-process kernel): `make -C backend behat` (Behat, Gherkin FR,
`features/generation-du-planning-de-saison.feature`).

## Layout

```
backend/   PHP API, persistence, async orchestration (Symfony Messenger)
engine/    Python CP-SAT solver (POST /generate)
frontend/  React UI — auth · wizard (data entry) · work-loop (adjust/regenerate)
docker/    container + local-env assets
specs/     living product/spec docs (initiales / courantes / evolution)
docs/      architecture, testing, glossaire, cartes
```

## Documentation

**Par sous-projet** (chaque zone travaille différemment — commencez par son README) :

| Zone | README | Docs structurantes |
|------|--------|--------------------|
| `backend/` | [`backend/README.md`](backend/README.md) | [`docs/TENANT.md`](backend/docs/TENANT.md) · [`docs/RLS.md`](backend/docs/RLS.md) · [`scripts/generate-schedule.sh`](backend/scripts/generate-schedule.sh) (guide) · [`AGENTS.md`](backend/AGENTS.md) |
| `engine/` | [`engine/README.md`](engine/README.md) | [`docs/business.md`](engine/docs/business.md) (cœur métier) · [`docs/nominal-flow.md`](engine/docs/nominal-flow.md) · [`docs/solver-errors.md`](engine/docs/solver-errors.md) · [`AGENTS.md`](engine/AGENTS.md) |
| `frontend/` | [`frontend/README.md`](frontend/README.md) | [`AGENTS.md`](frontend/AGENTS.md) · [`frontend/docs/frontend-wizard.md`](frontend/docs/frontend-wizard.md) |

**Transverse** :
- **`CLAUDE.md`** — index opérationnel (stack, frontières, conventions) · racine **`AGENTS.md`** y pointe.
- **`docs/project-map.md`** — carte détaillée · **`docs/architecture/`** — ADRs + matrice contrainte UI↔engine · **`docs/testing/`** — stratégie de tests · **`docs/glossary.md`** (termes & payload) · dette & backlog : **`specs/evolution/roadmap.md`**.
- **`docs/security/`** — RLS et ses exceptions · durcissement Mercure · registre RGPD (art. 30).
- **`docs/ops/`** — stack de prod (`docker-compose.prod.yml`) · runbook de déploiement · sauvegardes & restauration.
- **`specs/`** — specs vivantes : `initiales/` (origine figée) · `courantes/` (ce que l'appli fait) · `evolution/` (à venir). Voir [`specs/README.md`](specs/README.md).
- **`docs/archive/`** — documents point-in-time conservés pour trace historique, jamais une source de vérité.

## Status

Active development (V0). Delivered so far: auth, the planning work-loop, and the data-entry
wizard, backed by the CP-SAT engine.

**Not running in a live environment yet — but the road to it is paved**: the production stack
(`docker-compose.prod.yml`, immutable ghcr.io images), the one-command deploy (`make deploy`,
`.github/workflows/deploy.yml`) and the backup/restore runbook are shipped and tested. The SSH half
of the deploy stays dormant until the repo variable `DEPLOY_ENABLED=true`. See
[`docs/ops/`](docs/ops/).
