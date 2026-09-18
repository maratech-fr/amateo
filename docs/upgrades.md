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
garde-fou ne les voit pas. Ligne de dette ouverte : `P4-218` dans `specs/evolution/roadmap.md`.

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

## 2026-08-18 — lot Dependabot

### Groupe frontend-npm — 7 montées (PR #617)

**C'est quoi** : sept briques de la partie visible, toutes en version mineure ou corrective.
**Sentry** est le mouchard qui nous prévient quand un écran plante chez un club, avec la trace de
ce qui s'est passé. **Lucide** est la bibliothèque d'icônes. **ESLint** et **typescript-eslint**
sont les relecteurs automatiques qui refusent le code douteux avant qu'il n'arrive en production.
**jest-dom**, **@types/node** et **eslint-plugin-react-refresh** sont de l'outillage d'atelier :
ils n'existent que chez nous, jamais chez les clubs.

**Ça apporte** : des correctifs, pas des nouveautés — Sentry 10.69 → **10.70**, Lucide 1.29 →
**1.31**, ESLint 10.8.0 → **10.8.1**, typescript-eslint 8.66 → **8.67**, plus trois patchs
d'outillage. Aucune rupture, rien à réapprendre. L'intérêt de les prendre au fil de l'eau est
précisément d'éviter le saut de version douloureux qu'on subit quand on laisse traîner.

**Adapté chez nous** : **rien**. Suite complète verte (176 fichiers, 1538 tests) **et build de
production vérifié en plus des tests** — Sentry et Lucide finissent tous deux dans le fichier que
les navigateurs téléchargent, or un test vert ne prouve pas qu'on sait encore fabriquer ce fichier.

### actions/cache 4.3.0 → 6.1.0 (PR #616)

**C'est quoi** : une brique de notre chaîne d'intégration — celle qui **met en cache** des choses
lourdes entre deux exécutions, pour ne pas les retélécharger à chaque fois. On s'en sert à deux
endroits : le navigateur Chromium des tests de bout en bout, et la base de données de failles de
sécurité du scanner. Elle ne tourne que sur GitHub, jamais chez les clubs.

**Ça apporte** : deux versions majeures d'un coup, mais dont les ruptures sont **internes** —
réécriture du module en ESM, mise à jour des dépendances, meilleure gestion d'un cache en lecture
seule. Les réglages qu'on utilise (`path`, `key`, `restore-keys`) et le signal qu'on lit
(`cache-hit`, qui nous dit s'il faut retélécharger Chromium) sont l'interface stable : ils n'ont
pas bougé. Rester sur une majeure abandonnée d'une action GitHub, c'est prendre le risque qu'elle
cesse un jour de fonctionner sans préavis.

**Adapté chez nous** : **rien** — deux lignes de version épinglée. ⚠ Une montée d'action ne se
teste pas en local : **c'est la CI de la PR qui EST le test**, et elle exerce bien les deux usages
(cache Chromium dans les tests de bout en bout, cache du scanner dans le job sécurité). Les 14
contrôles sont passés.

### Rector 2.5.9 → 2.6.1 (PR #615)

**C'est quoi** : **Rector** est un outil qui relit le code PHP et le réécrit tout seul pour le
mettre au goût du jour — passer d'une vieille façon d'écrire à celle que recommande la version
actuelle de PHP ou de Symfony. Chez nous il ne se contente pas de proposer : **son style FAIT
convention** et la CI refuse de passer si le code s'en écarte. Il ne tourne jamais chez les
clubs — c'est un outil d'atelier, pas une brique du produit.

**Ça apporte** : une version mineure, mais qui embarque une **nouvelle règle** — et une nouvelle
règle Rector, chez nous, veut dire du travail immédiat : le gardien de style se met à refuser du
code qui passait la veille. Mieux vaut le prendre maintenant, sur dix fichiers connus, que dans six
mois sur cinquante.

**Adapté chez nous** : **10 fichiers**. La règle `ParamAndEnvAttributeRector` remplace l'écriture
par gabarit de texte par une écriture nommée, pour les valeurs que Symfony injecte dans nos
services :

```php
#[Autowire('%env(REDIS_URL)%')]   →   #[Autowire(env: 'REDIS_URL')]
#[Autowire('%kernel.debug%')]     →   #[Autowire(param: 'kernel.debug')]
```

Le comportement est **identique** — c'est la même valeur, injectée au même endroit. Ce qui change,
c'est que l'intention est dite explicitement (« ceci est une variable d'environnement », « ceci est
un paramètre »), donc lisible et vérifiable par l'outillage, au lieu d'être devinée dans une
chaîne de caractères. Deux fichiers ont ensuite demandé un passage de CS-Fixer, Rector ayant écrit
des noms de classes en entier là où le dépôt veut un import.

⚠ **Le correctif touche du code de production**, dont `JwtCookieFactory` et `MercureAuthController`
— deux fichiers sensibles (cookie JWT, authentification Mercure). La modification n'y change que la
FAÇON dont une valeur de configuration arrive, jamais ce qu'on en fait. Vérifié par le miroir
complet de la CI : **1591 tests, 9223 assertions**, dont les tests bloquants de sécurité.

**Et un réglage d'outillage, qui est le vrai enseignement du lot.** Nos deux gardiens de style se
sont mis à se contredire : Rector réécrit en noms de classes ENTIERS
(`\Symfony\Component\HttpFoundation\Cookie::…`), CS-Fixer les IMPORTE (`Cookie::…`) — chacun
défaisant l'autre, chacun rouge dans son propre contrôle. Ce n'est pas nouveau : c'est que jusqu'ici
Rector n'avait **rien à réécrire**, donc les deux ne se croisaient jamais. La première règle qui le
fait travailler a révélé le désaccord. `backend/rector.php` gagne donc `withImportNames()` — Rector
importe désormais, comme CS-Fixer — **borné à `removeUnusedImports: false`** : on voulait aligner
les deux outils, pas déclencher un ménage d'imports sur 8 fichiers étrangers à la montée (supprimer
un import n'est jamais anodin quand un docblock le référence encore). Effet réel : 2 fichiers, un
import de fonction Sentry.

## 2026-08-15 — lot Dependabot

### Groupe frontend-npm — Vite, Storybook, Lucide (PR #550)

**C'est quoi** : trois outils de la partie visible. **Vite** est la machine qui assemble le code de
l'interface en fichiers que le navigateur sait lire — c'est lui qui tourne quand tu lances le mode
développement, et c'est lui qui fabrique la version de production. **Storybook** est l'atelier où
l'on regarde un composant seul, hors de l'application, pratique pour travailler un bouton ou une
carte sans devoir naviguer jusqu'à son écran. **Lucide** est la bibliothèque d'icônes.

**Ça apporte** : trois correctifs et un lot d'icônes — Vite 8.2.0 → **8.2.1**, Storybook 10.5.6 →
**10.5.7**, Lucide 1.28 → **1.29**. Aucune rupture, aucune nouveauté à apprendre. Sur Vite, prendre
les correctifs vite est utile : c'est la brique qui fabrique ce que les clubs téléchargent, un bug
d'assemblage s'y voit en production, pas chez nous.

**Adapté chez nous** : rien. Suite complète verte (1328 tests) et **build de production vérifié en
plus des tests** — Vite touchant justement la fabrication, un test vert ne prouve pas qu'on sait
encore livrer : 2511 modules assemblés sans erreur.

### Groupe backend-composer — Doctrine, Sentry, Symfony (PR #549)

**C'est quoi** : quatre briques de la partie serveur. **Doctrine ORM** est le traducteur entre les
objets PHP de l'application et les tables de la base de données — c'est lui qui écrit et relit
chaque équipe, chaque créneau. **Sentry** est le mouchard d'erreurs : quand quelque chose casse en
production, c'est lui qui te prévient avec la pile d'appels, au lieu que tu l'apprennes par un club
mécontent. **symfony/mime** fabrique les emails (pièces jointes, encodages), **symfony/yaml** lit
les fichiers de configuration.

**Ça apporte** : que des correctifs d'entretien — Doctrine 3.6.7 → **3.6.8**, Sentry 5.11 →
**5.12**, mime 7.4.15 → **7.4.16**, yaml 7.4.13 → **7.4.15**. Rien de spectaculaire, et c'est le
but : les prendre au fil de l'eau évite le saut coûteux qu'on subit quand on a six mois de retard.
Les deux paquets Symfony restent sur la branche **LTS 7.4**, celle qu'on tient jusqu'à la 8.4
(fin 2027).

**Adapté chez nous** : rien dans le code applicatif. Mais **deux interventions sur la PR
elle-même**, toutes deux prévisibles :

1. **Rector 2.6.1 refusé** — Dependabot avait forcé notre garde-fou (`~2.5.9` réécrit en `~2.6.1`).
   Vérifié en le testant plutôt qu'en lisant nos notes : la 2.6.1 **réintroduit** le défaut connu,
   elle réécrit `Cookie::SAMESITE_STRICT` en nom complet dans `JwtCookieFactory` et
   `MercureAuthController`, que PHP-CS-Fixer raccourcit aussitôt. Les deux outils se contrediraient
   sans fin et **plus aucune fusion backend ne passerait**. Garde-fou restauré, les quatre autres
   montées conservées.
2. **Symfony ramené sur la LTS** — Dependabot calcule les versions **hors de notre conteneur**,
   donc sans le mécanisme qui force toute la famille Symfony à rester en 7.4. Il avait fait passer
   neuf briques internes en 8.0. Corrigé en recalculant dans le conteneur ; c'est le réflexe
   attendu à chaque lot backend, jamais un blocage de version.

⚠ À noter pour plus tard : Rector 2.6 apporte une règle intéressante (`ParamAndEnvAttributeRector`,
qui modernise l'écriture des variables d'environnement dans le code — 10 fichiers concernés chez
nous). Elle attend que le défaut ci-dessus soit corrigé en amont. Suivi : ligne **P4-80** de la
roadmap.

## 2026-08-11 — lot Dependabot

### Groupe backend-composer — PHP-CS-Fixer, PHPStan, Rector (outils de dev, PR #504)

**C'est quoi** : les trois outils qui relisent le code PHP automatiquement. **PHP-CS-Fixer** met le
code en forme (indentation, ordre des imports…), **PHPStan** cherche les erreurs de logique sans
exécuter le programme, **Rector** modernise le code vers les tournures de PHP 8.4. Aucun des trois
ne part en production : ils tournent chez nous et dans la CI. Chacun est un verrou qui bloque une
fusion s'il n'est pas content.

**Ça apporte** : PHP-CS-Fixer 3.95.17 → **3.95.18** et PHPStan 2.2.6 → **2.2.8** sont des correctifs
d'entretien, sans effet visible — on les prend au fil de l'eau pour ne pas accumuler du retard qui
devient un jour un saut coûteux. Rector 2.5.8 → **2.5.9** apporte une règle de plus.

**Adapté chez nous** : **un fichier**, `FfbbEngagementsController`. Rector 2.5.9 y demande d'écrire
« si cette variable EST une réponse d'erreur » plutôt que « si elle n'est pas vide » — c'est
exactement la convention que le projet s'est donnée (P4-24), et elle dit plus précisément ce que le
code vérifie.

**⚠ Et une version a été volontairement REFUSÉE : Rector 2.6.** Dependabot proposait 2.6.1. Testée,
elle réécrit deux fichiers de sécurité (le cookie qui porte la connexion, l'authentification
Mercure) en remplaçant les noms courts par des chemins complets — et **PHP-CS-Fixer les remet
aussitôt en noms courts**. Les deux outils se contredisent, chacun défaisant le travail de l'autre :
comme les deux bloquent la fusion, **plus aucune modification ne pourrait passer**. Vérifié que la
faute vient bien de l'outil et pas de notre code : Rector déclare lui-même n'appliquer **aucune
règle** sur ces fichiers (`applied_rectors: []`) — c'est son moteur d'écriture qui déraille, pas une
convention nouvelle qu'il faudrait suivre. 2.6.0 a le même défaut, 2.5.9 est saine. La version est
donc bornée à la série 2.5 (`~2.5.9` : les correctifs 2.5.x continuent d'arriver, la série 2.6 est
tenue dehors) jusqu'à ce que l'outil soit réparé — suivi en **P4-80**.

### Groupe frontend-npm — 13 paquets (PR #505)

**C'est quoi** : treize briques de l'interface web. Trois seulement partent chez l'utilisateur —
**Sentry** (le mouchard qui nous remonte les erreurs rencontrées par un vrai gestionnaire),
**lucide-react** (les icônes) et **Vite** (l'outil qui assemble l'application livrée). Les dix
autres ne servent qu'à nous : compilateur TypeScript, moteur de tests, navigateur simulé,
Storybook, Playwright.

**Ça apporte** : que des mises à jour d'entretien, aucune rupture. La plus notable est **Vite
8.1 → 8.2**, qui touche la fabrication du paquet livré — c'est celle qu'on surveille, parce qu'un
défaut là se voit chez tous les clubs à la fois et nulle part avant. Prendre ces mises à jour au fil
de l'eau évite le saut coûteux : c'est exactement ce qui bloque TypeScript 7 chez nous depuis des
mois, faute d'un écosystème qui suit.

**Adapté chez nous** : **rien**. Vérifié dans le conteneur d'outillage, jamais sur le poste — et
l'image a été **reconstruite avant** de tester, sans quoi on aurait validé une version périmée du
code (le piège de 2026-07-29). ESLint, la compilation TypeScript et les **1038 tests** passent ; le
paquet de production se construit et **ne grossit pas** (le fichier principal passe même de 274 à
271 ko).

### ⚠ Découvert pendant le lot, sans rapport avec les dépendances : un test qui rougit au hasard

`Engine Tests` — l'un des contrôles qui bloquent les fusions — est tombé sur la PR #504, **qui ne
touche pourtant pas le moteur**. Ce n'est ni un caprice ni la faute de la mise à jour : l'un de nos
tests se trompe.

Ce test vérifie qu'un gymnase n'accueille jamais deux équipes en même temps. Il travaille sur des
situations **tirées au hasard**, et il est tombé sur celle-ci : le gestionnaire a **épinglé
lui-même** deux équipes sur le même créneau, alors que ce créneau ne peut en accueillir qu'une. Le
moteur a fait ce qu'on lui a demandé — c'est une règle assumée du produit, l'épingle prime sur tout
(« il a le droit d'épingler, il a le droit de savoir »). Le test, lui, crie à l'erreur.

Conséquence concrète : **une fusion sur deux peut se retrouver bloquée sans raison**, selon les
situations tirées au sort ce jour-là. Suivi en **P4-81**, avec le correctif identifié (le patron
existe déjà dans le même fichier pour un test voisin).
