# Couverture de tests — cadrage des angles morts (P4-165 → P4-166)

> **Fichier de détail ouvert** (référencé depuis [`roadmap.md`](roadmap.md)). Il porte le cadrage
> des items nés du relevé du 2026-09-03 ([`../../docs/testing/test-coverage-map.md`](../../docs/testing/test-coverage-map.md)
> §4) : besoin, constat vérifié, options, recommandation, **décisions à trancher par le fondateur avec un
> exemple concret chacune**, et ce qui est délibérément hors scope. Quand un item est livré, sa ligne
> quitte la roadmap, sa trace va dans l'état des lieux, son comportement dans `docs/testing/` — et sa
> section ici est supprimée. **P4-169 (section E, testsuites PHPUnit complètes), P4-168 (section D,
> témoin Mercure) et P4-167 (section C, perf sur PR) livrés le 2026-09-03** (traces :
> `specs/courantes/etat-des-lieux.md` §3). **Décisions B1-B5 (section B) validées par le fondateur** :
> **B2-B5 implémentées côté engine (PR 1/3) et côté frontend (PR 2/3), toutes deux le 2026-09-03** ;
> B1 (driver `pcov`) est backend-spécifique, sans équivalent engine/frontend. Reste à implémenter
> côté backend (B1-B5, PR 3/3) — voir l'encart sous la section B. Statut :
> **A (Behat) toujours en attente de validation A1-A6**.

## Ordre proposé (du moins cher au plus structurant)

| # | Item | Effort | Pourquoi cet ordre |
|---|---|---|---|
| 1 | P4-166 couverture de code | M · 3 PR (une par zone) | chaque zone a son outillage ; le cliquet vient après la première mesure |
| 2 | P4-165 Behat | L · lot phasé | dépend des décisions A1-A5 ; les smokes migrés sont le premier palier |

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

> **Engine livré (PR 1/3, 2026-09-03)** — B2/B3/B5 implémentées côté engine : plancher versionné
> `coverage-floor.json` (racine, une valeur par zone), job CI `engine-coverage` (`needs:
> engine-tests`, hors des `needs` de `build-docker`), artefact `coverage-engine` (xml + résumé).
> Comportement détaillé : `docs/testing/test-coverage-map.md` §1/§3/§4 · trace :
> `specs/courantes/etat-des-lieux.md` §3.
>
> **Frontend livré (PR 2/3, 2026-09-03)** — B2/B3/B4/B5 implémentées côté frontend : bloc
> `coverage` dans `frontend/vitest.config.ts` (provider v8, `include: src/**`, exclusions
> **déclarées** — tests, `src/test/**`, `*.stories.*`, `*.d.ts`, `*.css`, `src/main.tsx`,
> `src/vite-env.d.ts` — et non implicites), reporters `text`/`text-summary`/`json-summary`/`lcov`,
> `thresholds.lines` lu de `../coverage-floor.json` (clé `frontend`, erreur explicite si `null`,
> jamais un chiffre en dur). Script `test:coverage` (`vitest run --coverage`), cible
> `make -C frontend coverage` (`.PHONY`, image tooling rebâtie via `install`), job CI
> `frontend-coverage` (`needs: frontend`, hors des `needs` de `build-docker`), artefact
> `coverage-frontend`. Garde `frontend/src/test/coverageFloor.test.ts` (fichier existe, JSON, clé
> `frontend` entière 0-100). Comportement détaillé : `docs/testing/test-coverage-map.md`
> §1/§3/§4 · trace : `specs/courantes/etat-des-lieux.md` §3. **Décisions d'implémentation
> consignées ici** (utiles à la PR 3/3, backend) :
> - **Plancher = `floor(mesure) − 1`, pas la mesure brute.** Même garde-fou qu'engine :
>   mesure frontend ~80 % le 2026-09-03 → plancher 79 (marge pour la variance d'une suite
>   instrumentée). Le nombre exact vit dans `coverage-floor.json`, jamais recopié ici.
> - **`.css` exclu explicitement (B4)** : une feuille de style n'a aucune ligne exécutable,
>   v8 la compterait à 0 % et ferait baisser artificiellement la mesure globale.
> - **Piège d'implémentation — `__dirname`, pas `import.meta.url`, dans le test qui lit
>   `coverage-floor.json`** : sous `vitest run --coverage`, `import.meta.url` n'est pas
>   toujours de schéma `file:` — `new URL(..., import.meta.url)` lève
>   `ERR_INVALID_URL_SCHEME`. `coverageFloor.test.ts` ancre sur `__dirname` (chemin nu,
>   injecté par Vitest) ; `vitest.config.ts` lui-même reste sur `import.meta.url` (chargé hors
>   `--coverage`, jamais impacté).
> - **Job séparé, jamais dans les `needs` de `build-docker`** (B5), même patron qu'`engine-coverage`.
>
> Ces deux livraisons partagent le patron : B1 (driver `pcov`) est backend-spécifique, sans
> équivalent engine/frontend.

**Besoin.** Savoir quelles lignes ne sont JAMAIS exécutées par un test — la carte dit ce qui est testé, pas
ce qui ne l'est pas. Sans mesure, un audit de couverture est un jugement, pas un relevé.

**Constat vérifié — ce qui reste ouvert (backend).** `backend/phpunit.xml.dist:18-38` sans
`<coverage>`, `php -m` dans `php-fpm` sans pcov ni xdebug. (Engine et frontend : livrés, voir les
encarts ci-dessus.)

**Options.** (a) Mesurer sans gater (artefact + résumé dans le log). (b) Mesurer + seuil fixe (« 80 % »).
(c) Mesurer + **cliquet** par zone : le plancher versionné ne descend jamais, monte quand une PR l'améliore.

**Reco.** (a) d'abord (une PR par zone), puis (c) une fois trois mesures stables. Jamais (b) : un chiffre
magique fait écrire des tests pour le chiffre. (Retenu pour engine et frontend : (a) et (c) dans la même
PR, le premier plancher étant posé à la première mesure — pas d'étape (a) seule intermédiaire.)

**Décisions tranchées, implémentation restante backend (B1-B5 déjà mises en œuvre côté engine et
frontend).**
- **B1 — driver backend : `pcov`, dans l'image dev/test seulement** (stage Docker dédié ou `ARG`), jamais
  dans l'image prod. Exemple : `docker/php/Dockerfile` gagne `pecl install pcov` derrière un `ARG WITH_PCOV=0`
  que `docker-compose.yml` met à 1 ; l'image prod publiée ne change pas d'un octet (Trivy identique).
- **B2 — restitution : artefact CI par zone + résumé texte dans le log du job**, pas de service tiers.
  Exemple : `coverage-backend.txt` téléchargeable depuis la PR ; Codecov/Coveralls refusés (la couverture
  est une donnée du dépôt, pas d'un SaaS ; et un badge n'est pas une preuve). Fait pour engine :
  artefact `coverage-engine` (xml + résumé texte) ; pour frontend : artefact `coverage-frontend`
  (`frontend/coverage/`, reporter `lcov` + `text-summary` dans le log).
- **B3 — cliquet : un fichier `coverage-floor.json` versionné (une valeur par zone), gardé en CI**
  (`--cov-fail-under` engine, `<coverage>` + seuil PHPUnit, `thresholds` vitest) ; une PR qui améliore la
  mesure remonte le plancher dans la même PR. Exemple : engine mesuré à 92,75 % → plancher 91 (`floor(mesure)
  − 1`, marge pour la variance hypothesis/paramétrés — décision d'implémentation, pas au cadrage initial) ;
  une PR qui tombe sous 91 rougit ; une PR qui monte à 95 écrit 94. Frontend suit le même patron
  (`thresholds.lines`).
- **B4 — exclusions déclarées, pas implicites** : migrations, `src/Seed/` (données), `DataFixtures`,
  `tests/`, fichiers générés. Exemple : `BcclSeeder.php` (~3 000 lignes de données) exclu, sinon il pèse
  plus que tout le domaine. Fait pour engine : `[tool.coverage.run].omit = []` déclaré vide en commentaire
  (rien à exclure dans `app/` aujourd'hui, la liste reste le point d'ajout visible en diff). Fait pour
  frontend : `vitest.config.ts` `test.coverage.exclude` (tests, `src/test/**`, stories, `.d.ts`, `.css`,
  `main.tsx`, `vite-env.d.ts`).
- **B5 — la couverture ne gate PAS `build-docker`** : job séparé, required check à part, pour que
  « on a perdu 1 % » ne bloque jamais une image de prod urgente sans décision humaine. Fait pour engine :
  `engine-coverage` hors des `needs` de `build-docker` ; pour frontend : `frontend-coverage` idem.

**Hors scope.** Couverture des e2e Playwright (instrumentation du bundle — coût disproportionné) ; couverture
de branches avant la couverture de lignes ; mutation testing (Infection, mutmut) — idée à garder, pas ici.

