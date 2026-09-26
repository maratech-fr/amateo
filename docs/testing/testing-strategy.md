# Testing Strategy — Amateo

Last verified @ 2026-09-26 (rotation de fraîcheur, `documentation-update`). Ce fichier ne couvre que
backend+engine (« Scope » ci-dessous). Re-confronté au code : le graphe des jobs §1 (noms et
`needs`) correspond à `.github/workflows/ci.yml` — `e2e` et `backend-coverage` sur `needs:
blocking-tests`, `engine-coverage`/`engine-perf`/`engine-perf-pr` sur `needs: engine-tests`,
`build-docker` sur `needs: [blocking-tests, engine-tests]` seuls ✓ ; `BlockingTestsListMatchesCiTest`
et `PlaywrightImageMatchesLockTest` existent toujours (`backend/tests/Unit/Documentation/` et
`Unit/Dependency/`) ✓ ; `phpunit.xml.dist:42` toujours à `SYMFONY_DEPRECATIONS_HELPER
max[direct]=0` ✓ ; le projet Playwright `superadmin` dépend bien de `setup` (`storageState`,
`frontend/playwright.config.ts`) ✓. Drift corrigé : §3bis affirmait `messenger-worker` « seul
service de dev à porter `restart: unless-stopped` » — désormais faux depuis P4-220 (même jour,
tous les services de dev durables le portent, `docker-compose.yml`) ; reformulé. Reste du fichier
(§2 backend tests, §3 engine tests, §4bis a11y, §5 known gaps) non re-sondé cette passe — voir
`git log -p --follow docs/testing/testing-strategy.md` pour l'historique des passes.

Scope: backend + engine. The rebuilt frontend has its own tests (Vitest + RTL unit/integration with `vi.mock`, Playwright e2e in `frontend/tests/e2e`, and the container screenshot pipelines). Companion to [`/CLAUDE.md`](../../CLAUDE.md) §4, [`blocking-tests.md`](blocking-tests.md) (la liste canonique), [`test-coverage-map.md`](test-coverage-map.md) (qui teste quoi, angles morts) and [`../project-map.md`](../project-map.md).

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
functional-tests    (Behat, Gherkin FR, API-only, one feature per promise) — parallel, no needs, BLOCKS the merge (required check à ajouter côté GitHub)
engine-perf         (dense + BCCL solve + place-matches build < 60 s) — needs engine-tests ; main only
engine-perf-pr      (dense solve, PR budget = 60 s)         — needs engine-tests ; PR only, skipped when engine/ untouched
engine-coverage     (couverture engine + cliquet)           — needs engine-tests ; does NOT gate build-docker
frontend-coverage   (couverture frontend + cliquet)         — needs frontend ; does NOT gate build-docker
backend-coverage    (couverture backend + cliquet)          — needs blocking-tests ; does NOT gate build-docker
```

**`engine-coverage`** (P4-166 PR 1/3, 2026-09-03) mesure `pytest --cov=app` en CI et la garde par un
cliquet : `needs: engine-tests`, `timeout-minutes: 15`, `--cov-fail-under` lu de `coverage-floor.json`
(racine, clé `engine`) — jamais un seuil en dur. **Absent des `needs` de `build-docker`** : une
régression de couverture rougit ce job seul, jamais l'image de prod (décision fermée,
`specs/courantes/etat-des-lieux.md` §2). Artefact `coverage-engine` (xml + résumé texte),
`upload-artifact` avec `if: always()`. Le plancher versionné et sa règle de cliquet sont détaillés
dans [`test-coverage-map.md`](test-coverage-map.md) (§ `coverage-floor.json`).

**`frontend-coverage`** (P4-166 PR 2/3, 2026-09-03) mesure `npm run test:coverage` (Vitest
`--coverage`) en CI et la garde par le même patron de cliquet : `needs: frontend`,
`timeout-minutes: 15`, `thresholds.lines` lu de `coverage-floor.json` (racine, clé `frontend`) —
jamais un seuil en dur. **Absent des `needs` de `build-docker`** (décision fermée,
`specs/courantes/etat-des-lieux.md` §2). Artefact
`coverage-frontend` (`frontend/coverage/`, `upload-artifact` avec `if: always()`). Détail
d'implémentation (exclusions déclarées, piège `__dirname` sous `--coverage`) :
[`test-coverage-map.md`](test-coverage-map.md) (§ `coverage-floor.json`).

**`backend-coverage`** (P4-166 PR 3/3, 2026-09-04 — **lot P4-166 SOLDÉ**) mesure `phpunit tests/
--exclude-group contract` en CI, instrumenté par le driver `pcov` (`-d pcov.enabled=1`), et la
garde par le même patron de cliquet : `needs: blocking-tests`, `timeout-minutes: 45`. PHPUnit 11
n'a pas de seuil natif (pas de `--fail-under`) : le gate est `backend/scripts/coverage-gate.php`
(sans dépendance, lit le clover produit par `--coverage-clover`, compare au plancher `backend` de
`coverage-floor.json`, sort 1 sous le plancher). `pcov` est **chargé mais INERTE**
(`pcov.enabled = 0` par défaut dans l'image, `docker/php/Dockerfile`) : `unit-tests` et
`blocking-tests` ne paient aucun coût d'instrumentation, seul ce job l'active. Le driver n'existe
QUE dans l'image dev/test (`ARG WITH_PCOV=0` par défaut, mis à `1` par `docker-compose.yml` et
l'action CI `build-php-cached`) — l'image de PROD (`docker-compose.prod.yml`, cible `prod`) ne
reçoit jamais cet arg et n'embarque jamais `pcov`. **Absent des `needs` de `build-docker`** (décision
fermée, `specs/courantes/etat-des-lieux.md` §2). Artefact `coverage-backend` (`backend/coverage/`,
`upload-artifact` avec `if: always()`). Le plancher versionné et sa règle de cliquet sont détaillés dans
[`test-coverage-map.md`](test-coverage-map.md) (§ `coverage-floor.json`).

**`engine-perf-pr`** (P4-167, 2026-09-03) donne un signal de perf plus tôt et moins cher sur les PR sans
dupliquer `engine-perf` : `if: github.event_name == 'pull_request'`, `needs: engine-tests`,
`timeout-minutes: 15`. Un step dédié détermine si `engine/` a bougé (`git diff --name-only
origin/${BASE_REF}...HEAD | grep -qE '^(engine/|docker/engine/)'`, `BASE_REF` passé par l'**ENV**,
jamais interpolé dans le shell — un nom de branche est une entrée d'attaquant sur une PR de fork,
finding semgrep `run-shell-injection`) : si rien n'a bougé le job finit vert en secondes (un required
check absent bloquerait le merge autrement qu'un required check vert) ; sinon il build l'image engine et
lance `pytest -m perf -k dense_club --durations=0 -rA` avec `PERF_BUDGET_SECONDS=60` (même budget que
`main` — décision fermée, `specs/courantes/etat-des-lieux.md` §2). ⚠ **Piège du sélecteur** : `pytest -k`
matche le node id ENTIER, et le fichier `engine/tests/perf/test_perf_dense.py` porte aussi le test BCCL
— `-k dense` aurait donc aussi sélectionné `test_bccl_completes_under_budget` (le mot `dense` apparaît
dans le nom du FICHIER). Le sélecteur correct est `-k dense_club`, qui isole
`test_dense_club_completes_under_budget`. `engine-perf` (main) garde les deux paliers, dense et BCCL, au
même budget 60 s.

**SEPT jobs isolés sans `needs`** — `frontend`, `dependency-audit`, `rector`, `secrets-scan`, `semgrep`, `engine-semantics`, `functional-tests` (Behat, P4-165 SOLDÉ, 2026-09-04 — `smoke-tests` supprimé, compte re-vérifié contre `ci.yml`) : un signal qui peut
rougir sur un commit qui n'a rien changé (une règle Rector élargie par un bump, une advisory publiée
ce matin) ne doit pas prendre en otage `blocking-tests` — donc l'isolation tenant/RLS — ni
`build-docker`, donc la livraison d'un correctif de sécurité. **Rougir ≠ ne rien bloquer** : `rector`
et `dependency-audit` sont des **required status checks** de `main`, ils bloquent le merge sans gater
aucun job. Même raison pour laquelle `SymfonyStackAlignmentTest` tourne dans `unit-tests` et non dans
le gate bloquant.

**Régime de permissions des workflows (P4-92, 2026-09-22)** : chaque workflow de `.github/workflows/` déclare désormais un bloc `permissions:` racine explicite (`contents: read` sur `ci.yml`, surchargé `packages: read` sur `secrets-scan`/`build-docker` qui pullent une image miroir ghcr ; `contents: read` + `packages: read` à la racine de `security-weekly.yml`), cliquet gardé par `WorkflowPermissionsDeclaredTest` (testsuite `Unit`, **ne gate pas**) qui rougit sur un bloc racine manquant ou un scope `write` hors de la liste fermée `deploy.yml`/`mirror-images.yml` — ce n'est PAS un correctif de faille, `default_workflow_permissions` valant déjà `read` côté dépôt.

**Régime de dépréciations (2026-09-22)** : `phpunit.xml.dist` passe `SYMFONY_DEPRECATIONS_HELPER` de `weak` à `max[direct]=0`, ce qui **redéfinit ce qui peut rougir `unit-tests`** (et tout job PHPUnit) — une dépréciation `direct` (une API Symfony dépréciée appelée par notre code, dont l'avertissement part du vendor et non de nos fichiers) fait désormais échouer la suite, attrapant la dérive vers Symfony 8.4 que le seuil `self` (dépréciations émises depuis nos seuls fichiers) laisserait passer.

**Piège générique `run: … | tee …` dans un `step` GitHub Actions (P4-256, e2e)** : le shell par défaut d'un `run:` sur un runner Linux est `bash -e {0}`, **sans** `pipefail` (il ne s'arme qu'avec un `shell: bash` explicite au niveau du step ou du job, absent de ce workflow) — un `| tee` sans `set -o pipefail` explicite dans le corps du `run` renvoie le code de sortie de `tee` (toujours 0) et masque un échec de la commande en tête de pipe. Tout nouveau `run: … | …` dans `ci.yml` doit poser `set -o pipefail` en première ligne s'il doit pouvoir faire rougir le step.

All PHP test jobs first **create + migrate the test DB** (`doctrine:database:create --if-not-exists` + `migrations:migrate`, `--env=test`) and run phpunit with `-e APP_ENV=test` on the `docker compose exec` — the containers default to `APP_ENV=dev` (root `.env` env_file) and `phpunit.xml.dist`'s `<server APP_ENV=test>` is not `force`d, so the real env var must be set explicitly.

| Job | What it runs |
|-----|--------------|
| `lint` | `docker compose config` + `make -n help` |
| `phpstan` (job name: **PHPStan & CS-Fixer**) | `composer phpstan` (level 8) **+ `composer cs-fix -- --dry-run --diff`** — needs postgres + redis. CS-Fixer vit ici, et non dans `lint`, parce que ce job a déjà le conteneur PHP que `lint` n'a pas (jusqu'au 2026-07-17 CS-Fixer ne tournait **nulle part** en CI, et `main` a été mergée rouge dessus deux fois) |
| `rector` (**Rector (style gate)**) | `composer rector -- --dry-run` (P4-24). Job **dédié, sans `needs`**, dépendance d'aucun autre — mais le contexte « Rector (style gate) » fait partie des **required status checks de `main`** (depuis le 2026-07-27), donc **il bloque le merge**. Corriger en local : `docker compose exec php-fpm sh -c 'cd /app/backend && composer rector'` (`make -C backend rector` est un dry-run : il montre, il ne fixe pas) |
| `blocking-tests` | les tests sécurité/queue/contrat lancés en **steps nommés**, chacun avec `--group phase1` — **gate du reste de la suite PHP** et de `build-docker`. ⚠ **La liste vit dans [`blocking-tests.md`](blocking-tests.md), et NULLE PART AILLEURS** : elle était recopiée ici et les deux copies ont dérivé l'une de l'autre (audit DOC-16 puis DOC-26, 3 éditions). Deux endroits pour une même vérité finissent par diverger — la copie est supprimée, pas resynchronisée. ⚠ **`--group phase1` ≠ le gate** : bien plus de fichiers `backend/tests/` portent l'annotation que le job n'a de steps nommés ; un fichier `phase1` non listé tourne dans `unit-tests`, donc après le gate et sans bloquer `build-docker`. La vérité exécutable est `.github/workflows/ci.yml` |
| `unit-tests` | full PHPUnit `tests/` (does NOT gate build-docker) |
| `backend-coverage` | `phpunit tests/ --exclude-group contract --coverage-clover` (pcov, `-d pcov.enabled=1`) + `scripts/coverage-gate.php` (plancher `backend` de `coverage-floor.json`, PHPUnit 11 n'a pas de `--fail-under` natif), needs `blocking-tests`, does **NOT** gate `build-docker` (P4-166 PR 3/3) |
| `e2e` | Playwright (full stack + Vite), needs blocking-tests. ⚠ **Deux cibles, pas une** : la suite tourne contre le **dev server** (:5173), puis un step dédié rejoue `security-headers.spec.ts` contre l'**image nginx** (:8081) avec `E2E_A17_REQUIRED=1`. Sans ce second passage, les tests A17 (CSP, HSTS, X-Frame-Options, nosniff) se **skippaient à chaque run** — les en-têtes n'existent que sur le build nginx — et le contrôle n'a jamais tourné en CI (audit D-04). La variable interdit au skip de revenir en silence : viser un dev server là devient un échec. **Fiabilité infra (2026-09-15)** : `COMPOSE_BAKE=false` (env du job) écarte le builder bake qui se figeait « waiting for BuildKit » ; un step **Pre-pull third-party images** (`docker compose pull --ignore-buildable`, enveloppé de `.github/scripts/retry.sh`) tire nginx/mercure/redis/postgres à part, l'image `engine` est bâtie dans son propre step relançable, et les steps d'infra (pull, build, `up --wait`) passent par `retry.sh` — un aléa de Docker Hub/BuildKit ne rougit plus une PR saine. Un step `if: failure()` écrit dans le résumé de job si l'échec est **AVANT Playwright (infra)** ou **Playwright**. **Un job vert peut cacher un flaky** (Playwright sort 0 dès qu'un retry passe, P4-256, toujours ouvert) : un step `always()` (`.github/scripts/flaky-summary.sh`) annonce dans le résumé de job les tests perdus-puis-rejoués, sans jamais faire rougir le job ni le gater. L'artefact `playwright-results` (traces `on-first-retry`) est lui aussi uploadé en `always()` — la trace de l'essai perdu survit donc même sur un run vert, récupérable 7 jours |
| `functional-tests` | **Behat, Gherkin français, API seule** (`backend/features/`, contexts `backend/tests/Behat/`) — scénarios métier relus par le fondateur, joués contre la stack RÉELLE (nginx→php-fpm, vrai `messenger-worker`, vrai engine, **`pdf-worker`** depuis le 2026-09-05 — `l-export-du-planning.feature` attend un PDF Puppeteer réel, sans lui le worker d'export répond `failed`), sans navigateur ni noyau in-process. **Aucun `needs`** — ils répondent « la fonctionnalité marche-t-elle ? », indépendamment des suites unitaires, et n'installent ni npm ni Chromium : le verdict tombe plus tôt. Chaque feature est autosuffisante (JWT auto, données créées/nettoyées, pointeur socle rouvert PUIS restauré) : jouable seule et dans n'importe quel ordre. **Remplace intégralement les 5 smokes bash** (`smoke-solver.sh`, `onboarding-smoke.sh`, `smoke-place-matches.sh`, `smoke-overlay.sh`, `smoke-coach-wishes.sh`, tous SUPPRIMÉS — P4-165, 2026-09-04) — parité prouvée assertion par assertion, même verdicts. Table feature ↔ ce qu'elle prouve : [`test-coverage-map.md`](test-coverage-map.md) §5 |
| `engine-tests` | `pytest` + `ruff check .` + `mypy` + `bandit -r app/` (ENG-46, lot correctif de l'audit 0918 — bandit était déjà en local via `make test`, il ne gatait pas la CI) (in the engine container) |
| `engine-coverage` | `pytest --cov=app --cov-fail-under=$FLOOR` (`$FLOOR` read from `coverage-floor.json`, key `engine`), needs `engine-tests`, does **NOT** gate `build-docker` (P4-166 PR 1/3) |
| `frontend` | `npm run lint` (dont `eslint-plugin-jsx-a11y`, §4bis) + `tsc -b` + `vite build` + `vitest` (parallel, no needs) |
| `frontend-coverage` | `npm run test:coverage` (`vitest run --coverage`, `thresholds.lines` lu de `coverage-floor.json`, clé `frontend`), needs `frontend`, does **NOT** gate `build-docker` (P4-166 PR 2/3) |
| `dependency-audit` | `composer audit` / `npm audit --audit-level=high` / `pip-audit` (A18, blocking, parallel, no needs). Les trois passent par `.github/scripts/audit-retry.sh` (P4-171) : 3 tentatives (10 s, 30 s) **seulement** quand la sortie porte une signature réseau (timeout, 5xx, DNS, `curl error`) — un `exit 1` d'audit sans cette signature est rendu tel quel, le gate ne s'aveugle pas |
| `build-docker` | `docker compose build` (needs **blocking + engine** tests only) |

All PHP jobs invoke `vendor/bin/phpunit` (PHPUnit 11, the direct `phpunit/phpunit` dep) — same binary as `Makefile` and `composer test`.

---

## 2. Backend tests (`backend/tests/`)

Layout, rangé PAR NATURE dans les 3 testsuites de `phpunit.xml.dist` (`Unit` : `Unit/`, `Logging/`,
`Messenger/` — sans conteneur ; `Integration` : `Integration/`, `Security/`, `Queue/`, `Api/`,
`Command/`, `OpenApi/`, `Validator/`, `MessageHandler/`, `EventListener/` — `Kernel`/`WebTestCase` ;
`Contract` : `CrossStack/`). Chaque sous-dossier de `tests/` portant un `*Test.php` appartient à
exactement une testsuite, gardé par `Unit/TestsuitesCoverEveryTestDirectoryTest` (P4-169, 2026-09-03).

⚠️ **Le piège reste réel malgré ce rangement** : le job CI `unit-tests` lance **`phpunit tests/`, le
dossier entier**, alors que `make -C backend test` ne joue que la testsuite `Unit` (rapide, sans DB)
et `make -C backend phpunit` que `--group phase1`. Les deux couvrent désormais TOUS les dossiers par
testsuite (rien n'y échappe en silence), mais `Integration`/`Contract` restent hors de `make test` par
construction. **Avant de pousser : `make -C backend tests-complete`**, miroir exact de la CI.

Groups (PHP attributes): `#[Group('phase1')]`, `#[Group('integration')]`, `#[Group('contract')]`, `#[Group('unit')]`. Test isolation via DAMA DoctrineTestBundle; bootstrap `tests/bootstrap.php`.

**Un test isolé (`#[RunInSeparateProcess]`/`#[RunTestsInSeparateProcesses]`) ne gèle plus le reste de la suite (2026-09-03).** `App\Tests\ReleasesParentTransactionBeforeIsolatedTests` (`backend/tests/ReleasesParentTransactionBeforeIsolatedTests.php`), extension PHPUnit déclarée dans `phpunit.xml.dist` juste APRÈS l'extension DAMA : au `TestSuite\Started` d'une classe portant un test isolé, elle force `DamaExtension::rollBack()` sur la transaction du processus PARENT. Pourquoi ce correctif : DAMA n'annule la transaction du test précédent qu'au `PreparationStarted` du SUIVANT — pour un test isolé, cet événement naît dans le processus ENFANT et n'atteint le parent qu'à la fin de l'enfant, donc le parent garde ses écritures non commitées (et leurs verrous) pendant toute la vie de l'enfant. Si l'enfant touche la même clé (constaté sur `priority_tier` id 1, créé par des tests API et retrouvé par `BcclSeeder` en find-or-create), c'est un **interblocage muet**, tranché seulement par `idle_in_transaction_session_timeout` (60 s sur `amateo_test`, réglé par `make db-init-test`, PR #281) qui tue la connexion du parent — et fait cascader tout le reste de la suite en « no connection to the server » (772 erreurs constatées le 2026-09-03 sur l'ordre `VenueUsageStatsApiTest` puis `BcclSeedCommandTest`). Détail complet (repro exacte, mécanique) : docblock du fichier.

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

**Second level — argument parity, not just family presence.** The family diff above has a blind
spot: it sees a family *missing*, never a family present on **both** paths but fed **empty** (a
verdict-side `shared_trainings=[]` where `/generate` passes `data.get("sharedTrainings", [])`
would make the rule mute and the verdict lie, while the family diff stays green). A second guard
compares, argument by argument, the **source expression** feeding the single call to
`add_level_1_hard_constraints` on each side (`ast.unparse`) — comparing keyword *names* alone would
miss exactly that substitution:
- **Narrow alias transparency**: a value that is a bare `Name` resolves through a **single**
  plain `x = <expr>` assignment (`ast.Assign`, one target, one binding) before comparison — this is
  what makes `resolved_implicit_rules` (an alias assigned once in `main.py`, then passed) compare
  equal to the verdict's inline `resolve_implicit_rules(...)`. **Annotated locals (`AnnAssign`) and
  parameters are never resolved** — doing so would fabricate false divergences for values that
  `/generate` holds as annotated locals but the verdict receives as parameters
  (`model`, `assignments`, `team_coach_map`, `team_player_map`).
- **`DECLARED_ARG_DIVERGENCES`** carries named, reasoned exceptions (same two-test pattern: a
  reason is mandatory, a stale entry — the argument is no longer actually divergent — fails).
  **Empty today** (ENG-41, lot correctif de l'audit moteur 0918, 2026-09-18): the one entry,
  `min_sessions_by_team`, was closed because its reason was false — `/generate` never passed real
  per-team floors, `adjusted_min_by_team` (renamed `min_by_team`) was already a dict of zeros on
  both sides (the minimum is SOFT-only, carried by the solver's objective, never a hard floor);
  the registry compared the **source text** of the two expressions, not their values, so it stayed
  green while believing a divergence that never existed.
- Fails loud, never silent, on a `*args`/`**kwargs` splat (individual keyword feeds become
  unreadable) or on the anchor call count drifting from exactly one.

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

⚑ **Patron du TÉMOIN** : un parcours qui peut devenir vide sans le dire est un faux vert — donnez-lui
un signal qui ÉCHOUE en le disant. Deux instances vécues : `modal-reachability.spec.ts` (§4 ci-dessous)
échoue si RIEN ne déborde au lieu de laisser passer un test qui ne teste rien ; `journey.spec.ts`
(P4-168, 2026-09-03) distingue « le planning est arrivé PAR le flux SSE » de « … par le repli polling,
hub muet » via un témoin DOM sans rendu (`ScheduleStreamWitness`, `data-schedule-stream-events`) — le
compteur d'événements survit à la fermeture normale du flux en fin de génération, contrairement à l'état
`connected` seul, qui retomberait avant que le témoin ne soit lu.

⚠ **Un piège d'environnement à connaître avant d'accuser le code** : les mails partent par le bus,
donc par le conteneur `messenger-worker` — qui **s'arrête sur son time-limit horaire**. Worker
mort = aucun mail de vérification = tout parcours qui s'inscrit échoue dans `fetchVerificationToken`,
un échec qui ressemble à un bug produit. La cible `make -C frontend e2e` le relève d'elle-même
(`compose up -d --wait`) ; une invocation `npx playwright test` directe, elle, **saute ce
self-heal** (il est conditionné à l'absence de `E2E_BASE_URL`). Constaté le 2026-08-21 : worker
arrêté depuis 9 h. Et la boîte Mailpit est désormais **vidée avant chaque inscription**
(`submitRegister`) — accumulée sur des dizaines de runs locaux, la recherche `to:{email}` finissait
par ne plus rendre le bon message.

⚑ **Depuis le 2026-09-18, ce risque est atténué côté compose** : `docker-compose.yml` pose
`restart: unless-stopped` sur `messenger-worker`, et depuis P4-220 (même jour) sur tous les autres
services de dev durables aussi (§4 `project-map.md`) — une sortie sur le time-limit horaire, ou sur
un `cache:clear` qui invalide le cache que le worker avait chargé, ne le laisse plus mort en
silence : il repart seul en quelques secondes, sur le code ET le cache courants. Le self-heal
`compose up -d --wait` ci-dessus reste utile pour le cas qu'il couvre seul : un worker jamais
démarré du tout (stack partiellement montée) ou arrêté volontairement (`docker compose stop`, que
`unless-stopped` respecte).

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

**Session superadmin UNIQUE par run (2026-09-15)** : ce même quota `admin_auth` (5 / 15 min) était
franchi dès que plusieurs specs superadmin se reloguaient, multiplié par `retries: 2` en CI — le
429 se déguisait en « la console ne s'ouvre pas après le TOTP ». Le login vit désormais dans un
**projet Playwright `setup`** (`superadmin.setup.ts`, patron officiel `dependencies` + `storageState`)
qui ouvre UNE session par run et la fige ; le projet `superadmin` la réutilise (`storageState`,
`retries: 0` — un retry ne rejouerait aucun login). Sans préflight, le setup écrit un état VIDE et
les specs se skippent via leur garde. **Le limiteur backend n'est pas touché.**

**Dockerized run (P4-33, 2026-08-04)** : `make -C frontend e2e` exécute la suite DANS le service compose `e2e` (image officielle Playwright **épinglée sur la version de `@playwright/test`** du lock — une dérive = « browser not found ») : l'hôte n'a plus besoin de Node, dernier maillon qui l'exigeait. Cibles internes au réseau (`E2E_BASE_URL=http://frontend-dev:5173`, `MAILPIT_WEB_URL=http://mailpit:8025`), donc stack + `make -C frontend dev` doivent tourner ; Vite doit autoriser le host interne (`server.allowedHosts: ['frontend-dev']` — sans quoi 403 « Blocked request »). La CI, elle, garde son chemin Node natif (elle installe déjà Node pour Vite) : elle installe SON PROPRE chromium (`npx playwright install chromium`, `ci.yml:1078-1082`, caché par un hash du lock), donc une montée de version de `@playwright/test` qui n'a pas suivi le tag `mcr.microsoft.com/playwright` de l'image `e2e` casse la suite e2e **locale en silence** sans jamais rougir la CI — le navigateur manque uniquement chez qui essaie `make -C frontend e2e`. Constaté le 2026-09-21 (image restée en `1.62.0-noble` pendant que le lock montait en `1.63.0`), corrigé par `b7959d98`, désormais gardé par `PlaywrightImageMatchesLockTest` (`backend/tests/Unit/Dependency/`, compare le tag `image` du service `e2e` à la version RÉSOLUE de `@playwright/test` dans le lock — non bloquant, `phase1`, `unit-tests` seul).

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
