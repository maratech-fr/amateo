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

## 2026-11-05 — lot Dependabot (4 PRs mergées dont 1 réparée, 2 majeures en attente)

### Paquets du backend (PR #1330 — réparée)

**C'est quoi** : les briques PHP du serveur — Symfony (le cadre de l'application : routes, sécurité,
envoi des mails, file de tâches), Doctrine (l'accès à la base), le module de réinitialisation de mot
de passe, et l'outillage d'analyse du code.

**Ça apporte** : **18 alertes de sécurité Symfony corrigées** (`composer audit` : plus aucune), toutes
réglées par la série 7.4.23 — c'est la raison de traiter ce lot tout de suite plutôt que de le subir.

**Adapté chez nous** : rien dans notre code. Deux réglages dans les dépendances :
- Dependabot résout hors de notre conteneur, sans le plugin Flex : 13 paquets Symfony étaient partis
  en 8.0.x. Ramenés sur la LTS 7.4 par `composer update --with-all-dependencies` dans le conteneur
  (le piège habituel, décrit dans le skill `dependabot`).
- PHPStan reste en 2.3.1 et Rector en 2.7 : PHPStan ≥ 2.3.4 empêche Rector 2.7 de lire les docblocks,
  et Rector 2.8 réécrirait `TenantFilterListener` et des listeners de sécurité. Cette montée est un
  chantier à part, qui touche l'isolation entre clubs (roadmap P4-80).

### Paquets du moteur (PR #1333 — 9 montées mineures/patch)

**C'est quoi** : FastAPI et Starlette (le serveur web du moteur), pandas (calculs sur tableaux),
pydantic-settings (lecture de la configuration), sentry-sdk (remontée d'erreurs), et l'outillage de
test et d'analyse (hypothesis, coverage, ruff, certifi).

**Ça apporte** : correctifs de routine, rien de visible pour un club.

**Adapté chez nous** : rien.

### Images Docker (PR #1331)

**C'est quoi** : Mercure, le canal temps réel qui pousse l'avancement d'une génération vers l'écran
(dev ET prod, même version des deux côtés), et Mailpit, la boîte aux lettres de test (dev seulement).

**Ça apporte** : correctifs de version patch (Mercure 1.1.2 → 1.1.5, Mailpit 1.31.6 → 1.32.0).

**Adapté chez nous** : rien.

### Paquets du frontend (PR #1329 — 8 montées mineures/patch)

**C'est quoi** : `@sentry/react` (remontée d'erreurs), `@tanstack/react-query` (données serveur à
l'écran), `lucide-react` (les icônes), `zustand` (état de l'écran), `vite` (la construction du site),
et l'outillage (`typescript-eslint`, `knip`, plugin eslint de react-query).

**Ça apporte** : correctifs de routine, rien de visible pour un club.

**Adapté chez nous** : rien.

### En attente : deux montées majeures (fiches présentées au fondateur)

- **PHPUnit 11 → 13** (PR #1332) : l'outil qui fait tourner les tests PHP.
- **python-dateutil 2 → 3** (PR #1334) : gestion des dates, que nous n'utilisons pas directement (il
  vient avec pandas).

### Alerte restante

`minimatch` 10.2.5 (alerte GitHub n°16, modérée, déni de service sur des motifs de fichiers) :
dépendance de l'outillage frontend seulement, rien en prod.

## 2026-10-31 — lot Dependabot (4 PRs mergées : frontend, puppeteer, umami, moteur)

### Paquets du frontend (PR #1284 — 5 montées mineures/patch)

**C'est quoi** : `@tanstack/react-query` 5.104.5 (la couche qui va chercher les données du serveur et les
garde à jour à l'écran, avec son plugin de règles `@tanstack/eslint-plugin-query`), `react-router` 8.5.0
(la navigation entre les écrans), `typescript-eslint` 8.72.0 (les règles d'analyse du code) et `knip`
6.42.1 (l'outil qui repère le code et les dépendances inutilisés).

**Ça apporte** : correctifs de routine, rien de visible pour un club.

**Adapté chez nous** : rien.

### Image `puppeteer` 25.14.0 → 25.16.0 (PR #1285)

**C'est quoi** : l'image de base du « pdf-worker » (`docker/pdf-worker/Dockerfile`), le petit service qui
pilote un vrai navigateur sans écran pour fabriquer les PDF d'export.

**Ça apporte** : correctifs du navigateur embarqué et de la librairie qui le pilote.

**Adapté chez nous** : rien (l'image reste figée par son empreinte).

### Image `umami` 3.4.0 → 3.4.1 (PR #1286)

**C'est quoi** : Umami, l'outil de statistiques de fréquentation de la vitrine, auto-hébergé
(`docker-compose.prod.yml`) — sans cookie ni service tiers.

**Ça apporte** : correctif de routine.

**Adapté chez nous** : rien.

### Paquets du moteur (PR #1287 — 7 montées mineures/patch)

**C'est quoi** : les bibliothèques Python du moteur de calcul — FastAPI 0.150.0 (le serveur web qui reçoit
les demandes du backend) et Starlette 1.9.0 (dessous), `hypothesis` 6.170.5 (tests par données générées),
`ruff` 0.17.1 (analyse et mise en forme du code), `websockets` 17.3, `pluggy` 1.7.0 (extensions de
pytest) et `ast-serialize` 0.13.1 (outillage de mypy).

**Ça apporte** : correctifs et suivi de routine.

**Adapté chez nous** : rien.

## 2026-10-25 — lot Dependabot (2 PRs mergées, frontend + engine)

### Paquets du frontend (PR #1265 — 17 montées mineures/patch)

**C'est quoi** : les bibliothèques de l'interface, dont React (le moteur d'affichage), Sentry
(remontée des erreurs), Vitest (tests unitaires) et `@playwright/test` (le robot qui joue les
parcours dans un vrai navigateur) — React 19.3.1, Sentry 11.8, Vitest 5.0.5, Playwright 1.64.1
en tête de lot.

**Ça apporte** : correctifs de routine.

**Adapté chez nous** : comme à chaque montée de `@playwright/test`, l'image Docker qui lance
Playwright en local/CI doit porter la MÊME version que le paquet — tag aligné sur
`v1.64.1-noble` dans `docker-compose.yml` (service `e2e`), gardé par
`backend/tests/Unit/Dependency/PlaywrightImageMatchesLockTest.php`.

### Paquets du moteur (PR #1240 — 7 montées mineures/patch)

**C'est quoi** : les bibliothèques Python du moteur de calcul des plannings — FastAPI 0.143.1
(le serveur web qui reçoit les demandes du backend), Pydantic 2.14.1 + `pydantic-settings` 2.16
(vérification de la forme des données), Starlette 1.8 (sous FastAPI), Ruff 0.17 (outil
d'analyse), `httptools` 0.9, NumPy 2.5.4 et `sentry-sdk` 2.72.

**Ça apporte** : correctifs et suivi de routine.

**Adapté chez nous** : rien.

## 2026-10-24 — modern-screenshot (nouvelle dépendance frontend, hors Dependabot — P4-323)

**C'est quoi** : `modern-screenshot`, une librairie qui rend un nœud du DOM en image (PNG/WebP)
côté navigateur — sans passer par une capture d'écran système ni un service tiers.

**Ça apporte** : la capture d'écran optionnelle jointe à un signalement (chantier F PR3,
`canal-signalement.md` §3sexies) : une image de l'écran courant, générée par le navigateur lui-même,
à l'identique de ce que l'utilisateur voit (texte sélectionnable rendu, pas une vidéo ni un flux
système).

**Adapté chez nous** : chargée en **import dynamique** (`frontend/src/shared/feedback/
captureScreen.ts`) — un chunk séparé, jamais dans le bundle principal, puisque la capture reste
l'exception (case cochée par défaut mais décochable, et seulement dans la modale de signalement).
Jamais de `workerUrl` : le worker de la librairie chargerait un script externe, que la CSP du
produit bloque. Aucun réglage serveur ni schéma à adapter — la sortie (WebP/PNG, ≤1920 px,
≤2 Mo) est validée côté serveur comme n'importe quel fichier joint, indépendamment de la
librairie qui l'a produite.

## 2026-10-16 (soir) — Python 3.12 → 3.14 (moteur, option B, montée complète)

**C'est quoi** : la version de Python qui fait tourner le moteur (`engine/`) — l'interpréteur
lui-même, pas une dépendance applicative.

**Ça apporte** : deux versions majeures de Python d'un coup (décision fondateur : sauter la 3.13
intermédiaire, « option B »), donc plus longtemps avant la prochaine montée obligatoire et le
dernier train de correctifs de sécurité de la branche en date.

**Adapté chez nous** : l'image `docker/engine/Dockerfile` passe à `python:3.14-slim` ;
`engine/pyproject.toml` relève le plancher (`requires-python >=3.14`) et recale les outils sur la
même cible (ruff, mypy) ; les verrous pip sont recompilés sous 3.14.8 SANS faire bouger la version
d'aucun paquet applicatif (`ortools` reste 9.15.6755, `pydantic` 2.14.0, `fastapi` 0.143.0 — seule
l'empreinte binaire change) ; le job CI « Dependency Audit » bascule son `setup-python` en 3.14 ;
le nouveau style `ruff format` autorisé par cette version (PEP 758, un `except A, B:` sans les
parenthèses qu'exigeait la 3.12) s'applique sur 21 fichiers, mécaniquement. Validé par
`make -C engine test` et `phpunit --group contract` (le contrat backend↔engine ne bouge pas,
`CONTRACT_VERSION` reste `1.5`), complétés par une contre-vérification indépendante 3.12 ⇄ 3.14 :
13 payloads réels (`/generate`, `/place-matches` — dont le snapshot BCCL à 141 matchs —,
`/validate-assignments`) rendent des sorties byte-identiques entre les deux versions (même statut,
même score sur le cas `dense_club` multi-worker), et 5 payloads invalides rendent le même 422
byte-identique des deux côtés. Seul écart mesuré : l'empreinte mémoire du conteneur monte
d'environ 6 % (≈147 Mo contre ≈138 Mo) — surveillé, non bloquant.

## 2026-10-16 (soir) — lot Dependabot (3 PRs mergées dont 1 réparée, 2 fermées)

### Doctrine ORM 3.7.4 → 3.7.5 (backend, PR #1208, réparée)

**C'est quoi** : Doctrine ORM, la couche qui lit et écrit nos données en base (entités ⇄ tables).

**Ça apporte** : correctifs de routine.

**Adapté chez nous** : comme à chaque lot backend, Dependabot avait tiré des composants Symfony en
8.0 (il résout hors de notre conteneur, sans la règle « rester sur la 7.4 ») : verrou de dépendances
régénéré dans le conteneur, seule Doctrine monte.

### Paquets du frontend (PR #1210) et du moteur (PR #1211)

`ky` 2.2 (appels HTTP du front), `lucide-react` 1.54 (icônes), `knip` 6.41 (outil qui repère le
code et les dépendances inutilisés) ; `absl-py` 2.5.1 côté moteur (utilitaire tiré par le solveur).
Correctifs de routine, rien à adapter.

### Fermées : PHP 8.5 (#1207), image Playwright 1.64.1 (#1215)

**PHP 8.4 → 8.5** : le fondateur a décidé de rester en 8.4 pour le moment (sécurité assurée
jusqu'à fin 2028). La montée telle que proposée ne construisait même pas l'image : OPcache est
intégré d'office en 8.5, notre Dockerfile essayait encore de l'installer. Elle se fera en PR
dédiée (P4-345) ; Dependabot ignore désormais PHP ≥ 8.5.

**Image Playwright** : son numéro doit être celui du paquet `@playwright/test` (sinon les tests
navigateur ne trouvent plus leur navigateur en local). Elle monte désormais AVEC le paquet npm,
jamais seule : Dependabot ne la propose plus.
