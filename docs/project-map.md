# Project Map — Amateo (engine + backend)

Last verified @ 2026-08-31 (P2-51 PR-7, `documentation-update` — mention du contrat recalée
2.18→2.19, confrontée à `engine/CONTRACT_VERSION` : le modèle groupe {équipes, K}
(`sharedTrainings`/`SharedTrainingGroupSchema`) est retiré des deux endpoints qui le portaient,
`sharedBlocks` devient la SEULE mutualisation. ⚠ Vérification volontairement ÉTROITE : le reste de
la carte n'a pas été reconfronté au code ce jour)

Detailed companion to the short index in [`/CLAUDE.md`](../CLAUDE.md). Frontend has been **rebuilt (React 19) and is active** — features live under `frontend/src/features/` (`ls` it, no count here — it rots): `auth`, `wizard` (data entry), `planning` (work-loop), `cockpit`, `matches`, `coach-wishes` (doléances), `club`, `profile`, `season-transition`, `legal`, `feedback` (bouton + dialogue de signalement), `release-notes` (journal + modale « quoi de neuf ») et `admin` (console superadmin, garde et session distinctes) ; voir `../frontend/docs/frontend-wizard.md` et `frontend-spec.md`. Generated/verified during onboarding against the real code and the `code-review-graph` knowledge graph.

---

## 1. Repository layout

```
backend/   PHP 8.4 · Symfony 7.4 · API Platform 4.3 · Doctrine ORM 3.6 · Messenger · Mercure · JWT
engine/    Python 3.12 · FastAPI · OR-Tools CP-SAT
frontend/  TS · React 19 · Vite · TW4  (active: auth · planning work-loop · wizard)
landing/   Page de vente publique — HTML/CSS statique PUR (zéro build, AUCUN lien avec frontend/) ;
           marque + liens dans landing/config.js (une source) ; en prod : servie par CADDY sur la VM
           (`file_server`, docs/ops/Caddyfile.example) — PAS un vhost nginx : les deux nginx du compose
           écoutent en 80 DERRIÈRE Caddy, qui tient la TLS et aiguille par domaine (P5-5)
specs/     Living specs (initiales / courantes / evolution) — see specs/README.md
docs/      This documentation set + docs/technique/
docker/    Per-service Dockerfiles (php, frontend, pdf-worker, postgres, …) + edge nginx confs
docker-compose.yml       dev stack   ·   docker-compose.prod.yml   prod stack (§4)
.github/workflows/       ci.yml (CI pipeline) + deploy.yml (build-push ghcr + deploy SSH)
contracts/ EMPTY placeholder (no codegen yet)
tests/     EMPTY placeholder (cross-stack tests currently live in backend/tests/)
```

All services share the Docker network `amateo_network`.

---

## 2. Backend (`backend/`)

**Entry point:** `public/index.php` → `src/Kernel.php` (`MicroKernelTrait`). Active bundles: API Platform, Doctrine ORM, Messenger, Mercure, LexikJWT, Security, Twig, CORS (Nelmio), DoctrineFixtures, DAMADoctrineTest.

### 2.1 Source layout (`backend/src/`)
| Dir | Content (no counts — they rot; `ls` the dir) |
|-----|---------|
| `Entity/` | Doctrine entities (UUID ids, scalar FK columns) |
| `ApiResource/` | API Platform resource DTOs (drive `/api/*`) |
| `Controller/` | custom controllers (see 2.3 for the structuring ones) |
| `Message/` + `MessageHandler/` | async messages + handlers (generate, PDF export) |
| `Service/` | business services (snapshot builder, result importer, PDF, FFBB importer, locks…) |
| `Repository/` | Doctrine repositories |
| `EventListener/` | tenant/season resolution (`TenantFilterListener`) + the security-bearing subscribers: `ApiRateLimitSubscriber` (SEC-11), `SeasonReadonlyGuardListener` (409 on an archived season), `AdminAuditSubscriber` (SA0 fail-closed audit), `Login{Success,Failure}Listener`; plus `CacheInvalidationListener`, `ScheduleGenerationFailureListener`, `TeamTagSyncListener`, `{Messenger,CronRunner}HealthSubscriber` |
| `Doctrine/Filter/` | `TenantFilter` (SQL-level tenant scoping) |
| `State/`, `Dto/`, `Enum/`, `DataFixtures/`, `Command/` | supporting code |

### 2.2 Domain entities (core)
- **Club** — root tenant: `slug` (unique), `ffbbClubCode`, `planId`, `billingCycle`, `timezone`, `locale`, `onboardingCompleted`.
- **User** (global) + **ClubUser** (membership junction: `clubId`, `userId`, `role`, `isActive`; unique `(clubId,userId)`) — access control pivot. **SuperAdmin** est une identité d'exploitation séparée, hors tenant, chargée uniquement par le firewall stateful `/api/admin/**` après mot de passe + TOTP.
- **Season** — per-club: dates, `status`, `transitionData`.
- **Team** — per-club-season: `sportCategoryId`, `priorityTierId`, `level`, `gender`, `size`, `sessionsPerWeek`, `forcedVenueId`, `parentTeamId`.
- **SchedulePlan** (ADR-0002) — the named plan and its pointer: `type` (`SEASON|CLOSURE|HOLIDAY`), `name`, `chosenScheduleId` (**the version that counts**), `calendarEntryId` (period plans), `lastVersionNumber` (monotonic). The **SEASON** plan and the version it points at **ARE** the season's calendar; a plan with no pointer is an "espace de travail".
- **Schedule** — per-club-season **version** of a plan: `status` (`DRAFT|PENDING|GENERATING|COMPLETED|FAILED`), `score`, snapshot data/hash, inline solver metrics, `schedulePlanId` + `versionNumber` (both **NOT NULL** since ADR-0002 lot D-a — "a version without a plan does not exist" is sealed in the DB). "Socle or overlay?" is read from `plan.type`, never from the schedule: `Schedule.calendarEntryId` was **dropped** (lot C4-PR2) as a duplicate anchor. `SolverMetric` stores the immutable per-attempt history for SA1. **"Validated" is not a status** — it is derived from the plan's pointer (read field `Schedule.isChosen`); the planning lifecycle is a structuring axis (§7.1).
- **Coach**, **Venue**, **Constraint**, **Reservation** (durable team→slot HARD pins; `schedulePlanId` null = base, non-null = that period plan's layer; fed to the engine as `slotTemplates` — distinct from ScheduleSlotTemplate which stores solve *results*), **ScheduleSlotTemplate**, **ScheduleDiagnostic**, **TeamCoach** / **CoachPlayerMembership** (coach↔team / player↔team links), …
- **Period-owned grid** (#8, 2026-07-24): **VenueTrainingSlot** carries a `schedulePlanId` (null = the season model). At plan birth the whole season grid is **copied** onto the plan (`SchedulePlanProvisioner`/`VenuePeriodGrid::copySeasonalSlots`) — there is **never** a season ∪ period union. **VenuePeriodOverride** is the sparse per-(plan, venue) setting, two independent optional fields: `mode` (nullable) — `DISABLED` (grid kept, venue removed from the engine payload) / `BLANK` (grid emptied) / no row = inherit (going back to inherit re-empties then **re-copies** from the season model) — and `dayOverrides`, a manual day mask (ISO weekday 1..7 → OPEN|CLOSED) that ADDS to the default derived from a dated closure. **Informative unavailability** (founder decision 2026-08-18, supersedes the P2-37 morning lockout): a dated closure no longer locks the setting — it PRE-FILLS a default that the plan's mask can override day by day. The composition (incident × mask) lives in the single home `PlanVenueClosures::effectiveStateForPlan/Entry`, shared by the gate, the payload builder, `OrphanPinGuard`, reservations and the radar. A pin that no longer lands on any slot of that grid blocks generation with a **422** naming venue, day and team (`OrphanPinGuard`, called by `GenerateScheduleController`) — never filtered silently. **Exceptions (non-blocking, the pin is inert): a DISABLED venue** (P3-20) **or a venue EFFECTIVELY fully closed over the window** (per the composed state above; TRANSVERSAL since P2-38: `PlanVenueClosures` applies a closure carried by ANY calendar entry once its window overlaps the plan's, not just the plan's own entry) — a day EFFECTIVELY closed (by the default or by a `CLOSED` mask entry) on an otherwise-open venue still blocks, since the session would otherwise be silently relocated; a day reopened by the mask no longer does. POST/PUT/DELETE on `VenuePeriodOverride` are accepted again on a fully-closed venue (DELETE purges both mode and mask).
- **Cockpit temporel** (paliers A/B/C): **CalendarEntry** — per-club-season dated entries, `kind` (`PERIOD` closure/holiday/… `| EVENT` club events), `startDate`/`endDate`, `status`. It carries the **FACT**; the plan carries the RESPONSE — `overlayScheduleId` was **dropped** (ADR-0002 lot D-b), "period → active version" now derives from its plan (`SchedulePlanProvisioner::chosenOfPeriodPlan`). Period settings hang off the **plan** (`TeamPeriodOverride`, `ConstraintPeriodOverride`, the copied grid, `VenuePeriodOverride`, reservations); the **dated constraints of the fact stay on the entry** (they feed the conflict radar). Reference tables **global, no `club_id`, no RLS** (pattern `public_holiday`): **SchoolHolidayPeriod** (13 academic zones) + **PublicHoliday** (national + territory), seeded from the official APIs. Reminder ledgers (global): **PeriodReminderLog**, **TransitionReminderLog**.
- **Module matchs** (palier A): **Competition** + **Fixture** (per-club-season, `externalRef` = FBI import key), **LeagueMatchWindow** (global catalog, league × category × level → allowed kickoff windows).
- **Doléances coachs** (#10, 2026-07-25/26): **CoachWishCampaign** (bounded collection: weeks × teams × deadline), **CoachWish** (a coach's wish for a week — sessions wanted, unavailable days, free-text comment; **not a constraint**, zero solver effect) and **CoachWishToken** — the coach's personal link. Its `token` is a 32-byte random secret **stored in clear** (founder decision: the manager must be able to re-copy the link), it carries `club_id` so the **login-less** public request can set the GUC, and its table is under **hybrid RLS** (open `SELECT` for the pre-GUC lookup, tenant-scoped writes) — see `docs/security/rls.md`.

### 2.3 API layer
- **Auto CRUD (API Platform):** `/api/{schedules,clubs,teams,coaches,venues,constraints,seasons,sport-categories,…}` — one resource per exposed aggregate (`ls backend/src/ApiResource/`), default pagination 30/page (custom providers return a paginator so `hydra:totalItems` is the real count — BCK-05). OpenAPI at `/api/docs`. `Team` collection honors `?seasonId=` / `?isActive=` (wired in `TeamStateProvider::applyRequestFilters`).
- **Custom controllers** (structuring ones; full list = `ls backend/src/Controller/`):
  | Controller | Route | Action |
  |-----------|-------|--------|
  | `GenerateScheduleController` | `POST /api/schedules/{id}/generate` | dispatch `GenerateScheduleMessage` |
  | `ValidateScheduleController` / `ReopenScheduleController` | `POST /api/schedules/{id}/{validate,reopen}` | planning lifecycle (ADR-0002): validate = the plan **points** at the version + its siblings are **deleted**; reopen = the plan **un-points** it |
  | `ExportPdfController` | `POST /api/schedules/{id}/export-pdf` | dispatch `ExportPdfMessage` |
  | `ImportController` | `POST /api/clubs/{id}/import-teams` | XLSX import via `FfbbExcelImporter` |
  | `ManualEditController` | `POST /api/schedule-slots/{id}/manual-edit/{constraint,lock,one-time}` | manual slot edits |
  | `ReorderTeamsController` | `POST /api/teams/reorder` | atomic tier/rank commit (wizard sort mode) |
  | `MembershipController` | membership approval endpoints | pending-member workflow |
  | `ClubLogoController` / `ClubAppearanceController` | club logo upload / accent colors | club visual identity |
  | `ResetSeasonController` | `DELETE /api/reset-season` | batch-delete season data |
  | `SeasonTransitionController` | `POST /api/seasons/{id}/transition` | copy N→N+1 draft (transition P1) |
  | `CalendarEntryConflictsController` | `GET /api/calendar-entries/{id}/conflicts` | cockpit "séances à replacer" radar |
  | `SchoolHolidaysController` / `PublicHolidaysController` | `GET /api/{school,public}-holidays` | holiday display feeds (club zone) |
  | `LeagueMatchWindowsController` / `FixtureConflictsController` | `GET /api/league-match-windows`, `GET /api/fixtures/conflicts` | match envelope + conflict radar (same-coach + VENUE_UNAVAILABLE) |
  | `VenueUnavailabilityImpactController` | `GET /api/venue-unavailability-impact` | alert-only impact of venue closures (placed matches + effective-schedule trainings) |
  | `ImportFixturesAnalyzeController` / `ImportFixturesController` | `POST /api/fixtures/import/analyze`, `POST /api/fixtures/import` | club-wide FBI import, one pass: dry-run mapping table → import with validated Division↔team mappings (diff/update by FBI number) |
  | `RegenerateController` / `RegenerateFromVersionController` | `POST /api/schedules/{id}/{regenerate,regenerate-from}` | new version of the same plan (guards refuse to overwrite the version the plan points at) |
  | `TranscribePeriodPlanController` | `POST /api/schedule_plans/{id}/transcribe-from-socle` | ADR-0004 (P2-44 PR-1): a versionless PERIOD plan gets its V1 by **copying** the pointed SEASON version instead of solving — filtered by the period selection, HARD-locked, no auto-pointer |
  | `FillPeriodPlanController` | `POST /api/schedules/{id}/fill` | ADR-0004 (P2-44 PR-3, "le comblement"): a PARTIAL solve on a PERIOD version — the source version's placements are pinned HARD **in the payload only** (never persisted), the solver places only the gaps; mirrors `RegenerateController` but bounded to a period plan, zero engine/contract change |
  | `SocleDeviationController` | `GET /api/schedules/{id}/socle-deviation` | ADR-0004 (P2-44 PR-5, "les écarts nommés"): read-only diff of a CLOSURE period version against the pointed SEASON version — `moved` + `unplaced` (reason derived from the period selection, **nullable** when it does not explain the absence); neither unchanged nor new sessions are reported. No management gate (a read, open to Members), no write at all |
  | `ValidateConstraintsController` | `POST /api/constraints/validate` | pre-solve check (per **plan**: its settings + the dated constraints of the fact) |
  | `VenuePeriodGridActionController` | `POST /api/venue_period_overrides/{reset-grid,clear-grid}` | #8 period grid **actions** (re-copy from the season model / empty it) — actions, not states, hence not a `PUT` of a mode |
  | `PublicCoachWishController` | `GET` + `POST /api/coach-wishes/public/{token}` | **PUBLIC_ACCESS — no JWT.** The token carries identity *and* club (it sets the `app.club_id` GUC, always released in `finally`). Per-IP rate limit **before** any lookup, byte-identical 404 for unknown vs malformed (anti-enumeration), writes bounded to the token's scope, `410` past the deadline |
  | `CoachWishCampaignActionController` | `POST /api/coach_wish_campaigns/{id}/{send-links,remind}` | mail the coaches their personal link / chase the non-responders |
  | `RgpdExportController` / `DeleteAccountController` | `GET /api/{me,club}/export`, `DELETE /api/me` | RGPD portability (art. 20) + erasure (art. 17) |
  | `Admin*Controller` (auth, jobs, monitoring, audit-log, club actions, messenger-failed, system-errors) | `/api/admin/**` | **superadmin SA0**: separate stateful firewall, password + mandatory TOTP, session-CSRF, per-IP throttle. A club JWT never crosses it and the admin session never sets `app.club_id` |
  | `AuthController` / `PasswordController` | `POST /api/register`, `GET /api/me`, password reset | auth (JWT) |
  | `HealthController` | `GET /api/health` | `{"status":"ok"}` |
- Plain `#[Route]` controllers are excluded from API Platform's auto OpenAPI; `App\OpenApi\CustomRoutesOpenApiFactory` re-adds them to the schema by **composing one `CustomPathContributor` per domain** (`backend/src/OpenApi/PathContributor/`, since P4-138 2026-08-30 — detail: `backend/docs/backend-inventory.md` §OpenAPI). **All of them are declared since P4-47 (2026-08-11)** — `EveryCustomRouteIsDocumentedTest` confronts the factory to the *router* in both directions, with an empty exemption baseline, so a new custom route without an entry in its domain's contributor fails CI. Routes declared as API Platform operations on their resource (import, fixtures import) appear automatically. Regenerate the snapshot on any API change: `docker compose exec php-fpm php bin/console api:openapi:export --output=/app/specs/courantes/openapi-snapshot.json`, then update `openapi-snapshot.meta.md`. ⚠ Opcache sits above the Symfony cache — `docker compose restart php-fpm` first, or the export re-emits stale docblocks (`backend/AGENTS.md` §17).

### 2.4 Async / messaging
- **Transport:** Redis (`redis://redis:6379/messages`), `sync://` under test. Worker: `messenger-worker` container. Bounded `retry_strategy` (3 retries) + `failure_transport: failed` (`MESSENGER_FAILURE_TRANSPORT_DSN`, boot-safe default) — exhausted messages are preserved, never silently dropped.
- **`GenerateScheduleMessage`** (`scheduleId`, `clubId`, `timeoutSeconds`=650) → **`GenerateScheduleHandler`** (orchestration only — BCK-04): acquire `ClubGenerationLock` → frozen snapshot (`ScheduleConstraintBuilder`) → `EngineClient.solve()` (`POST http://engine:8000/generate`) → import via `ScheduleResultImporter` → `SolverMetricsMapper` + `ScheduleDiagnosticsRecorder` → **flush (persist result)** → `ScheduleProgressPublisher.publishSafely()` (Mercure **best-effort** — the frontend polls as fallback, so a publish failure never discards a persisted solve).
- **Terminal-status guarantee (BCK-01):** a schedule never freezes in `PENDING`/`GENERATING`. Three nets: (1) the handler catch-all clears the dirty unit-of-work and marks `FAILED` on any uncaught error; (2) `ScheduleGenerationFailureListener` (`WorkerMessageFailedEvent`, `willRetry()===false`) terminates permanently-failed messages (e.g. lock-exhaustion); (3) `app:schedules:reconcile-stuck` fails `GENERATING` schedules older than `--older-than` minutes (worker crash/OOM) — executed every 10 minutes by the `cron-runner` compose service. PENDING is left to nets (1)/(2) to avoid racing a legitimately-queued message.
- **Operational jobs (SA3-A/B/C/D):** `cron-runner` exécute `app:jobs:run-due` chaque minute. `AdminJobCatalog` est une allowlist de dix jobs à arguments et horaires fermés (`Europe/Paris`) : reconcile toutes les 10 min, rappels et purges quotidiens, imports vacances/fériés trimestriels. Le tick rattrape au plus le dernier créneau manqué ; `scheduled_for` et un index unique empêchent le doublon par `(job, créneau)`, tandis que le verrou advisory empêche le chevauchement. `AdminJobRunStore` écrit via la connexion `admin` dans `admin_job_run` (aucun privilège `amateo_app`, aucun output/message d'exception persisté). `GET /api/admin/jobs` rapproche le catalogue du dernier run, expose le prochain passage et `manualTriggerAllowed`. `POST /api/admin/jobs/{key}/run`, session + CSRF, relance uniquement les deux imports idempotents avec source/acteur `superadmin`; aucune purge ou commande brute n'est acceptée.
- **`ExportPdfMessage`** → **`ExportPdfHandler`**: `PdfGenerator.generate()` → publish Mercure with export URLs.
- **Mercure topic:** `club:{clubId}:schedule:{scheduleId}` (validated non-empty). `MERCURE_URL` env.
- **`ClubGenerationLock`** (Redis): key `schedule_generation:club:{clubId}`, atomic `SETEX NX` + TTL, **atomic token-checked release** (Lua compare-and-delete — BCK-02; a GET-then-DEL could delete another worker's lock after a TTL-expiry race).

### 2.5 Multi-tenant isolation (security-critical)
1. `TenantFilter` (Doctrine SQL filter) appends `{table}.club_id = :param` on entities owning a `club_id` column (fail-secure — column-based, not marker-based); registered in `config/packages/doctrine.yaml`. Entities also carry the explicit `App\Entity\TenantOwnedInterface` marker (BCK-03) that drives the **app-layer** State provider/processor guards via `instanceof` (replacing `method_exists` duck-typing); `TenantOwnedInterfaceCompletenessTest` keeps the marker set ≡ the club_id-column set.
2. `TenantFilterListener` (kernel REQUEST, **priority 7 — AFTER the firewall (8)**; source: `backend/src/EventListener/TenantFilterListener.php`): resolves club from `_club_id` attr / `X-Club-Id` header / **else the authenticated JWT user's active `ClubUser` membership** (the frontend sends no header). Spoofed header without matching membership → 403. Enables the Doctrine filter and sets the `app.club_id` GUC via `TenantConnectionContext` (`set_config`). ⚠ Priority 8 (before auth) was the historical cross-club leak bug — never move it back. **RLS is ACTIVE** (migration `Version20260703120000`, SEC-03): FORCE policies on all `club_id` tables, runtime = `amateo_app`; migrations/ops via the `admin` connection (`amateo_owner`, bypasses RLS = superadmin door). 3 layers: Doctrine filter + RLS + provider/processor scoping for Club/User. See `backend/docs/TENANT.md`, `docs/security/rls.md`.
3. Cache pool `cache.schedule` (4h, Redis, tag-aware) — le payload solveur, purgé par TAG club via `CacheInvalidationListener` à la fin du travail (kernel.terminate ET événements worker Messenger, P2-11). Le pool `cache.tenant` a été supprimé (P2-12 : jamais aucun writer).
- Reference docs: `backend/docs/TENANT.md`, `backend/docs/RLS.md`.

### 2.6 Tooling (verified)
- **PHPStan** level 8 (`phpstan.neon`, Doctrine+Symfony ext).
- **CS-Fixer** (`.php-cs-fixer.dist.php`): `@Symfony` risky + `@PHP84Migration` + `@PHP80Migration:risky` + Yoda + strict comparisons + trailing commas.
- **Rector** (`rector.php`): `withPhpVersion(80400)`, aligned with composer `>=8.4`.
- **PHPUnit**: direct `vendor/bin/phpunit` (11.5.55, `phpunit/phpunit ^11`) in CI, `Makefile`, and `composer test`; schema `vendor/phpunit/phpunit/phpunit.xsd`. `make phpunit` adds `--group phase1`; the suite needs `make db-init-test` first.
- `config/services.yaml`: autowire all `App\*` except `DevScheduleReportWriter` (dev-only tool).

---

## 3. Engine (`engine/`)

**Entry point:** `app/main.py` (FastAPI). Routes: `GET /`, `GET /health`, `POST /generate` (main), `POST /implicit-constraints` (sync enabled implicit rules with backend).

### 3.1 Modules
| Module | Role |
|--------|------|
| `app/main.py` | FastAPI app, route handlers, per-club locking, solve orchestration |
| `app/core/config.py` | `Settings` (pydantic-settings, env prefix `ENGINE_`) |
| `app/schemas/input_schema.py` | `ScheduleInputSchema` (+ Venue/Team/Coach/Constraint/SlotTemplate); `solver_timeout_seconds`=650, `solver_seed`=42 |
| `app/schemas/output_schema.py` | `ScheduleOutputSchema`, `ScheduleSlotSchema`, `DiagnosticSchema`, `SolverMetricsSchema` |
| `app/solver/model.py` | `ScheduleCpModel(cp_model.CpModel)`, `build_model`, slot/lock/capacity extraction |
| `app/solver/constraints/` | **Paquet** (ENG-32) : Level-1 hard constraints (`structural`/`wellness`/`targeting`), `parse_v2_constraints()` (`parsing`), `diagnose_locked_slot_violations()` (`diagnostics`) — façade `__init__` à surface d'import inchangée, orchestrateur inclus (couture de test) |
| `app/solver/objective.py` | Level-2 soft objective, tiered placement scoring, bonuses, `SCORE_FORMULA_VERSION` |
| `app/solver/result_builder.py` | CP-SAT solution → output schema + diagnostics |
| `app/solver/helpers.py` | shared sentinels/utilities (deduplicated out of constraints/objective) |

### 3.2 Solve pipeline (`POST /generate`)
parse `ScheduleInputSchema` → `build_model()` → `parse_v2_constraints()` → `add_level_1_hard_constraints()` + time-window constraints → `add_level_2_objective()` → solve → `build_result()` → `ScheduleOutputSchema` with diagnostics. Per-club serialization via `_club_locks: dict[str, asyncio.Lock]` (+ `_club_locks_guard`).

**The solve budget is adaptive, and the payload is only a ceiling** (`main.py`): `max_time_in_seconds = _adaptive_timeout(n_teams, n_venues, solver_timeout_seconds)` — tiers 60/180/600 s by `n_teams × n_venues`, the payload's `solver_timeout_seconds` capping them, **never** setting them. `num_search_workers = _adaptive_workers(n_teams, n_venues)` is adaptive too: 1 below a complexity of 200 (deterministic — the golden fixtures depend on it), 8 above (the single worker *finds* the optimum fast on dense soft-preference problems but cannot *prove* it; the portfolio closes the proof, at the cost of a non-deterministic **assignment** — the objective **value** stays stable). `random_seed = solver_seed`. Phase 2 (chaining) reuses the same worker count under a hard `CHAINING_PHASE_MAX_SECONDS` cap. See [ADR-0001](architecture/adr-0001-single-pass-solve.md).

### 3.3 Contract
`engine/CONTRACT_VERSION` holds the version (currently `2.19` — `sharedTrainings`/`SharedTrainingGroupSchema` removed, the `sharedBlocks` block is now the only mutualisation, P2-51 PR-7; `2.18` — `candidates`/`references` lists replace the singular `candidate`/`reference` on `/validate-assignments` (P2-51 PR-5b: the verdict judges N moves under ONE verdict — the atomic block move, rail `/move-group` — on the FINAL state, never N sequential judgments; single list form, a 1-element list IS the single case; `/generate` goldens unchanged); `2.17` optional input block `sharedBlocks` (P2-51 PR-2: mutualisation blocks — N teams declared to behave as ONE team {id, teamIds 2..10, commonSessions>=1}, their sessions belong to the block; ACCEPTED but NOT consumed by the solver yet — block semantics are PR-3; absent/empty ⇒ byte-identical payload); `2.16` optional input block `venueTravelTimes` (P2-53 RMM-8 PR-2: per-venue-pair travel matrix {venueAId, venueBId, drivingMinutes?, walkingMinutes?}) + coach `isVehicled` + implicit rule `travelTime` CONSUMED by the training solver (least-travel tiebreak in the phase-2 sub-band + insufficient-gap PREFERRED compromise / MANDATORY hard block; opt-in on matrix presence; absent/empty ⇒ byte-identical payload); `2.15` optional input block `slotRotations` (RMM-5 A/B rotation PR-2: shared match slots {venueId, dayOfWeek, kickoff, teamIds} CONSUMED as SOFT by `/place-matches` — slot attraction + window protection at strict parity with habits; absent/empty ⇒ byte-identical payload); `2.14` optional input block `teamLinks` (lot PASSERELLES PR-1: declared bridges between two teams {id, teamAId, teamBId, intensity PREFERRED/MANDATORY}, ACCEPTED but NOT consumed by the solver yet — training consumption is PR-2; absent/empty ⇒ byte-identical payload); `2.13` optional `maxConsecutiveDays` in `implicitRules`; `2.12` optional input block `sharedTrainings` (P2-27: N teams declared to train together, EXACTLY K common sessions, reified both ways; absent/empty ⇒ byte-identical payload); `2.11` added the optional input field `previousAssignments` feeding the generation stability term folded into phase 2 (P3-21 PR A, engine side); now emitted in production by `RegenerateController`/`GenerateScheduleHandler` (P3-21 PR B, backend side, **lot closed**) as the version the manager is currently looking at, injected into the solver payload after the snapshot hash is computed so it never enters `snapshotHash`/`currentStructureHash` (absent/empty on a first generation ⇒ byte-identical payload, goldens + score unchanged) ; `2.10` added named compromises on `/validate-assignments`: optional input field `reference` (the move's origin placement) + output `compromises[]` on an ACCEPTED candidate, the solver-evaluated delta between two frozen states against the same `/generate` objective, P2-32 PR A ; `2.9` dropped the dead `temporaryLock`/`temporaryLockFor`/`temporaryMinSessionsOverride` slot fields the solver never read ; `2.8` made `session_below_effective_min` carry the structured cause of a missing session, measured at constraint-posting time: `causes[]` + `openCandidates`, P4-99 PR-1 ; `2.7` added the optional `implicitRules` block: adjustable intensity/thresholds for the 4 well-being rules, P2-28 PR 1 ; `2.6` added the P5-10 capacity metrics as optional response fields ; `2.5` dropped the dead `allowMultipleSessionsPerDay` team flag, P4-79 ; `2.4` added the `/validate-assignments` verdict endpoint, P2-2 F2a ; `2.3` dropped `maxDaysOverrideConfirmed`, P4-51 ; `2.2` added the `/place-matches` second problem, P1-4 PR D ; `2.1` added the coach hour-window fields, #195). Backend syncs the version via `GET /` and enabled implicit rules via `POST /implicit-constraints`. No codegen — Pydantic (engine) ⇄ payload (backend) are kept in sync manually; `ContractSchemaTest` + `MatchPlacementContractSchemaTest` + `ValidateAssignmentsContractSchemaTest` (backend) are the guardrails.

### 3.4 Tooling (verified) — see `pyproject.toml`
ruff (line 120, py312, double quotes, LF) · mypy `strict` + `pydantic.mypy` (`ortools.*` ignored) · pytest `-ra` + pytest-timeout + pytest-cov + hypothesis · bandit (excludes `tests`). Runtime deps: `fastapi`, `pydantic`, `pydantic-settings`, `ortools 9.11.x`, `uvicorn[standard]`.

---

## 4. Infrastructure

- **Orchestration (dev):** root `docker-compose.yml` (reads `.env`; template `.env.dist`).
- **Services (dev):** PostgreSQL 16 (`amateo-postgres`), Redis 7 appendonly (`amateo-redis`), Mercure hub (`amateo-mercure` — signed with the **dedicated `MERCURE_JWT_SECRET`, never `JWT_PASSPHRASE`**: the two being the same value *was* SEC-06, and `MercureHardeningTest` now blocks its return), Mailpit (`amateo-mailpit`), `pdf-worker` (Node), `php-fpm` + nginx, `engine`, **`messenger-worker`** (consumes the Redis queue — without it a generation stays `PENDING`), **`cron-runner`** (`app:jobs:run-due` every minute), `frontend` (nginx :8081) and the dev helpers `frontend-dev` / `frontend-tooling`. Every service has a Docker healthcheck. Details on the hub: [`security/mercure.md`](security/mercure.md).
- **Prod (`docker-compose.prod.yml`, P0-2/INF-03):** a **standalone** file, not an overlay of the dev compose — immutable images pulled by tag from ghcr.io, **zero code bind-mount**, no dev services (mailpit, frontend-dev, frontend-tooling), third-party images pinned (e.g. `dunglas/mercure:v0.19` where dev rides `:latest`), secrets declared `${…:?}` so the stack refuses to boot on a missing one. The VM only ever holds `docker-compose.prod.yml`, `.env.prod` and `jwt/`. Deploy = tag `v*` → build-push ghcr → SSH (`.github/workflows/deploy.yml`, `make deploy VERSION=vX.Y.Z`); the SSH half stays dormant until the repo variable `DEPLOY_ENABLED=true`. Detail: [`ops/prod-stack.md`](ops/prod-stack.md) · runbook: [`ops/deploy.md`](ops/deploy.md) · backups & Sentry: [`ops/backup-restore.md`](ops/backup-restore.md).
- **Edge routing:** dev `npm run dev` on host (:5173) proxies `/api`→8080, `/exports`→8080, `/.well-known/mercure`→3000 — and **no `/engine` proxy** (removed, FRT-17: the frontend never calls the engine directly, boundary §2 of `CLAUDE.md`). The `frontend` container's nginx (`docker/frontend/nginx.conf` — **a single conf for dev and prod since P4-118**) additionally proxies `/bundles/` and `/exports/`, and carries **no `/engine/` location at all**: that debug proxy was removed from dev too on 2026-07-31 (it exposed the solver unauthenticated).

---

## 5. Knowledge graph & the auto-update hook (pre-existing automation)

`code-review-graph` is installed (`uv tool`) and a graph is built over **all code zones** — backend (PHP), engine (Python) and frontend (TS/TSX). `.code-review-graphignore` excludes only build artifacts (`frontend/dist|node_modules|storybook-static`) and non-code dirs (`docs/`, `specs/`, `docker/`, `.github/`, `.claude/`). Its MCP tools back graph-aware exploration and review across the repo.

Two hooks in `.claude/settings.json` (paths corrected to this checkout):
- **`SessionStart`** → `code-review-graph status` (read-only).
- **`PostToolUse` on `Edit|Write|Bash`** → `code-review-graph update --skip-flows` — **the only automatic action in this repo**: it incrementally re-indexes the graph after each edit. It never touches application code. This is the documented exception to the "no hidden automation" rule. To make it manual, remove the `PostToolUse` block and run `code-review-graph update` by hand.

The MCP server (`.mcp.json`: `uvx code-review-graph serve`) loads at session start; its tools (`semantic_search_nodes`, `query_graph`, `get_impact_radius`, `detect_changes`, …) are the preferred way to explore before Grep/Read. Semantic search additionally needs `code-review-graph embed` (not run — pulls a heavy local model).

Two further MCP servers are configured in `.mcp.json` and enabled: **Serena** (`uvx … serena start-mcp-server`, LSP-based symbol navigation for PHP + Python + TS; `.serena/project.yml` excludes only frontend build artifacts `dist|node_modules|storybook-static`) and **Context7** (`@upstash/context7-mcp`, up-to-date external-library docs). Separately, the **Caveman** plugin is installed user-scope (opt-in compressed communication mode). All are dev-time tooling — no application-code impact.

**Dependabot** (`.github/dependabot.yml`, audit supply-chain) scans the four dependency ecosystems weekly — **pip** (`/engine`), **npm** (`/frontend`), **composer** (`/backend`), **github-actions** (`/`) — opening grouped version-bump PRs (labelled `dependencies` + zone). Security PRs from GHSA alerts require Dependabot security-updates enabled at the repo (Settings → Code security). This is GitHub-side CI automation (not a local hook, not agent automation), so it is orthogonal to the "no hidden agent automation" rule (§7 CLAUDE.md). The open PRs are processed by the **manual `/dependabot` skill** (`.claude/skills/dependabot/`): verify → repair our code if the upgrade breaks it → zone test suite → merge; invoking the skill is the user's merge go **for those PRs only**.

---

## 6. Cross-references
- Vocabulaire transverse (termes métier + clés de payload): [`glossary.md`](glossary.md)
- Commandes backend (make · console `app:*` · pièges RLS): [`../backend/docs/commands.md`](../backend/docs/commands.md) · routes FFBB: [`../backend/docs/ffbb-api.md`](../backend/docs/ffbb-api.md) · routes géo (BAN + IGN): [`../backend/docs/geo-api.md`](../backend/docs/geo-api.md)
- Journal des upgrades (le pourquoi, pour le fondateur): [`upgrades.md`](upgrades.md) — tenu par le skill `/dependabot`
- Tests & guardrails: [`testing/testing-strategy.md`](testing/testing-strategy.md) · [`testing/blocking-tests.md`](testing/blocking-tests.md) (liste canonique des tests qui gatent)
- Sécurité: [`security/rls.md`](security/rls.md) (RLS, GUC, exceptions) · [`security/mercure.md`](security/mercure.md) (durcissement SEC-05/06) · [`security/jwt-cookie.md`](security/jwt-cookie.md) (JWT en cookie httpOnly, `JWT_COOKIE_SECURE`, pièges de test) · [`security/rgpd.md`](security/rgpd.md) (registre art. 30)
- Exploitation: [`ops/prod-stack.md`](ops/prod-stack.md) · [`ops/deploy.md`](ops/deploy.md) · [`ops/backup-restore.md`](ops/backup-restore.md)
- Ce qui reste à faire — bugs, évolutions, dette, suppressions sûres : [`../specs/evolution/roadmap.md`](../specs/evolution/roadmap.md) (**l'ouvert seulement**)
- Ce qui est livré + les décisions fermées : [`../specs/courantes/etat-des-lieux.md`](../specs/courantes/etat-des-lieux.md)
- Decisions to formalize: [`architecture/adr-index.md`](architecture/adr-index.md) · matrice contrainte UI↔engine: [`architecture/constraint-matrix.md`](architecture/constraint-matrix.md)
