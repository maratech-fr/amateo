---
name: dependabot
description: Traite toutes les PRs Dependabot ouvertes — vérifie que chaque upgrade ne casse rien, répare si besoin, lance les tests, merge et pousse le correctif. Invoquer ce skill vaut GO de merge pour les PRs Dependabot uniquement.
---

# Dependabot — traitement des PRs de dépendances

Tu traites **toutes les PRs Dependabot ouvertes** du dépôt, de bout en bout. L'invocation de ce
skill par l'utilisateur **vaut autorisation de merger ces PRs-là** (et uniquement celles-là — la
règle « jamais merger sans go » reste en vigueur pour tout le reste).

## Étape 0 — Inventaire & tri

1. `gh pr list --author app/dependabot --state open` — liste complète.
2. Grouper par **zone** : `frontend` (npm), `backend` (composer), `engine` (pip), `ci`
   (github-actions). Traiter zone par zone, une PR à la fois dans la zone.
3. Dans chaque zone, ordre : **mineurs/patch d'abord, majeurs en dernier** (un majeur cassant ne
   doit pas bloquer les mineurs sains). Les PRs « group » de Dependabot passent avant les PRs
   individuelles qu'elles englobent (souvent Dependabot ferme les doublons tout seul après merge).
4. Vérifier que la CI de `main` est verte avant de commencer — sinon la réparer d'abord (une base
   rouge rend le verdict des PRs illisible).

## Étape 1 — Par PR : vérifier

1. `gh pr checkout <n>` puis rebase sur `main` à jour (`git fetch origin && git rebase origin/main`).
   Conflit de lockfile → régénérer le lockfile (`npm install` / `composer update <paquet>` /
   pip selon la zone) plutôt que résoudre à la main — **toujours DANS le container de la zone**,
   jamais sur l'hôte (voir l'encart Flex ci-dessous : c'est le container qui porte les plugins qui
   contraignent la résolution).
2. **Majeur ?** Lire le changelog/notes de migration du paquet (WebFetch sur le repo GitHub du
   paquet) AVANT de lancer les tests — savoir quoi surveiller.
3. Installer et lancer la **suite de la zone** :
   - `frontend/` : **dans le container**, jamais sur l'hôte — `make -C frontend test` (qui enchaîne
     `lint` = eslint + `tsc -b --force`, puis vitest). ⚠️ **Rebuilder l'image tooling d'abord**
     (`docker compose --profile tools build frontend-tooling`) : elle COPIE le code, donc sans
     rebuild on valide une version périmée. Compléter par un `npx vite build` dans le même
     container si le paquet touche la chaîne de build.
   - `backend/` : dans le container — `composer install`, puis `make -C backend tests-complete`
     (PHPStan + CS-Fixer + `phpunit tests/`, miroir exact du job CI ; il enchaîne lui-même
     `db-init-test`). ⚠️ **Pas `make test`**, qui ne lance que la testsuite `Unit` (rapide, sans
     DB) — `Integration` et `Contract` restent hors de vue par construction (cf. CLAUDE.md §10.1).
     Si le lot touche php-cs-fixer, phpstan ou rector, lancer AUSSI `make -C backend rector` : ces
     trois-là sont des gates CI distincts et peuvent rougir sur du code inchangé.
   - `engine/` : dans le container — `make test` (ruff + mypy + bandit + pytest)
   - `ci` (github-actions) : pas de tests locaux — vérifier que les versions d'actions existent et
     lire le diff (breaking inputs renommés/supprimés).
4. Dépendance **runtime** backend ou engine touchée (pas dev-only) → redémarrer les containers
   longue durée (`docker compose restart engine messenger-worker`) puis la feature de génération
   `make -C backend behat` (planning `COMPLETED` attendu).

## Étape 2 — Par PR : réparer si rouge

1. Diagnostiquer : la casse vient-elle de l'upgrade (API changée, config à migrer, dépréciation
   devenue erreur) ou d'un flaky ? Flaky → relancer une fois avant de conclure. **Avant tout :
   la casse est-elle INTRODUITE par la PR, ou déjà présente sur `main` ?** Comparer les deux
   (ex. `git show origin/main:backend/composer.lock`) — un défaut préexistant ne se répare pas ici.

> ⚠️ **Piège composer/Flex — attendu à CHAQUE lot backend.** `SymfonyStackAlignmentTest` échoue en
> listant des paquets Symfony passés en 8.0.x ? Ce n'est ni un flaky ni une régression de notre
> code : **Dependabot résout hors de notre container**, donc sans le plugin Flex qui impose
> `extra.symfony.require` (`7.4.*`) à TOUS les splits Symfony, transitifs compris. Il tire alors la
> dernière majeure. **Correctif** : `composer update <les paquets visés> --with-all-dependencies`
> **dans le container**, ce qui conserve les montées voulues et ramène Symfony sur la LTS.
> **Surtout PAS un pin dans `composer.json`** : il traiterait le symptôme en laissant croire que les
> transitifs ne sont pas couverts (CLAUDE.md §5, P4-31 — récidive constatée le 2026-07-29).
2. Réparer **notre code** pour suivre la nouvelle API — jamais figer/downgrader la version pour
   éviter la réparation, sauf si la migration dépasse ~1 h de travail ou touche un axe structurant
   (§7.1 CLAUDE.md). Dans ce cas : commenter la PR avec le diagnostic, la laisser ouverte, la
   signaler à l'utilisateur dans le bilan.
3. Committer le correctif **sur la branche de la PR Dependabot** (préfixe `(IA Claude) `,
   trailers habituels) et pousser. Dependabot tolère les commits ajoutés à ses branches.
4. Relancer la suite de la zone → verte.

## Étape 3 — Par PR : merger

1. Suite de zone verte (+ smoke si applicable) → `gh pr merge <n> --squash --delete-branch`.
2. `git checkout main && git pull` avant la PR suivante (chaque verdict se rend sur main à jour).
3. La CI GitHub ne gate pas le merge (double-contrôle) — le verdict local fait foi.

## Étape 4 — Bilan + journal des upgrades (obligatoire)

1. **Tableau final** : PR · paquet(s) · verdict (mergée / réparée+mergée / laissée ouverte+pourquoi).
   Signaler explicitement toute PR laissée ouverte et la raison (migration lourde, axe structurant,
   breaking irréparable). Si un correctif a touché du code produit (pas seulement lockfile/config),
   le mentionner — c'est un candidat à revue.
2. **Mettre à jour `docs/upgrades.md`** (le journal du pourquoi) : une section datée par lot,
   une entrée par upgrade notable (les mineurs sans impact peuvent être groupés). Chaque entrée,
   **écrite pour le fondateur, pas pour l'agent**, répond à trois questions en français simple :
   - **C'est quoi** — ce que fait le paquet dans l'application (une phrase, zéro jargon non défini) ;
   - **Ça apporte** — ce que la mise à jour change concrètement (perf, sécu, pérennité, features)
     et pourquoi la faire maintenant plutôt que la subir plus tard ;
   - **Adapté chez nous** — ce que l'upgrade a forcé à changer dans NOTRE code (ou « rien »).
   Ce journal se committe **dans la même PR** que les correctifs quand il y en a, sinon sur une
   branche doc dédiée. Une dette découverte au passage (paquet installé jamais utilisé, config
   morte) → ligne P4 dans `specs/evolution/roadmap.md`, référencée depuis l'entrée.

## Gardes-fous

- **Jamais** merger une PR dont la suite de zone est rouge.
- **Jamais** downgrader une dépendance de sécurité (les PRs `security` de Dependabot sont prioritaires — les traiter en premier, toutes zones confondues).
- Backend/engine : tout se lance **dans les containers** (PHPUnit avec `APP_ENV=test`).
- **Tout se lance dans les containers, frontend compris.** L'hôte n'a que Docker, Docker Compose et
  Make — convention du dépôt (CLAUDE.md §10), et **les 12 cibles de `frontend/Makefile` passent par
  `docker compose`, sans exception**. Tester le frontend sur l'hôte valide une version de Node qui
  n'est celle de personne : ni celle de la CI (runners `setup-node`), ni celle de l'image tooling —
  et fait conclure à tort qu'il faut agir sur le poste du fondateur (constaté 2026-07-29).
- Une seule PR à la fois — pas de merges parallèles (conflits de lockfile en cascade).
