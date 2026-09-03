# Couverture de tests — cadrage des angles morts (P4-165 → P4-167)

> **Fichier de détail ouvert** (référencé depuis [`roadmap.md`](roadmap.md)). Il porte le cadrage
> des items nés du relevé du 2026-09-03 ([`../../docs/testing/test-coverage-map.md`](../../docs/testing/test-coverage-map.md)
> §4) : besoin, constat vérifié, options, recommandation, **décisions à trancher par le fondateur avec un
> exemple concret chacune**, et ce qui est délibérément hors scope. Quand un item est livré, sa ligne
> quitte la roadmap, sa trace va dans l'état des lieux, son comportement dans `docs/testing/` — et sa
> section ici est supprimée. **P4-169 (section E, testsuites PHPUnit complètes) et P4-168 (section D,
> témoin Mercure) livrés le 2026-09-03** (traces : `specs/courantes/etat-des-lieux.md` §3). Statut :
> **cadrage proposé le 2026-09-03, en attente de validation pour A-C**.

## Ordre proposé (du moins cher au plus structurant)

| # | Item | Effort | Pourquoi cet ordre |
|---|---|---|---|
| 1 | P4-167 perf sur PR | S · 1 PR | `ci.yml` seul, à condition de mesurer la variance d'abord |
| 2 | P4-166 couverture de code | M · 3 PR (une par zone) | chaque zone a son outillage ; le cliquet vient après la première mesure |
| 3 | P4-165 Behat | L · lot phasé | dépend des décisions A1-A5 ; les smokes migrés sont le premier palier |

## A — P4-165 · Tests fonctionnels en Behat (Gherkin)

**Besoin.** Le fondateur veut relire — et à terme proposer — des scénarios métier en français, exécutables,
AVANT le code. Aujourd'hui les trois formats fonctionnels (PHPUnit `WebTestCase`, bash, Playwright) sont des
formats de développeur : un scénario comme « SF1 ne se réserve pas seule quand son résidu solo est 0 » existe
(`backend/tests/Integration/Api/ReservationApiTest.php`) mais n'est lisible que dans du PHP.

**Constat vérifié.** Behat absent (`grep -ri behat backend/composer.json` → 0) ; `symfony/browser-kit` déjà
présent (`backend/composer.json:112`) ; les 5 smokes pèsent ~700 lignes de shell (`wc -l backend/scripts/*smoke*.sh`)
et sont déjà des scénarios de bout en bout, chacun autosuffisant.

**Options.** (a) Behat + `FriendsOfBehat/SymfonyExtension`, contexts sur BrowserKit — API seule, pas de
navigateur. (b) Behat + Mink + Playwright/Chrome — scénarios d'écran. (c) Pas de Behat : tables de
scénarios en français dans `specs/courantes/` + tests PHPUnit nommés pareil (lisible mais pas exécutable).

**Reco.** (a). (b) doublonne Playwright et coûte un second navigateur en CI ; (c) est le motif « une vérité,
deux endroits » (`duplications-de-verite.md`) — le scénario lisible et le test dériveraient.

**Décisions à trancher.**
- **A1 — périmètre : API seule.** Exemple : la feature « réserver SF1 seule → refusée » fait un
  `POST /api/reservations` via BrowserKit et lit le 422 ; elle ne clique aucun bouton. Ce que l'écran
  affiche du 422 reste à Vitest/Playwright.
- **A2 — les 5 smokes MIGRENT (puis les `.sh` sont retirés), ils ne sont pas doublés.** Exemple :
  `smoke-place-matches.sh` devient `placement-des-matchs.feature` (« Étant donné une fenêtre d'accès le
  samedi 13:00-22:30 au Gymnase Matéo … Alors le match du dimanche est non placé pour “aucune fenêtre
  d'accès” ») ; le job `smoke-tests` devient le job `functional-tests` ; le `.sh` est supprimé dans la
  même PR que sa feature, une fois la parité prouvée (même assertion, même verdict). Alternative : garder
  les deux — refusée par défaut (deux vérités).
- **A3 — gate : job `functional-tests` required check de `main`, sans `needs`** (comme `smoke-tests`
  aujourd'hui : verdict tôt, indépendant des suites unitaires). Exemple : une PR qui casse le placement
  des matchs rougit en ~5 min, avant que `blocking-tests` ait fini.
- **A4 — langue : Gherkin en français (`# language: fr`, Étant donné / Quand / Alors)** ; les steps
  sont écrits une fois en PHP, réutilisés par toutes les features. Exemple : « Étant donné le club dev
  seedé » = un step qui appelle `app:bccl:seed --if-absent` sous `APP_ENV=test`.
- **A5 — qui écrit : l'IA rédige les premières features (migration des smokes), le fondateur relit et
  amende ; un scénario proposé par le fondateur en français est un besoin recevable tel quel.** Exemple :
  le fondateur écrit « Quand je valide le socle, alors les plans de période FUTURS sont détruits » — la
  feature existe avant le test, le step manquant est le travail.
- **A6 — isolement : mêmes rails que `WebTestCase`** (DAMA par scénario, RLS, `APP_ENV=test`, JWT Bearer
  minté par step, rate-limiter Redis purgé par scénario) — portés UNE fois dans un `BaseContext`. Exemple :
  deux scénarios qui créent chacun « SF1 » ne se voient pas.

**Hors scope.** Mink/écrans (A1) ; réécrire les tests PHPUnit existants en Gherkin (doublon — la valeur est
le scénario que le fondateur relit, pas la traduction) ; un runner Behat côté engine (pytest reste).

## B — P4-166 · Mesure de couverture de code (trois zones)

**Besoin.** Savoir quelles lignes ne sont JAMAIS exécutées par un test — la carte dit ce qui est testé, pas
ce qui ne l'est pas. Sans mesure, un audit de couverture est un jugement, pas un relevé.

**Constat vérifié.** Backend : `backend/phpunit.xml.dist:18-38` sans `<coverage>`, `php -m` dans `php-fpm`
sans pcov ni xdebug. Engine : `engine/Makefile:30` `pytest --cov=app` en local, `engine/pyproject.toml:23`
`pytest-cov`, step CI « Run pytest » nu, aucun `--cov-fail-under`. Frontend : `frontend/package.json:54`
`@vitest/coverage-v8` installé, aucun bloc `test.coverage`, aucun script.

**Options.** (a) Mesurer sans gater (artefact + résumé dans le log). (b) Mesurer + seuil fixe (« 80 % »).
(c) Mesurer + **cliquet** par zone : le plancher versionné ne descend jamais, monte quand une PR l'améliore.

**Reco.** (a) d'abord (une PR par zone), puis (c) une fois trois mesures stables. Jamais (b) : un chiffre
magique fait écrire des tests pour le chiffre.

**Décisions à trancher.**
- **B1 — driver backend : `pcov`, dans l'image dev/test seulement** (stage Docker dédié ou `ARG`), jamais
  dans l'image prod. Exemple : `docker/php/Dockerfile` gagne `pecl install pcov` derrière un `ARG WITH_PCOV=0`
  que `docker-compose.yml` met à 1 ; l'image prod publiée ne change pas d'un octet (Trivy identique).
- **B2 — restitution : artefact CI par zone + résumé texte dans le log du job**, pas de service tiers.
  Exemple : `coverage-backend.txt` téléchargeable depuis la PR ; Codecov/Coveralls refusés (la couverture
  est une donnée du dépôt, pas d'un SaaS ; et un badge n'est pas une preuve).
- **B3 — cliquet : un fichier `coverage-floor.json` versionné (une valeur par zone), gardé en CI**
  (`--cov-fail-under` engine, `<coverage>` + seuil PHPUnit, `thresholds` vitest) ; une PR qui améliore la
  mesure remonte le plancher dans la même PR. Exemple : engine mesuré à 74 % → plancher 73 ; une PR qui
  tombe à 72 rougit ; une PR qui monte à 78 écrit 77.
- **B4 — exclusions déclarées, pas implicites** : migrations, `src/Seed/` (données), `DataFixtures`,
  `tests/`, fichiers générés. Exemple : `BcclSeeder.php` (~3 000 lignes de données) exclu, sinon il pèse
  plus que tout le domaine.
- **B5 — la couverture ne gate PAS `build-docker`** : job séparé, required check à part, pour que
  « on a perdu 1 % » ne bloque jamais une image de prod urgente sans décision humaine.

**Hors scope.** Couverture des e2e Playwright (instrumentation du bundle — coût disproportionné) ; couverture
de branches avant la couverture de lignes ; mutation testing (Infection, mutmut) — idée à garder, pas ici.

## C — P4-167 · La perf du solveur sur les PR

**Besoin.** Une régression de perf (retour au prove-stall mono-worker, 612 s mesurés) doit rougir la PR qui
l'introduit, pas `main` après merge.

**Constat vérifié.** `.github/workflows/ci.yml:697` `if: github.ref == 'refs/heads/main'`, needs
`engine-tests`, `timeout-minutes: 30` ; `engine/tests/perf/test_perf_gate.py:30` `LARGE_CLUB_BUDGET_SECONDS = 60.0`,
palier dense (37 équipes · 8 gymnases) et BCCL ; marqueur `perf` exclu par défaut (`addopts`).

**Options.** (a) Le job complet sur toutes les PR (+30 min de runner par PR). (b) Un palier réduit sur PR
(dense seul, ~1-2 min), plein sur `main`. (c) Sur PR seulement quand `engine/` change (filtre de chemins).
(d) Déclenchement par label `perf`.

**Reco.** (b) + (c) combinés : palier réduit, seulement si `engine/` a bougé. (d) en repli manuel.

**Décisions à trancher.**
- **C1 — mesurer la variance AVANT de gater** : 5 runs du palier dense sur runners GitHub, écart-type
  noté dans la PR. Exemple : si dense oscille entre 25 et 55 s pour un budget de 60, le gate est un flake
  annoncé — on relève le budget PR (90 s) ou on ne gate pas, on ne « réessaie » jamais en CI.
- **C2 — palier PR = dense seul, budget PR ≥ 1,5 × la médiane mesurée** ; BCCL reste sur `main`.
  Exemple : médiane 30 s → budget PR 60 s ; `main` garde 60 s sur les deux paliers.
- **C3 — filtre de chemins : `engine/**` et `docker/engine/**`** ; une PR backend-only ne paie pas le job.
  Exemple : la PR #831 (backend) n'aurait pas lancé le palier ; la PR #832 (Dockerfile engine via Makefile
  engine) l'aurait lancé.

**Hors scope.** Benchmarks historisés (tendance dans le temps) — vision ; perf du backend (PHP) et du
frontend (Lighthouse) — autres sujets.

