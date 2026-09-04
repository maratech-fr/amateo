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
> d'implémentation B1-B5 : §2). Reste : **A (Behat) — palier 1/5 livré, 4 paliers restants**.

## Ordre proposé

Le seul item restant est P4-165 (Behat) — pas d'ordre à trancher tant qu'il est seul.

## A — P4-165 · Tests fonctionnels en Behat (Gherkin)

**Besoin.** Le fondateur veut relire — et à terme proposer — des scénarios métier en français, exécutables,
AVANT le code. Aujourd'hui les trois formats fonctionnels (PHPUnit `WebTestCase`, bash, Playwright) sont des
formats de développeur : un scénario comme « SF1 ne se réserve pas seule quand son résidu solo est 0 » existe
(`backend/tests/Integration/Api/ReservationApiTest.php`) mais n'est lisible que dans du PHP.

**Palier 1/5 LIVRÉ (2026-09-04)** : squelette + première feature migrée (génération de saison). Détail du
livré : `specs/courantes/etat-des-lieux.md` §3 (trace). **Restent 4 paliers** : `onboarding-smoke.sh`,
`smoke-place-matches.sh`, `smoke-overlay.sh`, `smoke-coach-wishes.sh`.

**Décisions A1-A6 — tranchées à l'implémentation du palier 1, valables pour les paliers 2-5.**
- **A1 — périmètre : API seule, MAIS pas via `BrowserKit`/kernel in-process comme envisagé au cadrage
  (option a).** Décision fermée (déviation assumée, `specs/courantes/etat-des-lieux.md` §2) : les contexts
  parlent **HTTP à la stack qui tourne** (`http://nginx/api`, vrai `messenger-worker`, vrai engine) — ni
  `FriendsOfBehat/SymfonyExtension`, ni `BrowserKit`, ni transaction DAMA. Ce que l'écran affiche reste à
  Vitest/Playwright.
- **A2 — les 5 smokes MIGRENT (puis les `.sh` sont retirés), ils ne sont pas doublés — confirmé.**
  `smoke-solver.sh` est retiré (palier 1), `placement-des-matchs.feature`/`overlay`/`coach-wishes`/
  `onboarding` suivront le même patron : un `.sh` retiré dans la même PR que sa feature, une fois la
  parité prouvée (même assertion, même verdict).
- **A3 — gate : job `functional-tests` required check de `main`, sans `needs` — livré.** `ci.yml`,
  même préambule que `smoke-tests`. ⚠ Required check côté GitHub (Settings → branch protection) reste
  à ajouter par le fondateur.
- **A4 — langue : Gherkin en français — livré.** `backend/behat.dist.php` (`# language: fr`), steps PHP
  réutilisables (`#[Given]`/`#[When]`/`#[Then]`, `backend/tests/Behat/`).
- **A5 — qui écrit : l'IA rédige les premières features (migration des smokes), le fondateur relit et
  amende** — appliqué palier 1, reste la pratique pour 2-5.
- **A6 — isolement : PAS « mêmes rails que `WebTestCase` » comme envisagé au cadrage** (aucune transaction
  DAMA — la stack tourne réellement, l'isolement vient de la garde bac-à-sable
  (`BaseContext::guardSandbox`, jumelle de `sandbox-guard.sh`, refuse `amateo_local`/prod) plutôt que du
  rollback par scénario. RLS et `APP_ENV=dev` (pas `test`) — cohérent avec A1 : une vraie stack, pas un
  kernel de test.

**Hors scope (inchangé).** Mink/écrans ; réécrire les tests PHPUnit existants en Gherkin (doublon — la
valeur est le scénario que le fondateur relit, pas la traduction) ; un runner Behat côté engine (pytest
reste).

