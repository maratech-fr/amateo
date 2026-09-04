# Carte de la couverture de tests — qui teste quoi, ce qui gate, ce qui manque

Last verified @ 2026-09-04 (P4-166 PR 3/3 — **lot P4-166 SOLDÉ, angle mort n° 1 §4 DISPARAÎT** :
les trois zones sont mesurées et gardées — re-vérifié `.github/workflows/ci.yml` (job
`backend-coverage`, `needs: blocking-tests`, absent des `needs` de `build-docker`),
`backend/phpunit.xml.dist` (bloc `<source>`, périmètre `src` avec exclusions déclarées),
`backend/scripts/coverage-gate.php` (lit le clover, plancher `backend`, sans dépendance),
`backend/Makefile` (cible `coverage`), `docker/php/Dockerfile` (`ARG WITH_PCOV=0`, pcov chargé
INERTE, jamais dans l'étage `prod`) et `coverage-floor.json` (racine, clé `backend` renseignée) ;
sa trace vit dans `specs/courantes/etat-des-lieux.md` §3).
Un stamp REMPLACE, l'historique vit dans git : `git log -p --follow docs/testing/test-coverage-map.md`.

> **Ce que ce fichier est** : la carte, pour le fondateur et pour un agent, de **ce que chaque outil
> prouve**, **par quel job CI**, et **ce que personne ne prouve**. Il ne remplace ni
> [`testing-strategy.md`](testing-strategy.md) (le graphe des jobs, les pièges, les asymétries
> déclarées) ni [`blocking-tests.md`](blocking-tests.md) (la liste canonique de ce qui gate). Il ne
> porte **aucun compte** (ils pourrissent en jours) — chaque section donne la commande qui le recalcule.

## 1. Qui teste quoi

| Outil | Zone | Ce qu'il prouve | Où | Local | Job CI |
|---|---|---|---|---|---|
| PHPUnit `Unit/` | backend | classes pures, sans conteneur ni DB (`extends TestCase`) — la testsuite `Unit` couvre aussi `tests/Logging/` et `tests/Messenger/` (rangement PAR NATURE, pas par nom de dossier) ; **couverture + cliquet** (`make -C backend coverage`, driver `pcov` sur `phpunit tests/ --exclude-group contract`, plancher lu de `coverage-floor.json`, gate `backend/scripts/coverage-gate.php`, artefact `coverage-backend`) | `backend/tests/Unit/` | `make -C backend test` (testsuite `Unit` **seule**, §10.1 de `CLAUDE.md`) · `make -C backend coverage` (couverture, séparé) | `unit-tests` ; `backend-coverage` (couverture, `needs: blocking-tests`, hors `needs` de `build-docker`) |
| PHPUnit `Integration/` | backend | `WebTestCase`/`KernelTestCase` sur DB réelle (DAMA, RLS) : API (`Api/`), services, commandes console (`Command/`), contrôleurs, listeners (`EventListener/`, `MessageHandler/`), OpenAPI, validateurs — la testsuite `Integration` couvre `tests/Integration/` + `Security/` + `Queue/` + `Api/` + `Command/` + `OpenApi/` + `Validator/` + `MessageHandler/` + `EventListener/` | `backend/tests/Integration/` | `make -C backend tests-complete` (miroir CI) | `unit-tests` + quelques steps de `blocking-tests` |
| PHPUnit `Security/` | backend | isolation tenant / saison / rôles / RLS / rate-limit / superadmin / verrous de période | `backend/tests/Security/` | idem | **la majorité des steps de `blocking-tests`** |
| PHPUnit `CrossStack/` | backend ⇄ engine, backend ⇄ frontend | contrats : forme du payload ⇄ Pydantic (`*ContractSchemaTest`), `CONTRACT_VERSION`, parités de payload, **miroirs front déclarés** (`FrontRederivationRegistryTest`, `CapacityMirrorParityTest`) | `backend/tests/CrossStack/` | `phpunit --group contract` | steps de `blocking-tests` + `engine-semantics` (groupe `contract` **contre le vrai engine**) |
| pytest | engine | unitaires du solveur (racine), **sémantiques** (`tests/semantic/` : une contrainte saisie est honorée, pas juste `COMPLETED`), goldens (`tests/golden/`, BCCL d'acceptation compris), invariants, perf (`-m perf`, budget lu par `_budget_seconds()` — `PERF_BUDGET_SECONDS` en override) ; **couverture + cliquet** (`make -C engine coverage`, plancher lu de `coverage-floor.json`, artefact `coverage-engine`) | `engine/tests/` | `make -C engine test` (ruff + format + mypy + bandit + pytest, **sans** couverture depuis P4-166) · `make -C engine coverage` (couverture + cliquet, séparé) | `engine-tests` ; `engine-coverage` (couverture, `needs: engine-tests`, hors `needs` de `build-docker`) ; `engine-perf` (main, dense + BCCL, 60 s) ; `engine-perf-pr` (PR, dense seul, quand `engine/**` ou `docker/engine/**` bouge) |
| Vitest + RTL | frontend | composants, hooks react-query, lib pure (`vi.mock` des queries) ; jsdom — **aucune mise en page** (`.claude/rules/frontend.md`) ; **couverture + cliquet** (`make -C frontend coverage`, plancher lu de `coverage-floor.json`, artefact `coverage-frontend`) | `frontend/src/**/*.test.ts*` | `make -C frontend test` (image tooling à rebâtir avant) · `make -C frontend coverage` (couverture, séparé — suite complète instrumentée, hors boucle courte) | `frontend` ; `frontend-coverage` (couverture, `needs: frontend`, hors `needs` de `build-docker`) |
| Playwright | frontend + stack complète | 11 parcours nommés en §2 — dont **le seul test UI → API → engine → planning** (`journey.spec.ts`, qui prouve aussi la livraison PAR SSE : témoin Mercure, échec nommé si le hub reste muet — P4-168) et 4 specs **axe** (contraste 2 thèmes, reflow, voile, écrans système) | `frontend/tests/e2e/` | `make -C frontend e2e` | `e2e` |
| Smokes bash | stack complète | 5 preuves sémantiques de bout en bout (§2), chacune autosuffisante (JWT, données, restauration) | `backend/scripts/*smoke*.sh` | `backend/scripts/<smoke>.sh` (sous `with-sandbox.sh` en mode play) | `smoke-tests` |
| Statique | 3 zones | PHPStan 8 · CS-Fixer · Rector — ruff · `ruff format` · mypy strict · bandit — eslint · `tsc -b --force` | Makefiles | `make lint` | `phpstan`, `rector`, `engine-tests`, `frontend` |
| Sécurité | dépôt, images | gitleaks (historique entier), semgrep, `composer`/`npm`/`pip audit` (retry sur endpoint indisponible seulement, `.github/scripts/audit-retry.sh`), Trivy CRITICAL sur les images prod | `.github/workflows/` | — | `secrets-scan`, `semgrep`, `dependency-audit`, `build-docker` + cron hebdo `security-weekly.yml` |

Les trois testsuites (`Unit`, `Integration`, `Contract`) couvrent désormais **tous** les sous-dossiers de
`backend/tests/` portant un `*Test.php` (rangement PAR NATURE : `TestCase` pur → `Unit`, `Kernel`/
`WebTestCase` → `Integration`) — gardé par `Unit/TestsuitesCoverEveryTestDirectoryTest`.

Recalculer les tailles : `find backend/tests -name '*Test.php' | awk -F/ '{print $3}' | sort | uniq -c` ·
`find engine/tests -name 'test_*.py' | awk -F/ '{print $3}' | sort | uniq -c` ·
`find frontend/src -name '*.test.ts*' | wc -l` · `ls frontend/tests/e2e/*.spec.ts`.

## 2. Ce qui est prouvé de bout en bout (par axe structurant, `CLAUDE.md` §7.1)

| Axe | Preuve principale | Où |
|---|---|---|
| Isolation tenant | `TenantIsolationTest`, `RlsIsolationTest`, `TenantCacheIsolationTest`, `MatchTenantIsolationTest` | `blocking-tests` |
| Pipeline de génération | `ConcurrentGenerationTest` (verrou) ; **`journey.spec.ts`** (wizard → génération CP-SAT réelle → planning validé → cockpit — **et livrée PAR le canal Mercure/SSE**, pas seulement par le repli polling : témoin `ScheduleStreamWitness`, `data-schedule-stream-events` ≥ 1 exigé, P4-168) ; smokes `smoke-solver.sh` (rail async → `COMPLETED`) et `onboarding-smoke.sh` (club neuf → minimum → `COMPLETED`) | `blocking-tests`, `e2e`, `smoke-tests` |
| Sémantique des contraintes | `engine/tests/semantic/` ; `engine-semantics` (clés, miroir capacité, forme du contrat contre le vrai engine) ; `smoke-place-matches.sh` (samedi PLACED dans sa fenêtre, dimanche UNPLACED `no_access_window`) | `engine-tests`, `engine-semantics`, `smoke-tests` |
| Cycle de vie des plans (ADR-0002) | `PeriodPlanBirthTest`, `PeriodCopyBirthTest`, `SeasonPlanInForceTest`, `SocleDeviationParityTest`, `ScheduleConstraintBuilderOverlayTest` ; `smoke-overlay.sh` (période → plan → overlay → `COMPLETED`) ; `club-life.spec.ts` (incident borné à SON plan) | `blocking-tests`, `smoke-tests`, `e2e` |
| Périmètre engagé | `EngagedTeamGuardTest` ; `matches.spec.ts` (matchs verrouillés tant que le plan principal n'est pas validé) | `blocking-tests`, `e2e` |
| Contrat backend ⇄ engine | `ContractSchemaTest`, `ValidateAssignmentsContractSchemaTest`, `PayloadVersionMatchesContractVersionTest`, les `*PayloadParityTest` | `blocking-tests` |
| Auth & memberships | `ClubUserAccessTests`, `MemberRoleTest`, `ManagementRoleTest`, `SuperAdminAccessTest`, `ApiRateLimitTest`, `PasswordResetEnumerationTest`, `RegisterTurnstileTest`, `MercureHardeningTest` ; `auth.spec.ts` ; `smoke-coach-wishes.sh` (seul chemin d'écriture non authentifié : token public → vœu persisté) | `blocking-tests`, `e2e`, `smoke-tests` |
| Accessibilité & rendu | `a11y-contrast`, `system-scene`, `modal-reachability`, `veil-double-click`, `width-calibration`, `security-headers` (A17 contre le build nginx, `E2E_A17_REQUIRED=1`) | `e2e` |

## 3. Ce qui gate `main`

- **`blocking-tests`** (`needs: [lint, phpstan]`) : les steps NOMMÉS — liste canonique
  [`blocking-tests.md`](blocking-tests.md), gardée dans les deux sens par `BlockingTestsListMatchesCiTest`.
  ⚠ L'annotation `#[Group('phase1')]` ne gate rien : bien plus de fichiers la portent que le job n'a de
  steps (`grep -rhoE "Group\('phase1'\)" backend/tests | wc -l` vs steps du job). Un test annoté mais non
  listé tourne dans `unit-tests`, APRÈS le gate.
- **Required checks sans `needs`** : `rector`, `dependency-audit`, `secrets-scan`, `semgrep`, `smoke-tests`,
  `engine-semantics` — ce dernier se règle **côté GitHub** (`ci.yml`, commentaire du job), non vérifiable
  depuis le dépôt.
- `build-docker` needs `[blocking-tests, engine-tests]` seulement ; `unit-tests` et `e2e` needs
  `blocking-tests` ; `engine-perf` needs `engine-tests` **et ne tourne que sur `main`**
  (`if: github.ref == 'refs/heads/main'`) : palier dense + BCCL, budget 60 s. `engine-perf-pr`
  needs `engine-tests` aussi, **ne tourne que sur PR** (`if: github.event_name == 'pull_request'`)
  et seulement quand `engine/` a bougé (`git diff --name-only origin/<base>...HEAD`, base via l'ENV,
  jamais interpolée dans le shell) : palier dense SEUL, budget PR = 60 s (même valeur que `main` —
  décision fermée, `specs/courantes/etat-des-lieux.md` §2, P4-167).
- **`engine-coverage`** (P4-166 PR 1/3, 2026-09-03) needs `engine-tests`, **PAS dans les `needs` de
  `build-docker`** : le cliquet de couverture (`--cov-fail-under`, plancher lu de
  `coverage-floor.json`) est un required check à part, jamais une porte vers l'image de prod
  (décision fermée, `specs/courantes/etat-des-lieux.md` §2). Rougit seul, ne bloque ni
  `blocking-tests` ni `build-docker`.
- **`frontend-coverage`** (P4-166 PR 2/3, 2026-09-03) needs `frontend`, **PAS dans les `needs` de
  `build-docker`** — même patron qu'`engine-coverage` : `npm run test:coverage` (Vitest
  `--coverage`), cliquet `thresholds.lines` lu de `coverage-floor.json` (clé `frontend`), artefact
  `coverage-frontend` (`frontend/coverage/`, `if: always()`).
- **`backend-coverage`** (P4-166 PR 3/3, 2026-09-04 — **lot P4-166 SOLDÉ**) needs `blocking-tests`,
  **PAS dans les `needs` de `build-docker`** — même patron : `phpunit tests/ --exclude-group
  contract --coverage-clover` instrumenté par `pcov` (`-d pcov.enabled=1`), cliquet
  `backend/scripts/coverage-gate.php` (PHPUnit 11 n'a pas de seuil natif — le script lit le clover
  et compare au plancher `backend` de `coverage-floor.json`), artefact `coverage-backend`
  (`backend/coverage/`, `if: always()`).

## 4. Ce que personne ne prouve (angles morts constatés, pas devinés)

1. **Rien n'est lisible par un non-développeur** : aucun `.feature`, aucun scénario en français. Les trois
   formats fonctionnels (PHPUnit `WebTestCase`, bash, Playwright) sont des formats de développeur — le
   fondateur ne peut ni relire ni proposer un scénario. Ouvert : roadmap **P4-165** (Behat/Gherkin, à cadrer).

## `coverage-floor.json` — la couture des trois zones (P4-166)

Fichier versionné à la **racine du dépôt** (pas dans `engine/`, `backend/` ou `frontend/`) : une clé
par zone (`engine`, `frontend`, `backend` — voir le fichier pour les valeurs courantes, jamais
recopiées ici). C'est la maison UNIQUE du **cliquet de couverture** (décisions d'implémentation
fermées, `specs/courantes/etat-des-lieux.md` §2) :
- **Rôle** : le plancher en dessous duquel le job de couverture de la zone rougit
  (`--cov-fail-under` côté engine, `thresholds.lines` côté frontend ; **PHPUnit 11 n'a pas de
  seuil natif** — côté backend, le gate est `backend/scripts/coverage-gate.php`, un script sans
  dépendance qui lit le clover produit par `--coverage-clover` et compare au plancher `backend`).
  `null` = zone pas encore mesurée — `frontend/vitest.config.ts` lève une erreur explicite si sa
  clé est `null`, plutôt qu'un défaut silencieux ; les trois zones sont mesurées depuis P4-166.
- **La règle du cliquet** : le plancher ne descend **jamais** ; une PR qui améliore la mesure d'une
  zone **remonte son plancher dans la même PR** — jamais un chiffre magique choisi a priori. Le
  plancher n'est pas la mesure brute : `floor(mesure) − 1` (marge pour la variance des tests
  paramétrés/hypothesis — décision d'implémentation engine, reprise côté frontend et backend ;
  décision fermée, `specs/courantes/etat-des-lieux.md` §2).
- **Qui le lit** : `engine/Makefile` (cible `coverage`) et le job CI `engine-coverage` (clé
  `engine`) ; `frontend/vitest.config.ts` (bloc `coverage.thresholds`) et le job CI
  `frontend-coverage` (clé `frontend`) ; `backend/scripts/coverage-gate.php` et le job CI
  `backend-coverage` (clé `backend`). Jamais un chiffre en dur dans le code de mesure lui-même.
- **Gardé par** `engine/tests/test_coverage_floor.py` côté engine, `frontend/src/test/coverageFloor.test.ts`
  côté frontend et `backend/tests/Unit/CoverageFloorFileTest.php` côté backend (même contrat : le
  fichier existe, est du JSON, la clé de la zone est un entier 0-100, jamais `null`).

## 5. Behat — ce qu'il ajouterait, ce qu'il n'ajouterait pas (cadrage P4-165)

**Ajoute** : la couche **produit** au-dessus des rails existants — un scénario en français, relu ou écrit
par le fondateur AVANT le code, exécutable, qui vaut spec courante. Exemple (règle P2-60, déjà testée dans
`ReservationApiTest` mais illisible hors code) :

```gherkin
Scénario : une équipe qui ne s'entraîne qu'en groupe ne se réserve pas seule
  Étant donné le bloc "SF1 + SF2" à 2 séances communes et SF1 à 2 séances par semaine
  Quand je réserve SF1 seule le mardi 19:00 au Gymnase Matéo
  Alors la réservation est refusée : "SF1 s'entraîne uniquement en groupe SF1 + SF2 : réservez le groupe"
```

Candidats naturels : les 5 smokes bash (déjà des scénarios de bout en bout, écrits en shell).

**N'ajoute pas** : de couverture technique (mêmes rails que `WebTestCase`), de mesure de couverture (angle
mort n° 1, indépendant), de test d'écran (Mink + navigateur = doublon de Playwright, à exclure). Coûts à
cadrer : un runner CI de plus, et l'isolement (DAMA, RLS, rate-limiter Redis, JWT Bearer par requête,
`APP_ENV=test`) à porter une fois dans les contexts — les mêmes pièges que `backend/tests/Integration/`.
