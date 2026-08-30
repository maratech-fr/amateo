# Testing Strategy — Amateo

Last verified @ 2026-08-30 (P4-152 — `test_hard_layer_parity_registry.py` §3 re-confronté au code :
`add_venue_minimum_constraints` (`main.py:584`) est désormais appelée aussi par
`validate_assignments.py::_apply_hard` (`:419`) — la dernière asymétrie `DECLARED_ASYMMETRIES`
est fermée, la carte est vide. Reste du fichier non re-vérifié cette passe. Historique des passes :
`git log -p --follow docs/testing/testing-strategy.md` — un stamp REMPLACE, il ne s'empile pas
(DOC-33).)

Scope: backend + engine. The rebuilt frontend has its own tests (Vitest + RTL unit/integration with `vi.mock`, Playwright e2e in `frontend/tests/e2e`, and the container screenshot pipelines). Companion to [`/CLAUDE.md`](../../CLAUDE.md) §4, [`blocking-tests.md`](blocking-tests.md) (la liste canonique) and [`../project-map.md`](../project-map.md).

---

## 1. CI pipeline (`.github/workflows/ci.yml`)

Order and dependencies:

```
lint ──┐
       ├─► blocking-tests ──► {unit-tests, e2e}
phpstan┘
engine-tests ───────────────────────────────────► build-docker
blocking-tests ─────────────────────────────────┘
frontend            (lint + tsc -b + vite build + vitest) — parallel, no needs, does NOT gate build-docker
dependency-audit    (composer/npm/pip audit, A18)          — parallel, no needs, does NOT gate build-docker
rector              (dry-run, style gate P4-24)            — parallel, no needs, gates NOTHING… but BLOCKS the merge
secrets-scan        (gitleaks)                             — parallel, no needs, BLOCKS the merge
semgrep             (security gate)                        — parallel, no needs, BLOCKS the merge
engine-semantics    (groupe `contract`, cross-stack)       — parallel, no needs, BLOCKS the merge
smoke-tests         (5 smokes sémantiques)                 — parallel, no needs, BLOCKS the merge
engine-perf         (dense solve < 60 s)                   — needs engine-tests ; main only
```

**SEPT jobs isolés sans `needs`** — `frontend`, `dependency-audit`, `rector`, `secrets-scan`, `semgrep`, `engine-semantics`, `smoke-tests` (compte re-vérifié contre `ci.yml` le 2026-08-19 ; le doc en annonçait TROIS, oubliant les quatre derniers, qui sont pourtant des **required checks** de `main`) : un signal qui peut
rougir sur un commit qui n'a rien changé (une règle Rector élargie par un bump, une advisory publiée
ce matin) ne doit pas prendre en otage `blocking-tests` — donc l'isolation tenant/RLS — ni
`build-docker`, donc la livraison d'un correctif de sécurité. **Rougir ≠ ne rien bloquer** : `rector`
et `dependency-audit` sont des **required status checks** de `main`, ils bloquent le merge sans gater
aucun job. Même raison pour laquelle `SymfonyStackAlignmentTest` tourne dans `unit-tests` et non dans
le gate bloquant.

All PHP test jobs first **create + migrate the test DB** (`doctrine:database:create --if-not-exists` + `migrations:migrate`, `--env=test`) and run phpunit with `-e APP_ENV=test` on the `docker compose exec` — the containers default to `APP_ENV=dev` (root `.env` env_file) and `phpunit.xml.dist`'s `<server APP_ENV=test>` is not `force`d, so the real env var must be set explicitly.

| Job | What it runs |
|-----|--------------|
| `lint` | `docker compose config` + `make -n help` |
| `phpstan` (job name: **PHPStan & CS-Fixer**) | `composer phpstan` (level 8) **+ `composer cs-fix -- --dry-run --diff`** — needs postgres + redis. CS-Fixer vit ici, et non dans `lint`, parce que ce job a déjà le conteneur PHP que `lint` n'a pas (jusqu'au 2026-07-17 CS-Fixer ne tournait **nulle part** en CI, et `main` a été mergée rouge dessus deux fois) |
| `rector` (**Rector (style gate)**) | `composer rector -- --dry-run` (P4-24). Job **dédié, sans `needs`**, dépendance d'aucun autre — mais le contexte « Rector (style gate) » fait partie des **required status checks de `main`** (depuis le 2026-07-27), donc **il bloque le merge**. Corriger en local : `docker compose exec php-fpm sh -c 'cd /app/backend && composer rector'` (`make -C backend rector` est un dry-run : il montre, il ne fixe pas) |
| `blocking-tests` | les tests sécurité/queue/contrat lancés en **steps nommés**, chacun avec `--group phase1` — **gate du reste de la suite PHP** et de `build-docker`. ⚠ **La liste vit dans [`blocking-tests.md`](blocking-tests.md), et NULLE PART AILLEURS** : elle était recopiée ici et les deux copies ont dérivé l'une de l'autre (audit DOC-16 puis DOC-26, 3 éditions). Deux endroits pour une même vérité finissent par diverger — la copie est supprimée, pas resynchronisée. ⚠ **`--group phase1` ≠ le gate** : bien plus de fichiers `backend/tests/` portent l'annotation que le job n'a de steps nommés ; un fichier `phase1` non listé tourne dans `unit-tests`, donc après le gate et sans bloquer `build-docker`. La vérité exécutable est `.github/workflows/ci.yml` |
| `unit-tests` | full PHPUnit `tests/` (does NOT gate build-docker) |
| `e2e` | Playwright (full stack + Vite), needs blocking-tests. ⚠ **Deux cibles, pas une** : la suite tourne contre le **dev server** (:5173), puis un step dédié rejoue `security-headers.spec.ts` contre l'**image nginx** (:8081) avec `E2E_A17_REQUIRED=1`. Sans ce second passage, les tests A17 (CSP, HSTS, X-Frame-Options, nosniff) se **skippaient à chaque run** — les en-têtes n'existent que sur le build nginx — et le contrôle n'a jamais tourné en CI (audit D-04). La variable interdit au skip de revenir en silence : viser un dev server là devient un échec |
| `smoke-tests` | **Les 5 smokes sémantiques** (`backend/scripts/` : onboarding · smoke-solver · smoke-place-matches · smoke-overlay · smoke-coach-wishes) sur une vraie stack. **Aucun `needs`** — ils répondent « la fonctionnalité marche-t-elle ? », indépendamment des suites unitaires, et n'installent ni npm ni Chromium : le verdict tombe ~2× plus tôt. Chacun est autosuffisant (JWT auto, données créées/nettoyées, pointeur socle rouvert PUIS restauré) : l'ordre est un confort, jamais une dépendance |
| `engine-tests` | `pytest` + `ruff check .` + `mypy` (in the engine container) |
| `frontend` | `npm run lint` (dont `eslint-plugin-jsx-a11y`, §4bis) + `tsc -b` + `vite build` + `vitest` (parallel, no needs) |
| `dependency-audit` | `composer audit` / `npm audit --audit-level=high` / `pip-audit` (A18, blocking, parallel, no needs) |
| `build-docker` | `docker compose build` (needs **blocking + engine** tests only) |

All PHP jobs invoke `vendor/bin/phpunit` (PHPUnit 11, the direct `phpunit/phpunit` dep) — same binary as `Makefile` and `composer test`.

---

## 2. Backend tests (`backend/tests/`)

Layout: `Unit/` (Entity, Enum, Service — no DB) · `Integration/Api/` · `Security/` · `Queue/` · `CrossStack/` — **et sept dossiers HORS des testsuites déclarées** : `Api/`, `Command/`, `Double/`, `EventListener/`, `MessageHandler/`, `OpenApi/`, `Validator/`.

⚠️ **Le piège** : `phpunit.xml.dist` ne déclare que trois testsuites (`Unit`, `Integration`, `Contract`), or le job CI `unit-tests` lance **`phpunit tests/`, le dossier entier**. Valider en local avec `make -C backend test` (testsuite `Unit` seule) ou `make -C backend phpunit` (`--group phase1` seul) **laisse ces sept dossiers hors de vue** — deux échecs y ont dormi jusqu'à la CI. **Avant de pousser : `make -C backend tests-complete`**, miroir exact de la CI.

Groups (PHP attributes): `#[Group('phase1')]`, `#[Group('integration')]`, `#[Group('contract')]`, `#[Group('unit')]`. Test isolation via DAMA DoctrineTestBundle; bootstrap `tests/bootstrap.php`.

### Blocking guardrails (`phase1`)
| Test | Asserts |
|------|---------|
| `Security/TenantIsolationTest` | 403 on another club's data · 200 on own club · 403 when membership inactive · 200 with no `X-Club-Id` |
| `Security/TenantCacheIsolationTest` | Implemented (B3, resolved 2026-07-01) — 2 real tests: cache invalidation isolates clubs; entity without `club_id` purges nothing. |
| `Queue/ConcurrentGenerationTest` | 2nd `ClubGenerationLock` acquire for same club fails · different clubs acquire concurrently · wrong token cannot release |
| `CrossStack/ContractSchemaTest` (`phase1`+`contract`) | engine payload shape valid (version, clubId, seasonId, teams, venues, coaches, constraints, trainingSlots, sportCategoryId, scopeTargetId…) · POSTs to the real engine when reachable, else skips |
| `Security/SuperAdminAccessTest` | club JWT rejected · password without TOTP rejected · MFA session isolated from tenants · disabled/expired admin rejected · logout protected by CSRF · IP rate limit · runtime DB role has no admin-table privilege |

`ContractSchemaTest` is the **only** guardrail for the manually-synced backend↔engine contract (no codegen). Any change to engine Pydantic schemas or the backend payload must keep it green.

### `CrossStack/` — backend↔frontend contract guards (group `contract`, not `phase1`)

The frontend has **no codegen**: its API types are hand-written interfaces in `features/*/api.ts`.
Three `CrossStack/` tests guard that hand-sync from three distinct angles, each blind to what the
others catch:
- `OpenApiSnapshotMatchesTheLiveContractTest` — the committed snapshot (`specs/courantes/openapi-snapshot.json`)
  matches the live backend contract. Says nothing about whether the frontend actually followed.
- `TsUnionsMatchPhpEnumsTest` — every TS union declared in its `MIRRORED` registry matches its PHP enum.
  Only looks at unions, never at interface field types.
- `TsFieldsMatchOpenApiSchemaTest` — per declared TS-interface ↔ OpenAPI-schema pair (`PAIRS`, extensible
  one entry at a time): every TS field must exist in the schema (one-way — a schema field the frontend
  ignores is not a drift), and a schema field constrained by an `enum` must not be typed as a bare
  `string` on the TS side. Declared exceptions live in `DECLARED_ENUM_DRIFTS` and must each carry a
  reason (guarded by its own test). Optionality (`required`) is intentionally not checked in v1 — the
  snapshot's read schemas the frontend consumes carry no `required` array at all (only `*Input`
  write-schemas do); extending the guard to `X.XInput` pairs is the documented escape hatch.

These three run in the `contract` group (`engine-semantics` CI job — a required check of `main`,
**not** part of `blocking-tests`/`unit-tests`; see `docs/testing/blocking-tests.md` for what
actually gates the merge).

---

## 3. Engine tests (`engine/tests/`)

- **Unit by feature/constraint:** `test_constraints.py`, `test_objective.py`, `test_result_builder.py`, `test_coach_rest_day.py`, `test_salarie_distribution.py`, `test_max_consecutive_sessions.py`, `test_age_order.py`, `test_chaining_bonus.py`, `test_engine.py` (endpoints), …
- **Golden / integration** (`tests/golden/`): full solves on real club fixtures (`simple_club.json`, `dense_club.json`, `bccl_regression.json`, …) with expected outputs; `test_two_pass.py` guards the **single-pass invariant** (ADR-0001) — the dormant relaxation fallback is NOT wired into production, so the test pins its absence.
- **Invariants** (`tests/invariants/test_invariants.py`): post-solve checks — no team/coach overlaps, venue capacity respected, hard locks honored.
- **Fixtures** (`tests/fixtures/`): JSON club configs (simple, medium, dense, bccl_regression, overlap_*, no_rest_*, vacation_week, impossible, score_hard_only_teams…) — `ls engine/tests/fixtures/` for the current set.
- Property-based tests via hypothesis; `pytest-timeout` guards runaway solves.

### `test_hard_layer_parity_registry.py` — HARD-layer parity guard (`/generate` ⇄ verdict)

`/generate` and `POST /validate-assignments` (the verdict on a manual move) must apply the same
HARD layer — a HARD family born on one path without its mirror on the other lets a manual move
that breaks it be judged **valid** in silence (exactly ENG-36, the travel-time finding, fixed in
PR #779). This guard makes a *next* asymmetry impossible to miss, rather than fixing one:
- **AST, not regex** — parses `app/main.py` and `validate_assignments.py`; resistant to reformatting.
- **Anchor = the aggregator** `add_level_1_hard_constraints`: both paths call it. The `/generate`
  side is the **single** function of `main.py` that composes it (today `_solve`); the verdict side
  is `_apply_hard`, checked to still compose the aggregator. Either anchor failing hard (0 or
  several composing functions) beats silently diffing the wrong function.
- **HARD family convention**: `^add_.*_constraints$` — excludes SOFT terms (`_penalty`/`_bonus`)
  and diagnostics (`diagnose_*`) by construction.
- **`KNOWN_GENERATE_FAMILIES`** is a floor sentinel: if the scanner sees fewer `/generate` families
  than this known set, it fails hard (scanner regression or real removal) rather than staying quiet.
- **`DECLARED_ASYMMETRIES`** carries named, reasoned exceptions (own test enforces a reason is
  present, and another enforces no declared exception has gone stale — i.e. the family is now
  actually symmetric and the entry should be removed). **Empty today**: the one real asymmetry
  found at birth, `add_venue_minimum_constraints` (posed on `/generate`, never mirrored by
  `_apply_hard`), was closed by P4-152 — the verdict now poses the same HARD constraint **and**
  names a refusal via the deterministic mirror `_venue_minimum_move_violation` (same pattern as
  `_travel_time_move_violation`/ENG-36, since the HARD layer alone lets the solver plant a phantom
  session elsewhere in the venue to satisfy the floor and answer "valid" regardless).
- Assumed fragility: a HARD block posed outside the anchor function, via an indirect call, or
  under a name outside the `add_*_constraints` convention would escape this static census — the
  sentinels above catch the cases that touch a *known* family; the rest is the cost of a static
  scan, which is why both anchors fail loud on drift instead of rendering an empty, lying diff.

Run: `cd engine && make test` (pytest + ruff + mypy, inside the engine container).

---

## 3bis. Les parcours e2e disent POURQUOI quand ils cassent

⚑ **Convention : un spec e2e importe `test` (et `expect`) depuis `./fixtures`, jamais depuis
`@playwright/test`.** La fixture y est `auto` : elle enregistre les réponses `/api/*` de statut
≥ 400 et les **attache au rapport quand, et seulement quand, le test tombe**
(`frontend/tests/e2e/fixtures.ts`).

Elle existe parce que le dépôt a payé son absence : le 2026-08-21, `journey.spec.ts` est tombé
**deux fois en CI** (PR #684 puis #687) sur `element(s) not found`, trois tentatives chacune, puis
une relance complète VERTE. Ce message dit ce qui MANQUE à l'écran ; il ne dit pas si le serveur a
refusé, s'il a répondu 422, ou si c'est la liste qui n'a pas suivi. Deux enquêtes pour rien.

⚠ **Ce n'est pas une assertion, délibérément.** Des 4xx légitimes traversent ces parcours (gardes
fail-closed, refus de rôle, 404 anti-énumération des pages à token) : en faire un échec
transformerait un comportement voulu en faux rouge. **On collecte, on n'accuse pas.**

⚠ **Un piège d'environnement à connaître avant d'accuser le code** : les mails partent par le bus,
donc par le conteneur `messenger-worker` — qui **s'arrête sur son time-limit horaire**. Worker
mort = aucun mail de vérification = tout parcours qui s'inscrit échoue dans `fetchVerificationToken`,
un échec qui ressemble à un bug produit. La cible `make -C frontend e2e` le relève d'elle-même
(`compose up -d --wait`) ; une invocation `npx playwright test` directe, elle, **saute ce
self-heal** (il est conditionné à l'absence de `E2E_BASE_URL`). Constaté le 2026-08-21 : worker
arrêté depuis 9 h. Et la boîte Mailpit est désormais **vidée avant chaque inscription**
(`submitRegister`) — accumulée sur des dizaines de runs locaux, la recherche `to:{email}` finissait
par ne plus rendre le bon message.

## 4. How to run locally

```bash
make start                                   # bring the stack up first (tests need postgres/redis/engine)
cd backend && make test                      # PHPStan + CS-Fixer + PHPUnit --testsuite Unit (PAS le gate phase1)
cd backend && make phpunit                   # PHPUnit --group phase1 (le gate bloquant)
cd backend && make tests-complete            # phpstan + cs + phpunit tests/ (miroir CI complet — à passer avant push)
cd engine  && make test                      # pytest + ruff + mypy
```

Backend & engine tests run **inside Docker** — running `phpunit`/`pytest` on the host will fail. If the stack is down, `ContractSchemaTest` and other integration tests skip or fail rather than silently passing.

**Frontend e2e (Playwright)** self-heal the stack: a `globalSetup` (`frontend/tests/e2e/global-setup.ts`) runs `docker compose up -d --wait` before any test — it starts any stopped service (a dead `messenger-worker`/`engine` was the recurring flake: the generation never completes → the planning never appears) and blocks until every healthcheck passes. No-op when already healthy; skipped when `E2E_BASE_URL` targets an externally managed stack.

**Modales — reflow WCAG 1.4.10 (2026-08-11)** : `modal-reachability.spec.ts` vérifie qu'une
modale LONGUE tient à l'écran et que ce qui dépasse défile, en 1440×900, 1440×600 et
**320×256** (la condition de reflow du standard, équivalent 400 % de zoom). C'est le pendant
navigateur de `modal-overflow.test.tsx` : jsdom n'ayant aucun moteur de mise en page, l'unitaire
est réduit à épingler les CLASSES du contrat — il ne voit ni un appelant qui le défait via
`className`, ni la mise en page interne d'un écran, ni `dvh`.

⚑ **Deux enseignements payés comptant, à ne pas re-payer.** (1) La première version visait deux
modales bon marché du wizard et rendait un **FAUX VERT** : mesurées à **192 px** et **190 px**,
elles tiennent dans n'importe quelle fenêtre — le scénario ne testait rien. D'où le **témoin** :
si rien ne déborde, le spec ÉCHOUE en le disant. (2) Le défaut ne se manifeste que sur une modale
longue, et la seule qui le soit est le catalogue d'actions superadmin — celle qui a réellement
cassé. Ce spec **ouvre donc le premier parcours e2e vers `/admin`**.

**Le socle superadmin e2e** (`support-admin.ts`) : login par les vrais écrans (mot de passe puis
TOTP), le code étant calculé dans le test (RFC 6238) à partir d'une clé semée au préflight.
⚠ **Aucune route dev n'est ajoutée pour ça, délibérément** — une porte `/api/dev/*` délivrant une
session superadmin mettrait la compromission complète de la surface cross-tenant derrière un seul
`APP_DEBUG` mal réglé, ce qui n'est pas comparable au simulateur d'horloge existant. Le préflight
(recréation du compte + purge de `cache.rate_limiter`) vit **en DEUX endroits qui doivent rester
d'accord** : la cible `make -C frontend e2e` et un step du job CI. ⚠ Sans le step CI, le spec se
**skipperait en silence** — exactement le piège D-04 ci-dessus. ⚠ Et sans la purge du limiteur,
`admin_auth` (5 essais / 15 min PAR IP) fait rougir le spec en désignant l'écran TOTP : ça
ressemble à une régression, c'est le quota.

**Dockerized run (P4-33, 2026-08-04)** : `make -C frontend e2e` exécute la suite DANS le service compose `e2e` (image officielle Playwright **épinglée sur la version de `@playwright/test`** du lock — une dérive = « browser not found ») : l'hôte n'a plus besoin de Node, dernier maillon qui l'exigeait. Cibles internes au réseau (`E2E_BASE_URL=http://frontend-dev:5173`, `MAILPIT_WEB_URL=http://mailpit:8025`), donc stack + `make -C frontend dev` doivent tourner ; Vite doit autoriser le host interne (`server.allowedHosts: ['frontend-dev']` — sans quoi 403 « Blocked request »). La CI, elle, garde son chemin Node natif (elle installe déjà Node pour Vite).

---

## 4bis. Frontend accessibility guardrail (WCAG 2.2 AA)

Two layers, added as **tests** so any frontend change is checked against the norm:

- **Static lint (blocking)** — `eslint-plugin-jsx-a11y` (recommended set) runs inside `npm run lint` (CI `frontend` job) at **`error`**. A single knob `A11Y_LEVEL` in `eslint.config.js` drives warn-vs-block (kept `error` now that the known violations are fixed; flip to `warn` only to temporarily unblock a large refactor). The remapping preserves each rule's tuned options and never re-enables the rules recommended disables. `label-has-associated-control` is told our custom control components (`Input`/`Select`/`TeamSelect`). The few intentional `autoFocus` uses (modal step fields, revealed rename/search inputs) carry a justified inline disable.
- **Structural axe** — `vitest-axe` asserts `toHaveNoViolations()` on the shared primitives (`src/test/a11y.test.tsx`, via `expectNoA11yViolations()` in `src/test/utils.tsx`) and the Modal (focus into panel on open, Escape close, focus restoration WCAG 2.4.3). Component-specific a11y lives in each component's own test where the fixtures already are: `MonthCalendar.test.tsx` (info emojis expose a text alternative, A11Y-05) and `WeekGrid.test.tsx` (venue named as text in every view, not colour only, A11Y-01). jsdom has no layout engine, so axe **skips colour-contrast (WCAG 1.4.3)** — that axis (A11Y-06) is a **follow-up** Playwright/axe pass in a real browser.

Shared modal a11y is one hook — `useModalA11y` (`src/shared/lib/useModalA11y.ts`): focus-trap + initial focus + focus restoration + Escape, applied to both `Modal` and `ConfirmDialog` (the audit's A11Y-03 / FRT-12/13 / UXC-02 came from per-modal divergent handling).

Matcher wiring: runtime `expect.extend` in `src/test/setup.ts`; the vitest-v3 type augmentation is `src/test/vitest-axe.d.ts` (vitest-axe ships only a stale global `Vi.Assertion`).

## 5. Known testing gaps
- *Résolu en partie (P4-122, 2026-08-23)* — **le parcours « réalité d'un club » est ACTIF** : `frontend/tests/e2e/club-life.spec.ts` mène l'incident Matéo du seed (P5-13) de sa carte radar jusqu'à son overlay généré, et atteste que les QUATRE plannings coexistent (socle validé, deux reprises, overlay) chacun borné à SA lignée — c'est le témoin qui aurait rougi sur le repli silencieux saison du 2026-08-19. **Idempotent** (la base e2e n'est jamais réinitialisée) : il prend l'atelier au premier passage, l'écran du planning ensuite — les deux branches sont prouvées. Reste ouvert dans P4-122 : cadrer si le journey suffit comme témoin socle (1) et si l'approbation manuelle mérite son chemin (2). Détail : `specs/evolution/roadmap.md` P4-122.
- **A11Y-06 — le contraste de couleur (WCAG 1.4.3) n'est vérifié par AUCUN test** : jsdom n'a pas de moteur de layout, donc axe **saute la règle** (cf. §4bis). La passe Playwright/axe en vrai navigateur reste à faire — jusque-là, un contraste insuffisant passe la CI.
- *Résolu (SEC-12, 2026-07-31)* — **la portée des policies RLS est désormais gardée** : `RlsIsolationTest::testEveryPolicyOnClubIdTablesIsTenantScoped` compare chaque policy permissive des tables `club_id` au canon, avec allowlist bidirectionnelle justifiée. Détail et limites : `docs/security/rls.md` §Exceptions. (Dette *résiduelle* — scoper le SELECT ouvert de `club_user`/`coach_wish_token` — encore ouverte, roadmap SEC-12.)
- *Résolus* : `TenantCacheIsolationTest` est implémenté (B3) et les 9 dépréciations de doc-comments PHPUnit 11 sont passées en attributs (B6) — 2026-07-01 (historique : git log de `docs/technical-debt.md`, absorbé dans `specs/evolution/roadmap.md` le 2026-07-11).
