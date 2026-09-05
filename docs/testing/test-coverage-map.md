# Carte de la couverture de tests — qui teste quoi, ce qui gate, ce qui manque

Last verified @ 2026-09-05 (P4-177, `documentation-update`). §2 (accessibilité & rendu) recalé :
`StatusPill` gagne la variante `accent` — paires de contraste `text-foreground on bg-accent/10`
(AA) et `text-accent icon on bg-accent/10` (1.4.11) ajoutées dans `tests/e2e/a11y-contrast.spec.ts`
(2 thèmes), aux côtés des paires `warning` posées par P4-173 (`text-foreground on bg-warning/10`,
`text-warning icon on bg-warning/10`). Vitest `badge.test.tsx` (nouveau) garde le contrat des
variantes/passthrough côté composant. Un stamp REMPLACE, l'historique vit dans git :
`git log -p --follow docs/testing/test-coverage-map.md`.

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
| Behat | stack complète | scénarios métier en français (Gherkin), une promesse par feature, lisibles et relisables par le fondateur, joués contre l'API réelle (aucun navigateur, aucun noyau in-process) — §5 : les 5 premières remplacent intégralement les smokes bash (`backend/scripts/*smoke*.sh`, SUPPRIMÉS — P4-165), les suivantes couvrent les règles qui détruisent/refusent/isolent (P4-175) | `backend/features/`, contexts `backend/tests/Behat/` | `make -C backend behat` (sous `with-sandbox.sh` en mode play) | `functional-tests` |
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
| Isolation tenant | `TenantIsolationTest`, `RlsIsolationTest`, `TenantCacheIsolationTest`, `MatchTenantIsolationTest` ; feature Behat `un-club-ne-voit-jamais-un-autre-club.feature` (un autre club ne liste/lit/supprime rien — 404, jamais 403 ; un membre sans rôle de gestion ne modifie rien) | `blocking-tests`, `functional-tests` |
| Pipeline de génération | `ConcurrentGenerationTest` (verrou) ; **`journey.spec.ts`** (wizard → génération CP-SAT réelle → planning validé → cockpit — **et livrée PAR le canal Mercure/SSE**, pas seulement par le repli polling : témoin `ScheduleStreamWitness`, `data-schedule-stream-events` ≥ 1 exigé, P4-168) ; feature Behat `generation-du-planning-de-saison.feature` (rail async → `COMPLETED`) et `inscription-et-premier-planning.feature` (club neuf → minimum → `COMPLETED`) | `blocking-tests`, `e2e`, `functional-tests` |
| Sémantique des contraintes | `engine/tests/semantic/` ; `engine-semantics` (clés, miroir capacité, forme du contrat contre le vrai engine) ; feature Behat `placement-des-matchs.feature` (samedi PLACED dans sa fenêtre, dimanche UNPLACED `no_access_window`) ; feature Behat `une-contrainte-saisie-est-honoree.feature` (contrainte honorée, contrainte impossible → échec diagnostiqué) ; feature Behat `un-verrou-est-souverain.feature` (verrou HARD sovereign à la régénération, déplacement impossible refusé et nommé, règle contraire signalée sans déplacer le verrou — P4-176) ; `Integration/HardLockSurvivesPayloadTest` (bloquant — le payload de génération transporte le verrou HARD ET la règle de jours qui le contredit sans en dégrader ni en retirer aucun) ; feature Behat `l-unite-de-placement-est-le-bloc.feature` (l'unité de placement d'un entraînement mutualisé est le groupe) ; `ReservationApiTest::testDeletingABlockCompleteReservationEmptiesTheWholeCase`/`testDeletingAnIndividualReservationOnANonCompleteCaseRemovesOnlyIt` (P2-62 — supprimer une réservation d'une case bloc-complète emporte toute la case + les verrous HARD, une individuelle se supprime seule) | `engine-tests`, `engine-semantics`, `functional-tests`, `unit-tests` |
| Cycle de vie des plans (ADR-0002) | `PeriodPlanBirthTest`, `PeriodCopyBirthTest`, `SeasonPlanInForceTest`, `SocleDeviationParityTest`, `ScheduleConstraintBuilderOverlayTest` ; feature Behat `le-socle-commande-les-plans.feature` (valider/rouvrir le socle efface les plans de période à venir, garde ceux déjà commencés ; aucune période sans socle en vigueur) ; feature Behat `le-planning-se-dit-a-regenerer.feature` (une contrainte ajoutée marque le planning en vigueur à régénérer, sans en effacer un créneau ; **P4-173** — le plan sert lui-même sa péremption au cockpit, scénario « Le cockpit le sait ») ; `Integration\Api\SchedulePlanStalenessServedTest` (P4-173 — version marquée → bloc, V1 marquée/V2 propre pointée → tout faux, sans pointeur → `null`, fenêtre révolue → `null`, servi sur la collection) ; feature Behat `la-semaine-de-reprise.feature` (détacher une semaine de vacances fait naître son plan, génération sur sa grille propre) ; feature Behat `une-semaine-de-vacances-couvre-lundi-vendredi.feature` (une semaine partielle ne devient jamais une semaine de reprise) ; feature Behat `une-indisponibilite-se-decoupe-en-debut-milieu-fin.feature` (découpage début·milieu·fin d'une fermeture) ; feature Behat `plan-de-periode-en-overlay.feature` (période → plan → overlay → `COMPLETED` ; remplissage recolle un membre de bloc libéré ; **D3 v1 : re-dater l'incident, le plan survit et sa version est marquée à régénérer**) ; `club-life.spec.ts` (incident borné à SON plan) ; `WeekChildEntryTest` (une semaine-enfant d'une mère VACANCES ne naît que si elle couvre tout son lundi→vendredi, 422 nommé sinon — D4, `App\Service\HolidayWorkweekRule`) et `HolidayWorkweekMirrorParityTest` (parité mécanique backend ⇄ front `holidayCoversWorkweek`, `holidayWorkweek.parity.json`, groupe `contract`) ; `Security/PeriodRedateTest` (8 cas — D3 v1, ex-P2-57 : re-datage d'une racine CLOSURE dans les deux sens, fenêtre gelée pour tous les autres cas, 409 sur fenêtre déjà prise) ; **`redate-closure.spec.ts`** (D3 v1 PR-2 — chemin UI réel : cockpit → « Modifier les dates » → `PUT`, le toast de succès annonce le re-datage, `/planning` affiche la bannière « périmé » ; idempotent — re-date puis restaure la fenêtre d'origine, aucune course avec `club-life.spec.ts` qui en dépend) ; `Security/PeriodWindowRaceTest` (P4-172 — deux créations de plan de période concurrentes sur deux entrées du même club ne se chevauchent plus : grain club+saison du verrou `lockClubWindows` prouvé par une seconde connexion DBAL tenant la clé consultative, annoté `phase1`+`integration` mais **non listé dans `blocking-tests`**, tourne dans `unit-tests` seul) ; **découpage début·milieu·fin d'une fermeture (décision fondateur, 2026-09-05)** : `CrossStack/WeekSegmentationMirrorParityTest` (groupe `contract` — parité backend `WeekSegmentationRule::segments` ⇄ front `weekSegments` sur `weekSegmentation.parity.json`) ; `Security/WeekChildEntryTest` (étendu — une semaine-enfant de fermeture doit être EXACTEMENT un segment calculé, 422 nommé sinon, tolérance des semaines révolues en tête) ; `Security/PeriodPlanBirthTest` (« Adapter d'un bloc » une fermeture refuse en 422 dès que sa fenêtre compte >1 segment) ; `Security/PeriodRedateTest` (le re-datage D3 refuse en 422 une nouvelle fenêtre à plus d'un segment) ; `Integration/Seed/BcclSeederIdempotenceTest` (aucune racine seedée ne porte plus un plan à >1 segment — l'incident Matéo est désormais deux enfants CLOSURE, milieu + fin) ; **`club-life.spec.ts`** et **`redate-closure.spec.ts`** recalés sur la nouvelle forme (e2e) | `blocking-tests`, `functional-tests`, `e2e`, `unit-tests` |
| Périmètre engagé | `EngagedTeamGuardTest` ; `matches.spec.ts` (matchs verrouillés tant que le plan principal n'est pas validé) ; feature Behat `le-perimetre-engage-est-protege.feature` (équipe engagée ni supprimable ni changeable de niveau, une équipe qui ne joue pas reste libre) | `blocking-tests`, `e2e`, `functional-tests` |
| Contrat backend ⇄ engine | `ContractSchemaTest`, `ValidateAssignmentsContractSchemaTest`, `PayloadVersionMatchesContractVersionTest`, les `*PayloadParityTest` — **aucune feature Behat dédiée** : la forme d'un payload/schéma n'est pas une promesse qu'un gestionnaire relit, le PHPUnit cross-stack reste la preuve directe | `blocking-tests` |
| Auth & memberships | `ClubUserAccessTests`, `MemberRoleTest`, `ManagementRoleTest`, `SuperAdminAccessTest`, `ApiRateLimitTest`, `PasswordResetEnumerationTest`, `RegisterTurnstileTest`, `MercureHardeningTest` ; `auth.spec.ts` ; feature Behat `voeux-des-coachs.feature` (seul chemin d'écriture non authentifié : token public → vœu persisté) ; feature Behat `l-export-du-planning.feature` (l'export du planning est refusé sans session) | `blocking-tests`, `e2e`, `functional-tests` |
| Accessibilité & rendu | `a11y-contrast` (2 thèmes — paires `warning` P4-173 et `accent` P4-177 verrouillées : texte `text-foreground` sur fond teinté ≥ AA, icône de tonalité ≥ 1.4.11, `StatusPill`), `system-scene`, `modal-reachability`, `veil-double-click`, `width-calibration`, `security-headers` (A17 contre le build nginx, `E2E_A17_REQUIRED=1`) | `e2e` |

## 3. Ce qui gate `main`

- **`blocking-tests`** (`needs: [lint, phpstan]`) : les steps NOMMÉS — liste canonique
  [`blocking-tests.md`](blocking-tests.md), gardée dans les deux sens par `BlockingTestsListMatchesCiTest`.
  ⚠ L'annotation `#[Group('phase1')]` ne gate rien : bien plus de fichiers la portent que le job n'a de
  steps (`grep -rhoE "Group\('phase1'\)" backend/tests | wc -l` vs steps du job). Un test annoté mais non
  listé tourne dans `unit-tests`, APRÈS le gate.
- **Required checks sans `needs`** : `rector`, `dependency-audit`, `secrets-scan`, `semgrep`,
  `engine-semantics`, `functional-tests` (Behat, P4-165 SOLDÉ) — ces deux derniers se règlent **côté
  GitHub** (`ci.yml`, commentaire du job), non vérifiable depuis le dépôt.
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

Le programme Behat du 2026-09-05 (P4-175, 11 features, complété le même jour par P4-176) a fermé
le dernier angle mort recensé (les parcours fonctionnels illisibles hors code — les rails et
règles qui détruisent/refusent/isolent ont désormais chacun une feature Gherkin relisable par le
fondateur, §5). L'exception qui restait ouverte (un verrou HARD contredit par une règle HARD
`forbiddenDays`) a été instruite et close (§2 `etat-des-lieux.md`) : reproduite en API pure sur le
rail réel, le comportement est conforme à l'invariant `CLAUDE.md` §6 (le verrou reste, la règle est
diagnostiquée) — la relocalisation constatée au cadrage venait d'un cache de payload périmé, pas du
produit. `un-verrou-est-souverain.feature` porte désormais ce scénario. Cette section reste la
maison des prochains angles morts trouvés.

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

## 5. Behat — ce qui est en place (P4-165 et P4-175 SOLDÉS)

> **Programme du 2026-09-05 livré entier** (jugement fondateur, « couverture suffisante pour ne
> pas avoir d'angle mort ») : les 11 promesses sensibles — celles qui détruisent, refusent ou
> isolent — ont chacune leur feature. **Nouvelle promesse = nouvelle feature, une par PR** ; c'est
> la règle d'entretien qui remplace le fichier de cadrage (`specs/evolution/behat-programme.md`,
> supprimé — historique en git).

`behat/behat` ^3 (v3.32) en require-dev, `backend/behat.dist.php` (Gherkin `# language: fr`, une
suite par feature, chacune reliée à son propre context — aucune collision de définition de step
possible entre features), les `.feature` de `backend/features/`, contexts dans
`backend/tests/Behat/` (`BaseContext` — socle commun : client HTTP, garde bac-à-sable jumelle de
`sandbox-guard.sh`, jeton via `bin/console lexik:jwt:generate-token` ; un context dédié par
feature). Chaque feature est jouable seule et dans n'importe quel ordre. Les 5 premières
**remplacent intégralement** le smoke bash qu'elles migrent (tous SUPPRIMÉS de
`backend/scripts/`, parité prouvée assertion par assertion) ; les 11 suivantes couvrent des
promesses qui n'avaient jusqu'ici qu'une preuve technique (PHPUnit/pytest, illisible hors code) :

**Rail de génération**

| Feature | Ce qu'elle prouve |
|---|---|
| `generation-du-planning-de-saison.feature` | le rail async de génération aboutit à `COMPLETED` (remplace `smoke-solver.sh`) |

**Inscription**

| Feature | Ce qu'elle prouve |
|---|---|
| `inscription-et-premier-planning.feature` | un club neuf inscrit + minimum saisi obtient son planning `COMPLETED` (remplace `onboarding-smoke.sh`) |

**Matchs**

| Feature | Ce qu'elle prouve |
|---|---|
| `placement-des-matchs.feature` | un match à domicile dans sa fenêtre d'accès est `PLACED`, un sans fenêtre reste `UNPLACED` avec la raison nommée `no_access_window` (remplace `smoke-place-matches.sh`) |

**Période (overlay, reprise, découpage, vacances)**

| Feature | Ce qu'elle prouve |
|---|---|
| `plan-de-periode-en-overlay.feature` | une période génère son plan en overlay sur sa propre grille, et le remplissage recolle un membre de bloc libéré sur la case de son partenaire épinglé (remplace `smoke-overlay.sh`) |
| `la-semaine-de-reprise.feature` | détacher une semaine de vacances fait naître son plan, et sa génération aboutit sur la grille PROPRE de la semaine — jamais l'union avec le planning de saison |
| `une-indisponibilite-se-decoupe-en-debut-milieu-fin.feature` | une fermeture à semaine entamée se découpe en début/milieu/fin (jamais une semaine complète isolée, jamais « d'un bloc ») ; les trois segments sont acceptés ensemble |
| `une-semaine-de-vacances-couvre-lundi-vendredi.feature` | une semaine n'est de vacances que si tout son lundi→vendredi tombe dans les vacances — une semaine partielle se planifie comme une semaine de saison |

**Règles métier (socle, contrainte, bloc, verrou, périmètre, à régénérer)**

| Feature | Ce qu'elle prouve |
|---|---|
| `le-socle-commande-les-plans.feature` | valider ou rouvrir le planning de saison efface les plans de période ENTIÈREMENT à venir, jamais un déjà commencé ; aucune génération de période sans socle en vigueur |
| `une-contrainte-saisie-est-honoree.feature` | une contrainte saisie est honorée par le solveur (aucune séance hors fenêtre) ; une contrainte impossible fait échouer la génération avec un diagnostic nommé, jamais un plan bricolé |
| `l-unite-de-placement-est-le-bloc.feature` | une équipe qui ne s'entraîne qu'en groupe ne se réserve pas seule ; réserver le groupe pose la séance pour tout le monde ; retirer une séance du lot emporte le groupe entier |
| `un-verrou-est-souverain.feature` | une séance verrouillée en dur reste à la même case après régénération ; un déplacement impossible (case sans créneau ouvert) est refusé et nommé, rien n'est écrit ; une règle qui contredit un verrou ne le déplace pas, le créneau reste et la règle violée est signalée (P4-176) |
| `le-perimetre-engage-est-protege.feature` | une équipe engagée en compétition (elle a des matchs) n'est ni supprimable ni changeable de niveau ; une équipe qui ne joue pas reste libre |
| `le-planning-se-dit-a-regenerer.feature` | ajouter une contrainte marque le planning en vigueur à régénérer, sans en effacer un seul créneau |

**Sécurité & accès (isolation, export, vœux)**

| Feature | Ce qu'elle prouve |
|---|---|
| `un-club-ne-voit-jamais-un-autre-club.feature` | un autre club ne liste, ne lit ni ne supprime une équipe qui n'est pas la sienne (404, jamais 403) ; un membre sans rôle de gestion ne modifie rien |
| `l-export-du-planning.feature` | le planning en vigueur s'exporte en PDF non vide ; l'export est refusé sans session |
| `voeux-des-coachs.feature` | une campagne envoie un lien, l'entraîneur répond sans compte, le vœu remonte côté gestionnaire (remplace `smoke-coach-wishes.sh`) |

**Choix de conception (décision fermée, `specs/courantes/etat-des-lieux.md` §2)** : les features
parlent **HTTP à la stack qui tourne** (client `HttpClient::create` vers `http://nginx/api`, vrai
`messenger-worker`, vrai engine) — **ni kernel in-process, ni `BrowserKit`, ni DAMA, ni
`friends-of-behat/*`** : c'est une déviation assumée de l'option (a) envisagée au cadrage
(BrowserKit), retenue parce qu'elle prouve le rail ASYNC réel de bout en bout, ce qu'un transport
in-process ou une transaction DAMA ne prouveraient plus.

**Écrire un nouveau scénario** : (1) une feature Gherkin française dans `backend/features/`
(`# language: fr`, `Étant donné`/`Quand`/`Alors`) ; (2) ses steps dans un context dédié sous
`backend/tests/Behat/` (`#[Given]`/`#[When]`/`#[Then]`, étendant `BaseContext`), déclaré dans
`backend/behat.dist.php` avec sa propre suite ; (3) `make -C backend behat` pour la jouer. Exemple
de scénario candidat (règle P2-60, déjà testée dans `ReservationApiTest` mais illisible hors code) :

```gherkin
Scénario : une équipe qui ne s'entraîne qu'en groupe ne se réserve pas seule
  Étant donné le bloc "SF1 + SF2" à 2 séances communes et SF1 à 2 séances par semaine
  Quand je réserve SF1 seule le mardi 19:00 au Gymnase Matéo
  Alors la réservation est refusée : "SF1 s'entraîne uniquement en groupe SF1 + SF2 : réservez le groupe"
```

**N'ajoute pas** : de couverture technique (mêmes rails que `WebTestCase`), de mesure de couverture (angle
mort n° 1 §4, indépendant), de test d'écran (Mink + navigateur = doublon de Playwright, exclu).
