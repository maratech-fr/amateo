---
paths:
  - "frontend/**"
---

# Frontend — conventions & pièges (chargé quand frontend/ est touché)

> **Ce fichier ne remplace pas [`frontend/AGENTS.md`](../../frontend/AGENTS.md)** (315 lignes :
> frontières, routage, état serveur/client, primitives, a11y). Il porte **seulement ce qui, non
> su, rend un test VERT à tort** — parce que ces règles-là doivent être en contexte sans que
> personne ait à penser à les chercher.

- 🔴 **Le front n'invente JAMAIS une règle métier — il AFFICHE celle que le backend a calculée.**
  Toute logique qui répond « qu'est-ce qui s'applique / que fait le solveur / ce geste est-il
  permis » n'existe **qu'une fois**. Trois régimes, un seul interdit : **(1) le backend dit** —
  supprimer la redérivation, afficher la réponse (le défaut) ; **(2) miroir déclaré** — la
  duplication est assumée (réactivité sans aller-retour réseau), **déclarée en tête de fichier**
  ET gardée par un **test de parité** (patron : `CoachDoubleBookingDetector` ⇄
  `wizard/lib/coachDoubleBooking.ts` ; côté cross-stack `PayloadCapacityMirror` +
  `CapacityMirrorParityTest`) ; **(3) redérivation silencieuse** — ❌ interdite. Signe d'alerte :
  un `switch`/chaîne de conditions sur les valeurs d'un **enum métier partagé** (`scope`,
  `ruleType`, `family`, `lockLevel`, `status`…) pour **décider d'un comportement** (pas pour
  choisir un libellé — ça, c'est de la présentation, cf. `matches/lib/diagnostic.ts`). Cas fondateur
  du **2026-08-12** : `applicableConstraints` faisait `case "CLUB": return true` alors que
  `ScheduleConstraintBuilder.php:846-870` éclate une `CLUB+targetTag` en N contraintes TEAM — le
  wrap affichait une règle sur une équipe à qui le solveur ne l'applique jamais.
  **Gardé depuis le 2026-08-12 par `FrontRederivationRegistryTest`** (CrossStack, groupe
  `contract`) : registre des miroirs déclarés (entrée sans parité → rouge) + détecteur des
  `switch` décideurs sur les enums de CONTRAINTE (module non déclaré → rouge, nommé). La largeur
  du détecteur est un CHOIX documenté (`POLICED_ENUMS`) ; le registre, lui, tient tous les miroirs.
- 🔴 **Réutiliser le PARTAGÉ avant d'écrire du neuf — jamais réinventer un élément qui existe déjà.**
  Avant de coder un état de chargement / vide / erreur, un bouton, une modale, un badge, une
  pastille, un formulaire : **chercher la primitive partagée** et l'utiliser telle quelle.
  Les maisons uniques (extensible) : **chargement** — `FullPageSpinner` (chargement de PAGE,
  standard `cockpit`/`planning`/`profile`/`club`), `Spinner` (inline, dans un bouton),
  `EmptyHint`/`EmptyBlock`/`EmptyState` (vide), `LoadErrorHint`+`readState` (échec de lecture avec
  retry), `ActionVeil` (voile de navigation/sauvegarde global — `app/ActionVeil.tsx`) ;
  **primitives** `shared/components/ui/*` (Button, Modal, Select, Input, Card, StepRail, Menu APG,
  SourceBadge, VenueSwatch…) ; **couleurs/espacements** = tokens du thème (`text-warning`,
  `text-muted-foreground`, `bg-muted`, `border-border`…), **jamais un `#hex`** ni une classe sans
  jeton (`text-warning-foreground` était un no-op, P4-130). Recoder à la main un spinner nu, un
  encart d'erreur, une pastille inline **là où la primitive existe** = incohérence UX (« même
  chose, au même endroit, de la même façon » — famille UXC de l'audit). Cas fondateur du
  **2026-08-28** : `MatchesPage` rendait un `<Spinner>` nu dans un `py-16` (demi-page) là où ses
  4 pages sœurs utilisent `FullPageSpinner` — ramené sur la primitive. Si la primitive **manque**,
  l'AJOUTER au partagé (une seule maison), pas en faire une variante locale.
- 🔴 **L'image tooling COPIE le code — la rebâtir AVANT tout test**, sinon la suite valide une
  version périmée et passe : `docker compose --profile tools build frontend-tooling`
  (`make -C frontend install` le fait). **Deux faux verts dans la même session le 2026-08-11.**
  ⚠ **`docker compose --profile tools run frontend-tooling …` NE rebâtit PAS** — `run` démarre
  l'image telle qu'elle est. C'est le piège : la commande a l'air de « lancer les tests sur le
  code », elle les lance sur la dernière image CUITE. Repris une 3ᵉ fois le 2026-08-21.
  **Le signal qui le trahit, à connaître par cœur** : vous ajoutez N tests, et le total
  affiché ne bouge pas (« Tests 119 passed » avant ET après en avoir écrit deux). Un compte
  INCHANGÉ après un ajout ne veut jamais dire « mes tests passent » — il veut dire **« mes tests
  n'existent pas dans l'image »**. Même chose pour une correction de source : un test qui reste
  rouge/vert *à l'identique* après une modification qui aurait dû le retourner accuse l'image,
  pas le code. Lisez le COMPTE avant de lire le verdict.
- 🔴 **Le service `frontend` (Nginx :8081) sert un `dist` CUIT dans son image** — pas de bind
  mount. Un `vite build` dans le conteneur tooling est jeté. Avant un e2e qui doit voir ta
  modification : `docker compose build frontend && docker compose up -d --force-recreate frontend`.
  Seul `frontend-dev` (profil `dev`, :5173) monte `./frontend` — c'est le hot-reload, pas la cible
  des e2e.
- 🔴 **Jamais `tsc --noEmit`** : le `tsconfig.json` racine est un fichier *solution*
  (`"files": []` + `references`), donc `--noEmit` voit **zéro fichier**, sort 0 sans rien vérifier,
  et la CI (`tsc -b`) échoue sur ce qu'il a sauté. `make -C frontend lint` fait `tsc -b --force` —
  le `--force` est requis (un `tsbuildinfo` périmé court-circuite le contrôle).
- 🔴 **axe SAUTE un sous-arbre `inert` — un scan d'a11y sur un écran voilé ne vérifie RIEN.**
  Découvert le 2026-08-21 en différant le blocage du voile (lot C) : le scan de contraste
  « wizard · gymnases » tournait pendant que le voile rendait le contenu `inert`, donc axe ne
  regardait aucun élément de cet écran — **vert, et vide**. Le voile n'est que le cas le plus
  récent : toute modale, tout `inert`, tout `aria-hidden` posé le temps d'un chargement produit le
  même faux vert. **Avant un scan axe, attendre que l'écran soit RENDU À L'UTILISATEUR** (helper
  `settleVeil` dans `tests/e2e/support.ts`). ⚠ Corollaire déjà vérifié : la couleur mesurée en
  pleine transition n'est pas la couleur finale — la surbrillance d'étape passait par un
  `text-muted-foreground` sur `bg-muted` à **3,93** avant de se poser sur sa vraie valeur AA. Un
  scan trop tôt échoue pour une couleur qui n'existe qu'un instant ; un scan sur un sous-arbre
  inert réussit sans rien lire. Les deux mentent.
- 🔴 **jsdom n'a AUCUN moteur de mise en page** : `boundingBox`, `scrollHeight` et
  `getBoundingClientRect` y valent 0. Le **contraste** et le **reflow** (WCAG 1.4.10) ne se testent
  qu'en **Playwright**. Un test jsdom sur ces sujets est vert par construction — il n'atteste rien.
- **TDD obligatoire**, RED prouvé avant l'implémentation
  ([`../../frontend/docs/frontend-strategy.md`](../../frontend/docs/frontend-strategy.md) §1).
- **Passe de design `ui-ux-pro-max`** (dans un agent — elle ne MESURE rien, mais elle TRANCHE une
  décision contre son corpus) dès qu'un écran naît, change d'apparence, **ou qu'une décision
  d'INTERACTION est arrêtée** : ce qui bloque, ce qui attend, ce qui prend le focus, ce qui
  s'annonce à un lecteur d'écran, ce dont on peut sortir. **Public ET interne.** Se lance **AVANT**
  que la décision soit figée — même doc, règle du 2026-08-11 **élargie le 2026-08-21** (le lot C a
  falsifié les deux bornes d'origine : écran interne, défauts non visuels).
- **Muter la PROD, pas le mock** : un test qui n'exerce que son double ne garde rien.
  `readState`/`PeriodAnchor` pour react-query (« vacuité crédible » — `AGENTS.md` §readState).
- **Tout tourne dans Docker**, frontend compris : les 12 cibles de `frontend/Makefile` passent par
  `docker compose`, sans exception. Tester sur l'hôte valide une version de Node qui n'est celle de
  personne.
- ⚠ **Un e2e qui passe sans avoir rien mis à l'épreuve est un faux vert** : quand un scénario peut
  devenir vide (une modale trop courte pour déborder, une liste vide), lui donner un **témoin** qui
  ÉCHOUE en le disant — cf. `tests/e2e/modal-reachability.spec.ts`.
