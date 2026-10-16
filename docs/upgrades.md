# Journal des upgrades techniques — le pourquoi du comment

> Tenu par le skill `/dependabot` à chaque traitement de PRs de dépendances. **Public : le
> fondateur, pas l'agent** — chaque entrée explique en français ce que fait le paquet, ce que
> l'upgrade apporte, et ce qu'il a fallu adapter chez nous. But : comprendre les mises à jour,
> pas les subir. Ordre antichronologique.
>
> **Règle de bornage (AUD-DOC-44, 2026-09-18) : ce fichier ne garde que les 6 derniers lots.**
> Au 7e lot ajouté, le plus ancien est retiré — son détail reste lisible dans git
> (`git log -p --follow docs/upgrades.md`). Un lot qui change un CONTRAT PUBLIC laisse en plus
> une trace PERMANENTE dans `specs/courantes/etat-des-lieux.md` §3 (règle posée dans
> `.claude/skills/dependabot/SKILL.md` étape 4) — cette permanence-là ne dépend pas du bornage
> d'ici.

## 2026-10-16 — Redis 8 (lot 4 PR D, hors Dependabot)

**C'est quoi** : l'image Redis — la file des générations (transport Messenger), les verrous
distribués (génération, placement de matchs) et les limiteurs anti-abus, en dev comme en prod
(`redis:8-alpine` dans les deux fichiers compose).

**Ça apporte** : une version maintenue (la 7 sort de support) et le retour d'une licence open
source AGPLv3 — la 7.4 était passée « source-available » (RSALv2/SSPLv1).

**Adapté chez nous** : verdict licence OK — l'AGPLv3 n'oblige qu'en distribuant une version
MODIFIÉE de Redis, or nous utilisons l'image officielle non modifiée, en interne uniquement.
Trois changements : les deux lignes d'image compose, le retrait de l'exception majeure dans
`.github/dependabot.yml` (Redis n'a plus de raison d'être ignoré), et une procédure de snapshot
du volume `redis_data` avant tout déploiement (`docs/ops/backup-restore.md` §7) — un retour
arrière 8 → 7 n'étant pas garanti sur le format AOF/RDB. Couvre le volet Redis de la ligne
**P4-310** de la roadmap ; reste le volet Mercure 1.0 de cette même ligne.

## 2026-10-16 — lot Dependabot (5 PRs mergées dont 2 réparées, 2 fermées)

### Paquets du moteur (engine, PR #1196 — 9 montées mineures)

**C'est quoi** : les bibliothèques Python du moteur de calcul des plannings — `fastapi` (le
serveur web qui reçoit les demandes du backend), `pydantic` (qui vérifie la forme des données
reçues), et des outils de test.

**Ça apporte** : correctifs et suivi de routine. Seul changement de comportement notable :
FastAPI 0.143 ne configure plus automatiquement la télémétrie OpenTelemetry — nous ne
l'utilisons pas, donc aucun effet.

**Adapté chez nous** : rien.

### protobuf 6 → 7 (engine, PR #1197 — FERMÉE)

**C'est quoi** : un format d'échange de données utilisé en interne par le solveur `ortools`.
Notre code ne s'en sert jamais directement.

**Ça apporte** : rien pour nous — et c'est **incompatible** : `ortools` 9.15 exige une version
de protobuf inférieure à 6.34. Mergée, plus aucune génération de planning n'aurait fonctionné.

**Adapté chez nous** : PR fermée et version majeure ignorée par Dependabot ; protobuf montera
quand une future version d'`ortools` l'acceptera.

### Paquets du frontend (PR #1195 — 15 montées mineures, réparée)

**C'est quoi** : les bibliothèques de l'interface, dont `@playwright/test` (le robot qui joue
les parcours dans un vrai navigateur) et Sentry (remontée des erreurs).

**Ça apporte** : correctifs de routine.

**Adapté chez nous** : l'image Docker qui lance Playwright en local doit porter la MÊME version
que le paquet — passée de 1.63.0 à 1.64.0 (`docker-compose.yml`, service `e2e`). C'était aussi
la moitié utile de la PR #1193, fermée.

### Paquets du backend (PR #1194 — 21 montées, réparée)

**C'est quoi** : les correctifs de la version 7.4 de Symfony, Sentry, et les outils d'analyse
(PHPStan, Rector) ; plus `symfony/mercure-bundle`, qui envoie les mises à jour en direct à l'écran.

**Ça apporte** : correctifs de routine ; PHPStan 2.3 détecte des cas qu'il laissait passer.

**Adapté chez nous** : trois choses.
- Dependabot avait tiré plusieurs composants Symfony en 8.0 (il résout sans notre règle « rester
  sur la 7.4 ») : ramenés en 7.4 par `composer update` dans le conteneur.
- `mercure-bundle` 0.6 parle par défaut la langue de **Mercure 1.0**, alors que notre serveur
  Mercure est encore en 0.x : épinglé à `protocol_version: '0.x'` dans
  `backend/config/packages/mercure.yaml`, en attendant la montée vers Mercure 1.0 du lot 4.
- PHPStan 2.3 a signalé une vérification inutile dans `VenueTravelTimeAutofillService` : retirée.

### Mercure 0.24 en production (PR #1193 — FERMÉE)

**C'est quoi** : le serveur qui pousse les mises à jour en direct (progression d'une génération,
calcul des trajets, placement des matchs).

**Ça apporte** : une étape intermédiaire que le fondateur a refusée : Mercure passe directement
de 0.19 à 1.0, même version en dev et en prod, dans une PR dédiée du lot 4.

**Adapté chez nous** : rien ici.

### Mineurs sans impact (#1192, #1183)

`shivammathur/setup-php` 2.40.0 (installe PHP dans la CI, empreinte vérifiée sur le tag) et
l'image `puppeteer` 25.14.0 du worker qui fabrique les PDF. Rien à adapter.

## 2026-10-16 — symfony/lock (nouvelle dépendance, lot 4 PR E, hors Dependabot — P4-316)

**C'est quoi** : `symfony/lock`, le composant Symfony de verrous — jusqu'ici absent du
`composer.json` backend, les quelques verrous du produit (génération, placement de matchs)
passaient par un usage direct de Redis, pas par ce composant.

**Ça apporte** : un store de verrous PARTAGÉ et configurable (`framework.lock`), que Symfony
câble automatiquement derrière `rate_limiter` (`lock_factory: auto`) — jusqu'ici, sans
`framework.lock` configuré, ce câblage résolvait un store LOCAL au système de fichiers d'un
conteneur (non partagé entre les workers php-fpm, ni avec `messenger-worker`) : un plafond
d'envoi par club (ex. `coach_wish_send`) restait contournable par des requêtes parallèles.

**Adapté chez nous** : `backend/config/packages/lock.yaml` pose `framework.lock:
'%env(REDIS_URL)%'` — même DSN que le cache. Conséquence immédiate et volontaire : **tous** les
limiteurs `rate_limiter` (pas seulement le nouveau `coach_wish_send`, P4-316) deviennent
atomiques, du fait de ce seul fichier de config, sans toucher à `rate_limiter.yaml`.

## 2026-09-17 — lot Dependabot (2 PRs mergées, dont 1 réparée)

### API Platform 4.3 → 4.4 (backend, PR #900)

**C'est quoi** : `api-platform/*` (les paquets `doctrine-orm`, `symfony` et leurs dépendances
internes), le socle qui transforme nos classes PHP en API — c'est lui qui lit nos entités/DTOs
et en déduit les routes, la documentation technique (« contrat OpenAPI », le plan exact de ce que
l'API accepte et renvoie) et le format JSON réellement servi.

**Ça apporte** : suivi de routine côté fonctionnement (aucune route, aucun schéma, aucune
propriété n'a bougé). Le changement visible est plus subtil : le contrat OpenAPI généré passe de
la version de spec 3.1.0 à 3.2.0, qui autorise désormais un cas que 3.1 interdisait — écrire une
« description » (le texte d'aide qui accompagne un champ dans la doc technique) juste à côté
d'un renvoi vers un autre schéma. Trois champs qui avaient ce cas (`Schedule.capabilities`,
`ScheduleDiagnostic.causes`, `SchedulePlan.staleness`) ont donc vu leur description, jusqu'ici
tue par la version 3.1, apparaître pour la première fois dans le contrat public.

**Adapté chez nous** : cette apparition a révélé un problème que personne n'avait vu, parce que
le texte était invisible jusque-là — deux de ces descriptions contenaient des références internes
d'équipe (des codes du type « P2-8 », « P4-101 », qui n'ont aucun sens pour qui consomme l'API de
l'extérieur), tirées des commentaires de code source. Un garde-fou automatique existant
(`PublicTextIsFreeOfInternalIdentifiersTest`) les a détectées et a fait échouer les tests : la
phrase utile est restée dans le commentaire de code (pour les développeurs), la référence interne
en a été retirée, et la description publique a été réécrite en français simple côté API. Au
passage, on a repéré que **cinq autres** descriptions déjà publiées avant ce lot (sur
`ScheduleResource.php` : `planType`, `generatedTeamCount`, `hasStructurePhoto`, `isLiveContext`,
`isChosen`) sont écrites en jargon de développeur anglais — sans référence interne, donc le
garde-fou ne les voit pas. Ligne de dette ex-`P4-218`, fermée sans correctif au triage roadmap du
2026-09-25 (cosmétique, aucun impact fonctionnel — décision fermée, `specs/courantes/etat-des-lieux.md` §2).

### Le piège Flex, encore (backend, PR #900)

**C'est quoi** : un rappel plutôt qu'une nouveauté — Dependabot calcule les mises à jour de
dépendances backend HORS de notre environnement habituel, donc sans le mécanisme (« Flex ») qui
impose à Symfony de rester sur sa version longue durée (LTS 7.4) pendant la résolution.

**Ça apporte** : rien de nouveau en soi, mais ça explique pourquoi la PR proposait au départ de
faire sauter 18 briques Symfony vers une version 8.0 qu'on ne veut pas encore (bugs corrigés
jusqu'en 2028, sécurité jusqu'en 2029 sur la 7.4 — la 8.0 n'a pas cette garantie de stabilité).

**Adapté chez nous** : recalculé dans notre environnement habituel avec seulement les 15 paquets
visés, ce qui a ramené tout Symfony sur la LTS 7.4 (versions 7.4.16 à 7.4.19) — jamais en figeant
une version dans le fichier de config (ça masquerait le problème sans le résoudre), toujours en
relançant le calcul correctement outillé.

### Doctrine ORM 3.6 → 3.7 + Doctrine Collections 2 → 3 (backend, PR #900)

**C'est quoi** : `doctrine/orm` traduit nos entités PHP en requêtes SQL ; `doctrine/collections`
(monté en même temps, comme dépendance technique) fournit le type de liste utilisé quand une
entité porte plusieurs éléments liés (ex. une équipe et ses créneaux).

**Ça apporte** : suivi de routine ; la version 3 de Collections retire des façons de faire
obsolètes et resserre ses règles internes.

**Adapté chez nous** : rien — vérifié qu'aucune de nos entités n'utilise ce type de liste
aujourd'hui (aucune relation ne compte plusieurs éléments liés dans l'autre sens chez nous).

### Rector 2.6.5 → 2.6.7 (backend, PR #900)

**C'est quoi** : l'outil qui réécrit automatiquement notre code pour suivre le style PHP décidé
pour le projet (une passe obligatoire avant chaque envoi de code backend).

**Ça apporte** : une nouvelle règle de style (préférer `__DIR__ . '/../x'` à une écriture plus
détournée du même chemin de fichier).

**Adapté chez nous** : appliquée sur le seul fichier concerné (`backend/tests/bootstrap.php`) —
Rector fait convention chez nous, une nouvelle règle s'applique dès qu'elle sort.

### Playwright 1.62 → 1.63 (frontend, PR #901)

**C'est quoi** : l'outil qui pilote un vrai navigateur pour jouer nos parcours utilisateur de bout
en bout (les tests dits « e2e »).

**Ça apporte** : suivi de routine.

**Adapté chez nous** : rien à adapter, mais une précaution prise avant de merger — ces tests ne
se jouent que sur les machines de la CI (pas en local), donc on a attendu que le run e2e déclenché
par la PR soit vert avant de merger, plutôt que de merger « à l'aveugle » sur les seuls tests
locaux.

### Le reste — montées mineures sans impact

**Backend (PR #900)** : `doctrine/doctrine-migrations-bundle`, `phpstan/phpdoc-parser`,
`sentry/sentry-symfony`, les paquets Symfony `framework-bundle`/`property-access`/
`property-info`/`serializer`/`validator`/`yaml` (restés sur la LTS 7.4), `behat/behat`,
`phpstan/phpstan`. **Frontend (PR #901)** : dix-neuf paquets mineurs/correctifs — Sentry,
React Query (+ ses outils de dev), `ky`, `lucide-react`, `react-router`, Storybook, Testing
Library, ESLint et ses greffons, TypeScript-ESLint, `@types/node`, `@types/react-dom`,
`@vitejs/plugin-react`, `globals`. Suivi de routine des deux côtés, rien à adapter — vérifié par
la suite de tests complète de chaque zone (backend : miroir exact de la CI, PHPStan + CS-Fixer +
tests + Rector à vide ; frontend : lint + build + tests, image de test reconstruite pour ne pas
valider une version périmée).

## 2026-09-04 — behat/behat 3.32 (backend, hors Dependabot — P4-165 palier 1)

**C'est quoi** : `behat/behat` (^3, v3.32.0 installée), le runner de tests fonctionnels Gherkin —
un scénario `Étant donné / Quand / Alors` écrit en français devient un test PHP exécutable. Ajouté
en `require-dev` du backend, pas par Dependabot : décision produit (le fondateur veut relire des
scénarios métier avant le code).

**Ça apporte** : la première couche de tests fonctionnels **lisible par un non-développeur**. La
config vit dans `backend/behat.dist.php` (Gherkin `# language: fr`, une seule suite `generation`
pour l'instant), les features dans `backend/features/`, les contexts dans `backend/tests/Behat/`.

**Adapté chez nous** : les contexts (`BaseContext`, `SeasonGenerationContext`) parlent **HTTP à la
stack qui tourne** (`http://nginx/api`) plutôt que d'ouvrir un noyau Symfony en mémoire — choix
délibéré, pas une contrainte de la librairie : ni `FriendsOfBehat/SymfonyExtension`, ni
`BrowserKit`, ni transaction DAMA. Le jeton JWT est minté via `bin/console lexik:jwt:generate-token`,
la garde bac-à-sable (`BaseContext::guardSandbox`) est une jumelle de
`backend/scripts/lib/sandbox-guard.sh`. Nouvelle cible `make -C backend behat` (redémarre
`messenger-worker`, garde sandbox) et nouveau job CI `functional-tests` (sans `needs`, même
préambule que `smoke-tests`). Le smoke `backend/scripts/smoke-solver.sh` est **supprimé** : la
première feature (`generation-du-planning-de-saison.feature`) le remplace à parité prouvée (même
verdict `COMPLETED`).

## 2026-09-01 — lot Dependabot

### Groupe frontend-npm — 20 montées + browserslist 4.28.8 (PR #813)

**C'est quoi** : vingt briques de la partie visible (mineurs/correctifs), plus `browserslist`
(la table « quels navigateurs supporter » utilisée par la chaîne de build), poussée à la main
dans le même lot.

**Ça apporte** : deux failles publiées LE JOUR MÊME sur browserslist (dont une « high » —
croissance mémoire non bornée) faisaient rougir l'audit de sécurité de TOUTES les PRs du dépôt ;
corrigées en 4.28.7, on embarque la 4.28.8. Le reste : suivi de routine.

**Adapté chez nous** : rien — 2299 tests frontend verts, build Vite vert, audit à zéro.

### Groupe backend-composer — 6 montées (PR #812)

**C'est quoi** : le pont temps réel Mercure (`symfony/mercure-bundle` 0.4→0.5, qui pousse les
mises à jour de génération à l'écran) et trois outils de qualité de code (php-cs-fixer, phpstan,
rector).

**Ça apporte** : suivi de routine ; mercure-bundle 0.5 = la branche maintenue.

**Adapté chez nous** : le piège Flex documenté a mordu comme prévu — Dependabot résout hors de
notre conteneur et 9 briques Symfony avaient sauté en 8.0.x ; ramenées sur la LTS 7.4 par le
correctif canonique (`composer update` ciblé dans le conteneur, jamais de pin). Miroir CI complet
vert (1962 tests), rector vert, smoke solveur vert.

### github-actions — docker/setup-buildx-action 4.2 → 4.3 (PR #746)

**C'est quoi** : l'action qui prépare le constructeur d'images Docker du déploiement.

**Ça apporte** : mineure de routine, épinglée par empreinte (vérifiée en amont).

**Adapté chez nous** : rien.
