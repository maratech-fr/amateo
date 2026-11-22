# Testing Strategy — Amateo

Scope: backend + engine. The rebuilt frontend has its own tests (Vitest + RTL unit/integration with `vi.mock`, Playwright e2e in `frontend/tests/e2e`, and the container screenshot pipelines). Companion to [`/CLAUDE.md`](../../CLAUDE.md) §4, [`blocking-tests.md`](blocking-tests.md) (la liste canonique), [`test-coverage-map.md`](test-coverage-map.md) (qui teste quoi, angles morts), [`tests-par-parcours.md`](tests-par-parcours.md) (ce que chaque niveau doit prouver, par parcours — la charte de l'agent `tank`) and [`../project-map.md`](../project-map.md).

---

## 1. CI pipeline (`.github/workflows/ci.yml`)

**Le gate complet** — toute PR vers `main`, PR finale d'un lot comprise. Order and dependencies
(tout `needs` ci-dessous s'ajoute à `needs: preflight`, que **tous** les jobs du gate complet portent) :

```
preflight ─► (tous les jobs du gate complet) — « Preflight » : refuse une PR de fork, sans checkout, required check
lint ──┐
       ├─► blocking-tests ──┐
phpstan┘                    ├─► build-docker
engine-tests ───────────────┘
backend-tests       (suite PHPUnit COMPLÈTE, sans couverture, un seul passage) — BLOCKS the merge (required check), does NOT gate build-docker
e2e                 (Playwright dans le conteneur `e2e`, full stack + Vite) — BLOCKS the merge (required check)
frontend            (lint + tsc -b + vite build + vitest, sans couverture) — BLOCKS the merge (required check), does NOT gate build-docker
dependency-audit    (composer/npm/pip audit, A18)          — no other needs ; BLOCKS the merge, does NOT gate build-docker
rector              (dry-run, style gate P4-24)            — gates NOTHING… but BLOCKS the merge
secrets-scan        (gitleaks)                             — no other needs ; BLOCKS the merge
semgrep             (security gate)                        — no other needs ; BLOCKS the merge
engine-semantics    (groupe `contract`, cross-stack)       — BLOCKS the merge
functional-tests    (Behat, Gherkin FR, API-only, one feature per promise) — BLOCKS the merge (required check of `main`)
engine-tests        (pytest --cov + cliquet, ruff, mypy, bandit, deptry) — GATES build-docker — donc le cliquet engine gate build-docker
engine-perf         (« Engine Perf Gate » : tous les tests `-m perf`) — needs engine-tests ; NOT a required check, mais le verdict de `main` le compte
```

**Hors du gate complet** — chacun est le seul job de son régime, sans `needs` :

```
lot-gate            (« Lot Gate » : gitleaks, moteur, front, PHPStan, liste bloquante) — PR vers `lot/<sujet>` seulement ; seul check requis du ruleset `lot-gate`
main-verdict        (« Main Verdict » : prouve que la PR mergée a joué le gate complet) — `push main` seulement ; lu par `isCiGreen`
```

**Trois régimes, triés par l'événement et la branche cible (2026-11-15).** `ci.yml` se déclenche sur
`push main` et sur `pull_request` (types `opened`, `synchronize`, `reopened`, `edited`) vers `main`
ou `lot/**` ; un push sur `lot/**` ne lance rien. Le tri se fait par l'événement et la **branche
cible**, jamais par dossier touché (aucun `paths`, aucun job sauté selon le diff) : une PR vers
`main` joue le gate complet ci-dessus ; une PR vers `lot/<sujet>` joue le seul `lot-gate` ; un
`push main` joue le seul `main-verdict`. `dependency-audit` et `build-docker` ne tournent plus qu'en
PR vers `main`. La table des régimes, le contenu et l'ordre des steps du gate de lot, ce qu'il ne
joue pas, le verdict de `main`, les gestes et les pièges :
[`../ops/ci-runner.md`](../ops/ci-runner.md) § Branche de lot (maison unique). Garde :
`CiWorkflowRegimeTest` (listes de régimes fermées, `if` exacts) ; la logique du verdict :
`scripts/ci-verdict-test.py`. **Les features Behat et les specs e2e ne changent pas de contenu :
seul leur MOMENT d'exécution change** (PR finale du lot au lieu de chaque PR du lot ; la preuve
locale avant merge d'une PR→lot : `CLAUDE.md` §7 étape 5).

**Où tourne la CI.** Le gate complet tourne sur le pool que désignent `CI_RUNS_ON` /
`CI_RUNS_ON_HEAVY` (la VM auto-hébergée du fondateur une fois les variables posées, GitHub sinon) ;
le gate de lot et le verdict sont hébergés par défaut (`CI_RUNS_ON_LOT`, `CI_RUNS_ON_VERDICT`).
Routage, retour arrière, VM éteinte : [`../ops/ci-runner.md`](../ops/ci-runner.md). Avec les
required checks (15, **Preflight** compris) et `strict` (branche à jour obligatoire), l'arbre mergé
est celui que la PR a prouvé. **Ce que `build-docker` prouve** : que les six images de PROD se
construisent (`--pull`, tags `scan-<run>-<attempt>/…`) et passent Trivy CRITICAL ; sur le runner il
profite du cache de couches du démon, donc **il ne prouve plus un build de zéro** — `deploy.yml`, chez
GitHub, le prouve à chaque release.

**Un seul passage par suite.** Chaque suite tourne UNE fois en CI : `engine-tests`, `frontend` et
`backend-tests` (ci-dessous) portent leur suite, sans job de couverture à part. Seule la couverture
du moteur reste dans chaque run (plus bas) ; celles du backend et du front se mesurent chaque lundi
(§ « Couverture hebdomadaire »). Parce que `engine-tests` est dans les `needs` de `build-docker`,
**le cliquet engine gate `build-docker`** (décision fondateur 2026-10-14) ; `backend-tests` et
`frontend` n'en sont pas des `needs`.

**`backend-tests`** lance la suite PHPUnit COMPLÈTE `phpunit tests/ --exclude-group contract` en UN
SEUL passage, **sans couverture** : le check « Backend Tests (suite + couverture) » garde son nom
(required check de `main`), il ne mesure plus la couverture. **`needs: preflight` seul** : il ne dépend d'aucun autre job.
`timeout-minutes: 45`, `fetch-depth: 0` (pour `RoadmapIdentityTest`, qui rejoue l'histoire git de
la roadmap). **Bloque le merge** ; **absent des `needs` de `build-docker`** — une régression ne
prend jamais en otage une image de prod. Le groupe `contract` reste exclu (il interroge le VRAI
moteur → job dédié « Engine semantics »). Dernier acte du step : la durée de la suite contre son
plafond (§ « Plafond de temps »).

La **couverture engine** (P4-166) est mesurée DANS `engine-tests` : son step `pytest` lance
`pytest --cov=app` avec `--cov-fail-under` lu de `coverage-floor.json` (racine, clé `engine`) —
jamais un seuil en dur. Parce que `engine-tests` gate `build-docker`, une régression de couverture
engine bloque l'image. `set -o pipefail` protège le `| tee`, `-u` mappe l'uid du runner (SEC-15).
Artefact `coverage-engine` (xml + résumé texte), `upload-artifact` avec `if: always()`. Le plancher
versionné et sa règle de cliquet : [`test-coverage-map.md`](test-coverage-map.md) (§ `coverage-floor.json`).

**Couverture hebdomadaire (backend et front).** `.github/workflows/quality-weekly.yml` (« Quality
Weekly », cron lundi 05:00 UTC et `workflow_dispatch`, classe lourde de la VM) joue deux jobs, aucun
ne tourne sur une PR ni un push :
- `Coverage (backend)` : la suite PHPUnit sous `pcov` (`-d pcov.enabled=1`, `memory_limit=1G`), puis
  `backend/scripts/coverage-gate.php` (lit le clover, compare au plancher `backend` de
  `coverage-floor.json`, sort 1 dessous — PHPUnit 11 n'a pas de `--fail-under` natif). `pcov` est
  **chargé mais INERTE** (`pcov.enabled = 0` dans `docker/php/Dockerfile`) et n'existe QUE dans
  l'image dev/test (`ARG WITH_PCOV=0` par défaut, mis à `1` par `docker-compose.yml` et l'action
  `build-php-cached`) ; l'image de PROD ne l'embarque jamais.
- `Coverage (frontend)` : `npm run test:coverage` (Vitest `--coverage`), `thresholds.lines` lu du
  plancher `frontend` de `coverage-floor.json`.

Un rouge ne bloque aucun merge : c'est une alarme. `make ci-mesure` lit la conclusion du dernier run
et imprime la ligne de roadmap à ouvrir ; un retrait de tests conforme qui franchit un plancher se
signale, il ne s'annule pas (`tests-par-parcours.md` §3). Artefacts `coverage-backend` et
`coverage-frontend` : rétention 7 jours. Les planchers ne changent pas ; leur règle de cliquet et
les exclusions déclarées : [`test-coverage-map.md`](test-coverage-map.md) (§ `coverage-floor.json`).
Pool, VM éteinte, relance : [`../ops/ci-runner.md`](../ops/ci-runner.md) §8.

**Plafond de temps par suite.** `test-time-ceiling.json` (racine) fixe, pour `backend`, `behat`,
`e2e`, `frontend` et `engine`, un plafond en secondes et une marge en pourcentage.
`scripts/suite-time.py --suite <nom> --seconds "$SECONDS"` est le dernier acte de chaque suite dans
`ci.yml` ; il ne rend un verdict que sur la VM (indicatif sur un runner hébergé). La garde
`--never-rises`, dans `lint` et `lot-gate`, refuse toute hausse d'un plafond ou d'une marge sans la
ligne datée du fondateur dans le corps de la PR. Gestes, diagnostic d'un rouge, descente des
plafonds : [`../ops/ci-runner.md`](../ops/ci-runner.md) §8.

**`engine-perf`** (« Engine Perf Gate ») est le seul job de perf du moteur : un job du gate complet
d'une PR vers `main`, `needs: [preflight, engine-tests]`, `timeout-minutes: 30`. Il lance
`pytest -m perf --durations=0` — tous les tests `perf` de `engine/tests/perf/` (grand club dense,
BCCL, génération nominale BCCL, construction et résolution de `/place-matches`), sans `-k` ni filtre
par chemin : une PR qui ne touche pas `engine/` le paie aussi. La CI ne pose aucun
`PERF_BUDGET_SECONDS` (le budget de 60 s des grands clubs tient, un retour au blocage mono-worker à
612 s rougit) ; ce réglage ne sert qu'à relever le budget d'un run local sur une machine plus lente.
**Non requis au merge** (« Engine Perf Gate » n'est pas l'un des 15 checks de `main`), mais le
verdict de `main` le compte comme chacun des autres jobs du gate. Une PR vers un lot ne le joue pas.
On ne « réessaie » jamais un flake de perf en CI : si ce gate rougit, on mesure.

**Gardes d'hygiène des dépendances (2026-09-30)** — trois outils, un par zone, chacun un step
dans un job EXISTANT (pas de job dédié) :
- **knip** (frontend) — `npm run lint:deps` (`frontend/knip.json` : entrées `worker.js`,
  `worker-heartbeat.js`, `tests/e2e/*.setup.ts` ; projet `src/**`, `tests/**`, `tooling/**`),
  step du job `frontend`. Rougit sur une dépendance déclarée mais jamais importée ou un fichier
  source inatteignable depuis les entrées ; le mode `--dependencies --files` laisse les exports
  inutilisés HORS du gate. **Déjà dans `make -C frontend lint`** (donc dans `make -C frontend test`,
  qui l'appelle) : une dérive knip se voit en local avant la CI. Faux positif : ajouter
  le paquet à `ignoreDependencies` dans `frontend/knip.json`, avec sa raison en commentaire JSON
  n'étant pas supporté, la documenter dans le message du commit qui l'ajoute.
- **composer-unused** (backend) — `composer composer-unused` (config `backend/composer-unused.php`,
  16 filtres nominatifs, chacun sa raison en commentaire PHP : bundle câblé par `bundles.php`,
  DSN/YAML, ou runtime — jamais un symbole PHP importé), step du job `phpstan`. **Déjà dans
  `make -C backend lint`** (cible dédiée `make -C backend composer-unused`) ; pas dans `phpstan`,
  `test` ni `tests-complete`. Faux positif : ajouter
  `->addNamedFilter(NamedFilter::fromString('vendor/paquet'))` à `backend/composer-unused.php`
  avec sa raison en commentaire.
- **deptry** (engine) — `deptry .` (config `[tool.deptry]` de `engine/pyproject.toml` : exclusions
  d'environnement + `per_rule_ignores.DEP002` pour les CLI/plugins pytest jamais `import`és —
  `uvicorn`, `ruff`, `mypy`, `bandit`, `pytest-cov`, `pytest-timeout`, `deptry` lui-même), step du
  job `engine-tests`. Rougit sur DEP001 (import manquant du lockfile), DEP002 (déclaré, jamais
  importé) ou DEP003 (transitive seulement). **Déjà dans `make -C engine lint` ET `make -C engine
  test`** — comme les deux gardes ci-dessus dans le `make lint` de leur zone, une dérive se voit donc
  en local avant la CI. Faux positif : ajouter l'entrée à `per_rule_ignores` dans `engine/pyproject.toml` avec sa
  raison en commentaire.

Les trois sont des gardes d'**hygiène**, pas de sécurité : elles bloquent sur un paquet mort, pas
sur une faille (`dependency-audit`, ci-dessus, reste le seul gate de vulnérabilités).

**SEPT jobs isolés du gate bloquant** — `frontend`, `dependency-audit`, `rector`, `secrets-scan`, `semgrep`, `engine-semantics`, `functional-tests` (Behat — remplace les anciens smokes bash, tous supprimés) : ils ne dépendent que de `preflight` (jamais de `blocking-tests` ni de `build-docker`). Un signal qui peut
rougir sur un commit qui n'a rien changé (une règle Rector élargie par un bump, une advisory publiée
ce matin) ne doit pas prendre en otage `blocking-tests` — donc l'isolation tenant/RLS — ni
`build-docker`, donc la livraison d'un correctif de sécurité. **Rougir ≠ ne rien bloquer** : les sept
sont des **required status checks** de `main` — `rector` et `dependency-audit` compris — ils
bloquent le merge sans gater aucun job. Même raison pour laquelle `SymfonyStackAlignmentTest` tourne
dans `backend-tests` et non dans le gate bloquant.

**Régime de permissions des workflows (P4-92)** : chaque workflow de `.github/workflows/` déclare un bloc `permissions:` racine explicite (`contents: read` sur `ci.yml`, surchargé `packages: read` sur `secrets-scan`/`build-docker`/`lot-gate` qui pullent une image miroir ghcr, et `actions: read` + `pull-requests: read` sur `main-verdict` qui ne fait que lire l'API ; `contents: read` + `packages: read` à la racine de `security-weekly.yml`), cliquet gardé par `WorkflowPermissionsDeclaredTest` (testsuite `Unit`, **ne gate pas**) qui rougit sur un bloc racine manquant ou un scope `write` hors de la liste fermée `deploy.yml`/`mirror-images.yml` — ce n'est PAS un correctif de faille, `default_workflow_permissions` valant déjà `read` côté dépôt.

**Connexion Docker Hub (anti-429, 2026-10-09)** : chaque job qui tire une image Docker Hub (build/pull de bases `php`/`node`/`nginx`/`python`/`postgres`, `docker compose`, `semgrep/semgrep`, `aquasec/trivy`) se connecte à Docker Hub **si la variable de dépôt `DOCKERHUB_USERNAME` et le secret `DOCKERHUB_TOKEN` existent** (jeton « Public Repo Read-only ») via un step `docker/login-action` conditionnel `if: vars.DOCKERHUB_USERNAME != ''`. L'identifiant est une VARIABLE (non secrète, lisible dans un `if:`) et le jeton reste un secret lu dans le seul `with:` du step — jamais un secret dans un `env:` de workflow (règle semgrep `gha-workflow-env-secret` : il serait exposé à tout le workflow, code de PR compris). Motif : les runners partagés tapent sur le quota **anonyme** par IP et se font refuser `429 Too Many Requests`. **Sans variable/secret (PR de fork, non posés), la CI tire en anonyme exactement comme avant.** Couvre `ci.yml`, `visual-baselines.yml`, `security-weekly.yml`, `mirror-images.yml` et `deploy.yml` (builds prod) ; les images servies par `ghcr.io/maratech-fr/mirror-*` (gitleaks, trivy du build-docker) restent tirées de ghcr et ne sont pas concernées.

**Régime de dépréciations** : `phpunit.xml.dist` fixe `SYMFONY_DEPRECATIONS_HELPER` à `max[direct]=0`, ce qui **définit ce qui peut rougir `backend-tests`** (et tout job PHPUnit) — une dépréciation `direct` (une API Symfony dépréciée appelée par notre code, dont l'avertissement part du vendor et non de nos fichiers) fait échouer la suite, attrapant la dérive vers Symfony 8.4 que le seuil `self` (dépréciations émises depuis nos seuls fichiers) laisserait passer.

**Piège générique `run: … | tee …` dans un `step` GitHub Actions (P4-256, e2e)** : le shell par défaut d'un `run:` sur un runner Linux est `bash -e {0}`, **sans** `pipefail` (il ne s'arme qu'avec un `shell: bash` explicite au niveau du step ou du job, absent de ce workflow) — un `| tee` sans `set -o pipefail` explicite dans le corps du `run` renvoie le code de sortie de `tee` (toujours 0) et masque un échec de la commande en tête de pipe. Tout nouveau `run: … | …` dans `ci.yml` doit poser `set -o pipefail` en première ligne s'il doit pouvoir faire rougir le step.

**Garde de captures de référence (`frontend/tests/e2e/visual-reference.spec.ts`, PR 8/8 série
« uniformité des écrans »)** : 9 `toHaveScreenshot` figent l'apparence des écrans retravaillés par
la série (login, register, planning semaine, matchs calendrier, matchs semaine type, matchs
contraintes club, club, wizard coachs, wizard contraintes) — thème clair forcé, animations
désactivées, horloge navigateur **et** serveur (`POST /api/dev/clock`) gelées sur l'instant
canonique des features Behat et relâchées en `finally`, widget `DevClock` masqué,
`maxDiffPixelRatio: 0.01`, viewport 1280×900. Le **gate** réel vit dans le job `e2e` ci-dessus
(required check « E2E (Playwright) ») : il compare aux PNG commités
(`frontend/tests/e2e/visual-reference.spec.ts-snapshots/`), il ne réécrit **jamais** les
références. **Re-baseline — une procédure, un workflow dédié**
(`.github/workflows/visual-baselines.yml`, `workflow_dispatch` seul, jamais en local — les PNG
doivent naître dans l'environnement EXACT de la CI — le **conteneur Playwright** du service compose `e2e`, le même que celui du job `e2e`, où que le job tourne) :
`gh workflow run visual-baselines.yml --ref <branche> -f update=true` → `gh run download <run-id>
-n visual-baselines` → committer le dossier `-snapshots/`, re-pousser. Le mode `update=true`
lance `--update-snapshots=all` : il réécrit TOUTES les références, pas seulement celles en échec
— sinon un changement de texte sous la tolérance de 1 % garderait l'ancien texte en silence.
Déclenche une re-baseline :
un changement d'apparence VOULU, un changement de l'image Playwright (épinglée, parité avec
`package-lock.json` gardée par `PlaywrightImageMatchesLockTest`), ou une dérive du seed BCCL. Les PNG
dépendent de cette image, plus de l'hôte : passer la CI du runner auto-hébergé à GitHub (retour
arrière) ne demande aucune re-baseline. Hors périmètre, délibérément : la page publique des vœux (token non forgeable sans base
réelle) et le thème sombre.

All PHP test jobs first **create + migrate the test DB** (`doctrine:database:create --if-not-exists` + `migrations:migrate`, `--env=test`) and run phpunit with `-e APP_ENV=test` on the `docker compose exec` — the containers default to `APP_ENV=dev` (root `.env` env_file) and `phpunit.xml.dist`'s `<server APP_ENV=test>` is not `force`d, so the real env var must be set explicitly.

| Job | What it runs |
|-----|--------------|
| `lint` | `docker compose config` + `make -n help` + step `ci-verdict self-test` (`scripts/ci-verdict-test.py`, la logique du verdict de `main`) + la garde `--never-rises` des plafonds de temps et les auto-tests de `suite-time.py`, `ci-mesure.py` et `tests-retires.py` + step final `ci-releve self-test` (`scripts/ci-releve-test.py`, l'outil `make ci-releve` : `docs/ops/ci-runner.md` § Exploitation) ; câblage gardé par `CiReleveWiringTest` (statique, non bloquant) |
| `phpstan` (job name: **PHPStan & CS-Fixer**) | `composer phpstan` (level 8) **+ `composer cs-fix -- --dry-run --diff`** **+ `composer composer-unused`** — needs postgres + redis. CS-Fixer vit ici, et non dans `lint`, parce que ce job a déjà le conteneur PHP que `lint` n'a pas (jusqu'au 2026-07-17 CS-Fixer ne tournait **nulle part** en CI, et `main` a été mergée rouge dessus deux fois) |
| `rector` (**Rector (style gate)**) | `composer rector -- --dry-run` (P4-24). Job **dédié, `needs: preflight` seul**, dépendance d'aucun autre — mais le contexte « Rector (style gate) » fait partie des **required status checks de `main`**, donc **il bloque le merge**. Corriger en local : `docker compose exec php-fpm sh -c 'cd /app/backend && composer rector'` (`make -C backend rector` est un dry-run : il montre, il ne fixe pas) |
| `blocking-tests` | les tests sécurité/queue/contrat lancés en **UNE invocation PHPUnit** (step `Blocking suite (phase1, one PHPUnit process)` : `--group phase1` + la liste des chemins, un par ligne, chacun précédé de son commentaire de raison) **plus un step filtré** (`ParcImportCorrectionTenantNR`, classe `integration` hors `phase1`) — **gate du reste de la suite PHP** et de `build-docker`. ⚠ **La liste vit dans [`blocking-tests.md`](blocking-tests.md), et NULLE PART AILLEURS** : elle était recopiée ici et les deux copies ont dérivé l'une de l'autre (audit DOC-16 puis DOC-26, 3 éditions). Deux endroits pour une même vérité finissent par diverger — la copie est supprimée, pas resynchronisée. ⚠ **`--group phase1` ≠ le gate** : bien plus de fichiers `backend/tests/` portent l'annotation que le step `Blocking suite` n'en liste ; un fichier `phase1` non listé tourne dans `backend-tests`, donc après le gate et sans bloquer `build-docker`. La vérité exécutable est `.github/workflows/ci.yml` |
| `backend-tests` (job name: **Backend Tests (suite + couverture)**) | full PHPUnit `tests/ --exclude-group contract` en UN passage, sans couverture (relevée chaque lundi par `quality-weekly.yml`), puis la durée de la suite contre son plafond (`scripts/suite-time.py`). **`needs: preflight` seul** (ne dépend d'aucun autre job). Remplace les ex-`unit-tests` + `backend-coverage` (fusion 2026-10-14). Required check de `main` → **bloque le merge** ; does **NOT** gate `build-docker` (P4-166) |
| `e2e` | Playwright (full stack + Vite), **sans `needs` autre que `preflight`** (2026-10-14 — ne consommait aucun artefact, le `needs: blocking-tests` n'était que de l'ordonnancement). **La suite tourne DANS le conteneur Playwright** du service compose `e2e` (image épinglée, `shm_size`), Vite dans le service `frontend-dev` (attente bornée par `wget` sur `127.0.0.1`, l'image est busybox Alpine : `localhost` s'y résout en `::1` alors que Vite n'écoute qu'en IPv4), mailpit dans le projet ; aucun Node, Chromium ni port hôte : le projet compose est propre au job (`ci-<run>-<tentative>-<job>`). Le volume `node_modules` du conteneur est chowné par l'HÔTE (guillemets doubles : `$(id -u)` évalué hors du conteneur root), la clé TOTP du superadmin jetable vit dans `RUNNER_TEMP`, jamais dans `GITHUB_ENV`. ⚠ **Deux cibles, pas une** : la suite tourne contre le **dev server** (`frontend-dev:5173`), puis un step dédié rejoue `security-headers.spec.ts` contre l'**image nginx** (service `frontend`, `E2E_BASE_URL=http://frontend`) avec `E2E_A17_REQUIRED=1`. Sans ce second passage, les tests A17 (CSP, HSTS, X-Frame-Options, nosniff) se **skippaient à chaque run** — les en-têtes n'existent que sur le build nginx — et le contrôle n'a jamais tourné en CI (audit D-04). La variable interdit au skip de revenir en silence : viser un dev server là devient un échec. **Fiabilité infra (2026-09-15)** : `COMPOSE_BAKE=false` (env du job) écarte le builder bake qui se figeait « waiting for BuildKit » ; un step **Pre-pull third-party images** (`docker compose pull --ignore-buildable`, enveloppé de `.github/scripts/retry.sh`) tire nginx/mercure/redis/postgres à part, l'image `engine` est bâtie dans son propre step relançable, et les steps d'infra (pull, build, `up --wait`) passent par `retry.sh` — un aléa de Docker Hub/BuildKit ne rougit plus une PR saine. Un step `if: failure()` écrit dans le résumé de job si l'échec est **AVANT Playwright (infra)** ou **Playwright**. **Un job vert peut cacher un flaky** (Playwright sort 0 dès qu'un retry passe, P4-256, toujours ouvert) : un step `always()` (`.github/scripts/flaky-summary.sh`) annonce dans le résumé de job les tests perdus-puis-rejoués, sans jamais faire rougir le job ni le gater. L'artefact `playwright-results` (traces `retain-on-failure`, conservées sur tout essai en échec, y compris l'essai perdu puis rejoué) est lui aussi uploadé en `always()` — la trace de l'essai perdu survit donc même sur un run vert, récupérable 7 jours |
| `functional-tests` | **Behat, Gherkin français, API seule** (`backend/features/`, contexts `backend/tests/Behat/`) — scénarios métier relus par le fondateur, joués contre la stack RÉELLE (nginx→php-fpm, vrai `messenger-worker`, vrai engine, **`pdf-worker`** compris — `l-export-du-planning.feature` attend un PDF Puppeteer réel, sans lui le worker d'export répond `failed`), sans navigateur ni noyau in-process. **`needs: preflight` seul** — ils répondent « la fonctionnalité marche-t-elle ? », indépendamment des suites unitaires, et n'installent ni npm ni Chromium : le verdict tombe plus tôt. Chaque feature est autosuffisante (JWT auto, données créées/nettoyées, pointeur socle rouvert PUIS restauré) : jouable seule et dans n'importe quel ordre. **Remplace intégralement les 5 smokes bash** (`smoke-solver.sh`, `onboarding-smoke.sh`, `smoke-place-matches.sh`, `smoke-overlay.sh`, `smoke-coach-wishes.sh`, tous SUPPRIMÉS de `backend/scripts/`) — parité prouvée assertion par assertion, même verdicts. Table feature ↔ ce qu'elle prouve : [`test-coverage-map.md`](test-coverage-map.md) §5 |
| `engine-tests` | `pytest --cov=app --cov-fail-under=$FLOOR` (`$FLOOR` lu de `coverage-floor.json`, clé `engine` — cliquet fondu ici 2026-10-14) + `ruff check .` (+ `ruff format --check`) + `mypy` + `bandit -r app/` (ENG-46, lot correctif de l'audit 0918 — bandit était déjà en local via `make test`, il ne gatait pas la CI) + `deptry .` (in the engine container). **GATE `build-docker`** → le cliquet engine gate donc aussi `build-docker` (décision fondateur 2026-10-14) |
| `frontend` | `npm run lint` (dont `eslint-plugin-jsx-a11y`, §4bis) + `npm run lint:deps` (knip) + `tsc -b` + `vite build` + `npm run test` (`vitest run`, sans couverture : plancher `frontend` relevé chaque lundi par `quality-weekly.yml`), puis la durée de la suite contre son plafond (`needs: preflight` seul). Required check de `main` → **bloque le merge** ; does **NOT** gate `build-docker` (P4-166) |
| `dependency-audit` | `composer audit` / `npm audit --audit-level=high` / `pip-audit` (A18, blocking, `needs: preflight` seul). Les trois passent par `.github/scripts/audit-retry.sh` (P4-171) : 3 tentatives (10 s, 30 s) **seulement** quand la sortie porte une signature réseau (timeout, 5xx, DNS, `curl error`) — un `exit 1` d'audit sans cette signature est rendu tel quel, le gate ne s'aveugle pas |
| `build-docker` | build des six images de PROD (dont `pdf-worker`) (`--pull`, tags `scan-<run>-<tentative>/…`) + scan Trivy CRITICAL (needs **blocking + engine** tests, plus `preflight`) ; PR vers `main` seulement, tant qu'aucun de ses `needs` n'a échoué ni été annulé |
| `engine-perf` (**Engine Perf Gate**) | `pytest -m perf --durations=0`, tous les tests `perf` (ci-dessus) ; needs `preflight` + `engine-tests` ; non requis au merge, compté par le verdict de `main` |
| `lot-gate` (**Lot Gate**) | le seul job d'une PR vers `lot/<sujet>` : gitleaks, moteur (pytest + cliquet moteur, ruff, format, mypy, bandit, deptry), front (build, gardes statiques Vitest, Vitest lié au diff par `--changed`), PHPStan, puis la liste bloquante — les steps `Blocking suite` et `ParcImportCorrectionTenantNR` de `blocking-tests`, rejoués par alias d'ancres YAML. Sans `needs`, garde fork en premier step. Contenu, ordre, ce qu'il ne joue pas : `docs/ops/ci-runner.md` § Branche de lot |
| `main-verdict` (**Main Verdict**) | le seul job d'un `push main` : `scripts/ci-verdict.py` prouve par l'API GitHub que la PR mergée qui porte le commit a joué le gate complet (chaque job de son run de PR hors « Lot Gate » et « Main Verdict » réussi). Sans `needs`, aucun Docker, aucun secret. Codes de retour, réessai, faux rouge : `docs/ops/ci-runner.md` § Branche de lot |

All PHP jobs invoke `vendor/bin/phpunit` (PHPUnit 11, the direct `phpunit/phpunit` dep) — same binary as `Makefile` and `composer test`.

---

## 2. Backend tests (`backend/tests/`)

Layout, rangé PAR NATURE dans les 3 testsuites de `phpunit.xml.dist` (`Unit` : `Unit/`, `Logging/`,
`Messenger/` — sans conteneur ; `Integration` : `Integration/`, `Security/`, `Queue/`, `Api/`,
`Command/`, `OpenApi/`, `Validator/`, `MessageHandler/`, `EventListener/` — `Kernel`/`WebTestCase` ;
`Contract` : `CrossStack/`). Chaque sous-dossier de `tests/` portant un `*Test.php` appartient à
exactement une testsuite, gardé par `Unit/TestsuitesCoverEveryTestDirectoryTest` (P4-169, 2026-09-03).

⚠️ **Le piège reste réel malgré ce rangement** : le job CI `backend-tests` lance **`phpunit tests/`, le
dossier entier**, alors que `make -C backend test` ne joue que la testsuite `Unit` (rapide, sans DB)
et `make -C backend phpunit` que `--group phase1`. Les deux couvrent TOUS les dossiers par
testsuite (rien n'y échappe en silence), mais `Integration`/`Contract` restent hors de `make test` par
construction. **`make -C backend tests-complete` vert avant de demander le GO de merge**, miroir
exact de la CI (la PR se pousse dès le rebase, cette suite tourne en parallèle — `CLAUDE.md` §10.1).

Groups (PHP attributes): `#[Group('phase1')]`, `#[Group('integration')]`, `#[Group('contract')]`, `#[Group('unit')]`. Test isolation via DAMA DoctrineTestBundle; bootstrap `tests/bootstrap.php`.

**Un test isolé (`#[RunInSeparateProcess]`/`#[RunTestsInSeparateProcesses]`) ne gèle plus le reste de la suite (2026-09-03).** `App\Tests\ReleasesParentTransactionBeforeIsolatedTests` (`backend/tests/ReleasesParentTransactionBeforeIsolatedTests.php`), extension PHPUnit déclarée dans `phpunit.xml.dist` juste APRÈS l'extension DAMA : au `TestSuite\Started` d'une classe portant un test isolé, elle force `DamaExtension::rollBack()` sur la transaction du processus PARENT. Pourquoi ce correctif : DAMA n'annule la transaction du test précédent qu'au `PreparationStarted` du SUIVANT — pour un test isolé, cet événement naît dans le processus ENFANT et n'atteint le parent qu'à la fin de l'enfant, donc le parent garde ses écritures non commitées (et leurs verrous) pendant toute la vie de l'enfant. Si l'enfant touche la même clé (constaté sur `priority_tier` id 1, créé par des tests API et retrouvé par `BcclSeeder` en find-or-create), c'est un **interblocage muet**, tranché seulement par `idle_in_transaction_session_timeout` (60 s sur `amateo_test`, réglé par `make db-init-test`, PR #281) qui tue la connexion du parent — et fait cascader tout le reste de la suite en « no connection to the server » (772 erreurs constatées le 2026-09-03 sur l'ordre `VenueUsageStatsApiTest` puis `BcclSeedCommandTest`). Détail complet (repro exacte, mécanique) : docblock du fichier.

### Double de l'écrivain d'abonnement (`tests/Double/SameTransactionClubSubscriptionWriter`)

Sous DAMA, chaque connexion a sa transaction : l'écrivain réel (`ClubSubscriptionWriter`, connexion propriétaire) ne voit pas un club semé par la connexion applicative. `config/services_test.yaml` le remplace donc par ce double, qui écrit dans la transaction applicative quand le club n'est visible que d'elle (sinon, l'écrivain réel). Un test règle l'offre d'un club par `tests/Support/SubscribesClub.php`, **jamais** par un accesseur du club ni par un `INSERT` à la main. Règle : le rôle applicatif ne reçoit **jamais** `INSERT`/`UPDATE` sur `club_subscription`, en test comme en production ; le double passe par la fonction `SECURITY DEFINER` `amateo_test_record_club_subscription`, qui n'existe que dans une base dont le nom finit par `_test` (aucune migration ne la crée ; le double refuse de la créer ailleurs). Pourquoi la table est ainsi fermée : `docs/security/rls.md` § `club_subscription`. Garde : `Config/TestDoublesStayInTheTestEnvironmentTest`.

### Blocking guardrails (`phase1`)
| Test | Asserts |
|------|---------|
| `Security/TenantIsolationTest` | a `X-Club-Id` header is ignored (own club's data only, not 403) · 200 on own club · 403 when membership inactive · 200 with no header · an anonymous request carrying the header never sets the tenant context |
| `Security/TenantCacheIsolationTest` | 2 real tests: cache invalidation isolates clubs; entity without `club_id` purges nothing. |
| `Queue/ConcurrentGenerationTest` | 2nd `ClubGenerationLock` acquire for same club fails · different clubs acquire concurrently · wrong token cannot release |
| `CrossStack/ContractSchemaTest` (`phase1`+`contract`) | engine payload shape valid (version, clubId, seasonId, teams, venues, coaches, constraints, trainingSlots, sportCategoryId, scopeTargetId…) · POSTs to the real engine when reachable, else skips |
| `Security/SuperAdminAccessTest` | club JWT rejected · password without TOTP rejected · MFA session isolated from tenants · disabled/expired admin rejected · logout protected by CSRF · IP rate limit · runtime DB role has no admin-table privilege |

`ContractSchemaTest` is the **only** guardrail for the manually-synced backend↔engine contract (no codegen). Any change to engine Pydantic schemas or the backend payload must keep it green.

### `CrossStack/` — backend↔frontend contract guards (group `contract` but one, not `phase1`)

The frontend has **no codegen**: its API types are hand-written interfaces in `features/*/api.ts`.
Four `CrossStack/` tests guard that hand-sync from four distinct angles, each blind to what the
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
- `FrontMockDateFormatMatchesContractTest` (lot bugs Rillieux #16 PR2, decision fondateur
  2026-10-22 « un mock doit envoyer ce que l'API envoie ») — a date field's NAME, not the OpenAPI
  snapshot, is the source of truth (a `string` property typed date-only has no `format: date` API
  Platform can infer): a property ending in `Date`/`Deadline` is served `Y-m-d`, a `DateTimeImmutable`
  property (`*At`) is served RFC 3339. Scans literal date-shaped values in every front `*.test.ts(x)`
  file and rougit either direction — a mock giving a date-only field a datetime, or a timestamp field
  a bare date. Nominative exemptions (`EXEMPTIONS` constant) for deliberate robustness inputs, each
  motivated and checked to still exist (`campaignWeeks.test.ts` is the one today). Companion guard
  `OpenApi/DateOnlyFieldsServeDateOnlyStringsTest` (not `CrossStack/`, not `contract` — plain
  `Integration` suite, `backend-tests` job) asserts the backend side alone: every `*Date`/`*Deadline`
  property of an `ApiResource` is typed `string`, never a raw `DateTimeImmutable`.

The first three run in the `contract` group (`engine-semantics` CI job — a required check of `main`,
**not** part of `blocking-tests`/`backend-tests`; see `docs/testing/blocking-tests.md` for what
actually gates the merge). `FrontMockDateFormatMatchesContractTest` carries **no** group: it only
reads `frontend/src` files and PHP reflection (no engine), so it runs in `make -C backend
tests-complete` and the `backend-tests` job (`phpunit tests/ --exclude-group contract`) — a mock date
mismatch is caught locally, not only in CI.

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
- **AST, not regex** — parses `app/main.py` and `validate_assignments/hard_layer.py`; resistant to reformatting.
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

⚑ **Ce risque est atténué côté compose** : `docker-compose.yml` pose
`restart: unless-stopped` sur `messenger-worker` et sur tous les autres
services de dev durables (§4 `project-map.md`) — une sortie sur le time-limit horaire, ou sur
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

**Depuis un worktree** : même commandes, sur la stack PRIVÉE de la copie (`make -C backend tests-complete` monte un socle de test `amateo-test-<hash>` ; `make -C backend test-destroy` à la fin du worktree, `test-down` = pause ; `tests-complete` balaie d'abord les stacks orphelines). Behat et e2e : `make epreuve-up WT=<worktree>` (une seule stack d'épreuve, front `:8181` — fenêtre privée, le cookie `localhost` est partagé avec `:8081`), puis `backend/scripts/with-sandbox.sh …`, puis `make epreuve-down`. Détail : `backend/docs/commands.md` §Worktrees.

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

**Session superadmin UNIQUE par run** : ce même quota `admin_auth` (5 / 15 min) était
franchi dès que plusieurs specs superadmin se reloguaient, multiplié par `retries: 2` en CI — le
429 se déguisait en « la console ne s'ouvre pas après le TOTP ». Le login vit dans un
**projet Playwright `setup`** (`superadmin.setup.ts`, patron officiel `dependencies` + `storageState`)
qui ouvre UNE session par run et la fige ; le projet `superadmin` la réutilise (`storageState`,
`retries: 0` — un retry ne rejouerait aucun login). Sans préflight, le setup écrit un état VIDE et
les specs se skippent via leur garde. **Le limiteur backend n'est pas touché.**

**Dockerized run (P4-33, 2026-08-04)** : `make -C frontend e2e` exécute la suite DANS le service compose `e2e` (image officielle Playwright **épinglée sur la version de `@playwright/test`** du lock — une dérive = « browser not found ») : l'hôte n'a plus besoin de Node, dernier maillon qui l'exigeait. Cibles internes au réseau (`E2E_BASE_URL=http://frontend-dev:5173`, `MAILPIT_WEB_URL=http://mailpit:8025`), donc stack + `make -C frontend dev` doivent tourner ; Vite doit autoriser le host interne (`server.allowedHosts: ['frontend-dev']` — sans quoi 403 « Blocked request »). La CI utilise désormais LE MÊME service `e2e` (plus de Node ni de chromium installés sur l'hôte du job), donc une montée de version de `@playwright/test` qui n'a pas suivi le tag `mcr.microsoft.com/playwright` de l'image `e2e` casse la suite e2e — en local comme en CI. Constaté le 2026-09-21 (image restée en `1.62.0-noble` pendant que le lock montait en `1.63.0`), corrigé par `b7959d98`, désormais gardé par `PlaywrightImageMatchesLockTest` (`backend/tests/Unit/Dependency/`, compare le tag `image` du service `e2e` à la version RÉSOLUE de `@playwright/test` dans le lock — non bloquant, `phase1`, `backend-tests` seul : c'est lui, et non l'e2e, qui attrape une dérive avant qu'elle ne rougisse la suite).

**Arguments de la cible `e2e`** (`frontend/Makefile`) — sans argument, la cible lance `npx playwright test` tel quel. Variables :

| Variable | Effet | Exemple |
|---|---|---|
| `SPEC` | fichiers à jouer, chemins **relatifs à `/app/frontend`** (le dossier du conteneur), pas à l'hôte | `SPEC=tests/e2e/mutualize.spec.ts` |
| `GREP` | motif passé à `-g` : c'est une **regex Playwright**, pas un sous-texte | `GREP='mutualis(e|ation)'` |
| `REPEAT` | `--repeat-each`, entier de 1 à 10 | `REPEAT=5` |
| `RETRIES` | `--retries`, 0, 1 ou 2 (la CI joue 2 ; en local le défaut est 0, `playwright.config.ts:12`) | `RETRIES=2` |
| `IGNORE_SNAPSHOTS` | `1` ajoute `--ignore-snapshots` | `IGNORE_SNAPSHOTS=1` |

Les plafonds sont **durs** et contrôlés au chargement du Makefile, avant toute recette : une valeur refusée n'allume rien, quelle que soit la cible (`make help REPEAT=11` est refusé aussi), et la variable d'environnement (`REPEAT=11 make …`) ne les contourne pas. Liste blanche stricte : `1 2`, `%` et une valeur vide explicite sont refusés. Le plafond de 10 répétitions par spec en local est une décision fondateur du 2026-11-10 ; il se joue hors heures de travail, et au-delà on mesure sur la CI. Les arguments voyagent par variables exportées puis `-e VAR` sans valeur (une apostrophe dans le motif ne casse pas la ligne côté hôte). La cible reste refusée en worktree nu : elle se joue sur la stack d'épreuve (`make epreuve-up WT=<worktree>`, puis `with-sandbox.sh`). Le `chown` du volume `node_modules` est évalué côté hôte (UID/GID de l'utilisateur, comme le job CI `e2e`).

⚠ Non prouvé à ce jour par un vrai run Playwright : le développement des variables par le shell de l'image `e2e` et l'effet du `chown` sur un volume neuf (contrôlés seulement par `make -n`).

**Lecture d'une trace** (une trace est retenue sur échec, `trace: 'retain-on-failure'`) : ouvrir d'abord `error-context.md` du dossier du test (état de la page à l'échec), puis la chronologie réseau de la trace. Visualiseur : `docker compose --profile tools run --rm --no-deps -p 9323:9323 -v <dossier>:/traces:ro e2e npx playwright show-trace …` (chemin du `trace.zip` sous `/traces`). **Jamais `trace.playwright.dev`** : la trace porte le nom d'un club et des cookies de session, elle ne quitte pas le poste.

---

## 4bis. Frontend accessibility guardrail (WCAG 2.2 AA)

Two layers, added as **tests** so any frontend change is checked against the norm:

- **Static lint (blocking)** — `eslint-plugin-jsx-a11y` (recommended set) runs inside `npm run lint` (CI `frontend` job) at **`error`**. A single knob `A11Y_LEVEL` in `eslint.config.js` drives warn-vs-block (kept `error` now that the known violations are fixed; flip to `warn` only to temporarily unblock a large refactor). The remapping preserves each rule's tuned options and never re-enables the rules recommended disables. `label-has-associated-control` is told our custom control components (`Input`/`Select`/`TeamSelect`). The few intentional `autoFocus` uses (modal step fields, revealed rename/search inputs) carry a justified inline disable.
- **Structural axe** — `vitest-axe` asserts `toHaveNoViolations()` on the shared primitives (`src/test/a11y.test.tsx`, via `expectNoA11yViolations()` in `src/test/utils.tsx`) and the Modal (focus into panel on open, Escape close, focus restoration WCAG 2.4.3). Component-specific a11y lives in each component's own test where the fixtures already are: `MonthCalendar.test.tsx` (info emojis expose a text alternative, A11Y-05) and `WeekGrid.test.tsx` (venue named as text in every view, not colour only, A11Y-01). jsdom has no layout engine, so axe **skips colour-contrast (WCAG 1.4.3)** — that axis (A11Y-06) is a **follow-up** Playwright/axe pass in a real browser.

Shared modal a11y is one hook — `useModalA11y` (`src/shared/lib/useModalA11y.ts`): focus-trap + initial focus + focus restoration + Escape, applied to both `Modal` and `ConfirmDialog` (the audit's A11Y-03 / FRT-12/13 / UXC-02 came from per-modal divergent handling).

Matcher wiring: runtime `expect.extend` in `src/test/setup.ts`; the vitest-v3 type augmentation is `src/test/vitest-axe.d.ts` (vitest-axe ships only a stale global `Vi.Assertion`).

## 5. Known testing gaps
- **A11Y-06 — le contraste de couleur (WCAG 1.4.3) n'est vérifié par AUCUN test** : jsdom n'a pas de moteur de layout, donc axe **saute la règle** (cf. §4bis). La passe Playwright/axe en vrai navigateur reste à faire — jusque-là, un contraste insuffisant passe la CI.
