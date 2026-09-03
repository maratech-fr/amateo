# Couverture de tests — cadrage des angles morts (P4-165)

> **Fichier de détail ouvert** (référencé depuis [`roadmap.md`](roadmap.md)). Il porte le cadrage
> des items nés du relevé du 2026-09-03 ([`../../docs/testing/test-coverage-map.md`](../../docs/testing/test-coverage-map.md)
> §4) : besoin, constat vérifié, options, recommandation, **décisions à trancher par le fondateur avec un
> exemple concret chacune**, et ce qui est délibérément hors scope. Quand un item est livré, sa ligne
> quitte la roadmap, sa trace va dans l'état des lieux, son comportement dans `docs/testing/` — et sa
> section ici est supprimée. **P4-169 (section E, testsuites PHPUnit complètes), P4-168 (section D,
> témoin Mercure), P4-167 (section C, perf sur PR) et P4-166 (section B, mesure de couverture — les
> trois zones, PR 1/3 engine + PR 2/3 frontend + PR 3/3 backend) SOLDÉS**, leurs sections
> supprimées de ce fichier (traces : `specs/courantes/etat-des-lieux.md` §3, décisions
> d'implémentation B1-B5 : §2). Reste : **A (Behat) en attente de validation A1-A6**.

## Ordre proposé

Le seul item restant est P4-165 (Behat) — pas d'ordre à trancher tant qu'il est seul.

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

