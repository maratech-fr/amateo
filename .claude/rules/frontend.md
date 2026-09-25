---
paths:
  - "frontend/**"
---

# Frontend — conventions & pièges (chargé quand frontend/ est touché)

> **Ce fichier ne remplace pas [`frontend/AGENTS.md`](../../frontend/AGENTS.md)** (frontières,
> routage, état serveur/client, primitives, a11y). Il porte **seulement ce qui, non
> su, rend un test VERT à tort** — parce que ces règles-là doivent être en contexte sans que
> personne ait à penser à les chercher.

- 🔴 **Le front n'invente JAMAIS une règle métier — il AFFICHE celle que le backend a calculée.**
  Toute logique qui répond « qu'est-ce qui s'applique / que fait le solveur / ce geste est-il
  permis » n'existe **qu'une fois**. Trois régimes, un seul interdit : **(1) le backend dit** —
  supprimer la redérivation, afficher la réponse (le défaut) ; **(2) miroir déclaré** — la
  duplication est assumée (réactivité sans aller-retour réseau), **déclarée en tête de fichier**
  ET gardée par un **test de parité** (patron : `CoachDoubleBookingDetector` ⇄
  `wizard/lib/coachDoubleBooking.ts` ; côté cross-stack `PayloadCapacityMirror` +
  `CapacityMirrorParityTest` ; côté cockpit `App\Service\HolidayWorkweekRule` ⇄
  `cockpit/lib/holidayWorkweek.ts`, parité `HolidayWorkweekMirrorParityTest`, D4 2026-09-04 ;
  `App\Service\WeekSegmentationRule` ⇄ `cockpit/lib/weekSegmentation.ts`, parité
  `WeekSegmentationMirrorParityTest`, découpage début·milieu·fin, 2026-09-05 ; côté matchs
  `MatchConflictDetector::kickoffInsideLeagueWindow` ⇄ `matches/lib/envelope.ts::
  kickoffInsideLeagueWindow` (enveloppe ligue, intervalle fermé sur le jour), parité
  `LeagueEnvelopeMirrorParityTest`/`leagueEnvelope.parity.test.ts`, FRT-32 2026-09-18) ;
  **(3) redérivation silencieuse** — ❌ interdite. Signe d'alerte :
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
  **Listbox** — sélecteur riche à choix unique (couleur/icône, compte, sous-ligne, option
  désactivée motivée), patron APG, P4-164 PR-1, maison des sélecteurs qui dépassent le `<select>`
  natif, recherche intégrée au panneau au-delà de 8 options réelles (P4-198) —, **StatusPill** — la pastille partagée, icône + texte, variantes warning/accent/neutral,
  P4-173 puis P4-177 —, VenueSwatch…) ; `SourceBadge` (AUTO/MANUEL) est désormais lui aussi une maison
  unique — `features/matches/SourceBadge.tsx` (P4-177, adossée à `StatusPill`), consommée par
  `TravelMatrixModal.tsx` et `OpponentsPage.tsx` (ex-`OpponentTravelCard.tsx`, absorbée le
  2026-09-19 — les deux copies locales d'origine ont disparu) ;
  `FilterToggle` (`shared/components/ui/filter-toggle.tsx`, P4-207) est la maison unique de la case
  à cocher d'un filtre d'affichage — née du patron inline de `ReviewQueue.tsx`, qui l'utilise
  désormais pour ses deux interrupteurs (« Afficher les traitées », « Masquer les extérieurs »,
  UXC-21, lot audit 2026-09-18) : plus aucune copie locale du patron ; sa sœur `FilterChip`
  (`shared/components/ui/filter-chip.tsx`, P4-216, 2026-09-22) est la maison unique de la PUCE de
  filtre `aria-pressed` bordée à compteur (icône optionnelle) — consommée par les chips
  « Familles » (Calendrier + Conflits) et « Traitement » (Conflits) de `features/matches`.
  N'absorbe QUE ce contrat exact : ni les interrupteurs `role="switch"` (sémantique a11y
  différente), ni les contrôles SEGMENTÉS (bordure portée par le conteneur, pas de compteur —
  types de compétition, période, « Regrouper par ») ; `snapshotFile`
  (`shared/lib/fileSnapshot.ts`, P3-7) est la maison unique du snapshot mémoire d'un `File` avant
  envoi — ferme le piège `ERR_UPLOAD_FILE_CHANGED` (fichier relu sur disque à l'envoi, déguisé en
  « Problème de connexion ») — consommée par `TeamsImportModal.tsx` et `ImportFbiDialog.tsx` ;
  **couleurs/espacements** = tokens du thème (`text-warning`,
  `text-muted-foreground`, `bg-muted`, `border-border`…), **jamais un `#hex`** ni une classe sans
  jeton (`text-warning-foreground` était un no-op, P4-130). `PRODUCT_ACCENT` (`shared/lib/product.ts`)
  est la SEULE maison d'un hex d'accent produit ; les valeurs statiques d'accent d'`index.css` sont
  la sortie EXACTE de sa dérivation, gardée par `src/test/accentTokenParity.test.ts` — **on ne les
  édite jamais à la main, on les recalcule** (`specs/courantes/identite-visuelle-produit.md`).
  `BrandIcon` (`shared/components/ui/brand-icon.tsx`) est la SEULE exception admise à « jamais un
  `#hex` » **en composant React** (ses trois arcs) ; les **SVG d'asset statiques de marque**
  (`public/brand/*.svg` — `favicon.svg`, `fond-light.svg`/`fond-dark.svg`, P5-16) en sont une
  seconde, pour la même raison : un fichier servi tel quel n'a pas de jeton de thème à consommer.
  `BrandMark` (`shared/components/ui/brand-mark.tsx`), le logo COMPLET
  (icône + mot) posé partout où le produit se nomme comme MARQUE (login/inscription, écrans
  système, console admin — jamais pour une mention dans une phrase), n'en porte aucune : le mot
  hérite `currentColor`, un seul ton dans tous les thèmes (un second ton teal codé en dur tombait
  sous la barre de contraste sur fond clair, retiré).
- 🔴 **Les racines de shell ne portent plus `bg-background` depuis le fond d'écran commun**
  (P5-16, `AppLayout.tsx`/`AuthLayout.tsx`) : le fond commun vit sur `body` (`index.css`), et une
  racine qui poserait `bg-background` par-dessus le masquerait entièrement. L'en-tête d'`AppLayout`
  et les cartes restent OPAQUES (`bg-background`/`bg-card` posés dessus, pas sur la racine) — le
  fond ne vit que dans les zones vides. Un écran qui veut au contraire un fond NU (déjà chargé
  visuellement, ou système) pose `bg-background` sur sa PROPRE section, pas sur un shell partagé —
  patron `GenerationScene.tsx` (racine `bg-background`, décor déjà dense) et `system-screen.tsx`
  (inchangé, hors lot).
  Recoder à la main un spinner nu, un
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
  le `--force` est requis (un `tsbuildinfo` périmé court-circuite le contrôle). `tests/e2e/` et
  `playwright.config.ts` sont désormais couverts eux aussi (`tsconfig.e2e.json`, référencé depuis
  le fichier solution racine, P4-257, 2026-09-25) — avant ce lot ils n'étaient couverts par
  **aucun** des deux autres projets (`tsconfig.app.json` n'`include` que `src`, `tsconfig.node.json`
  que `vite.config.ts`/`tooling`), si bien qu'un spec Playwright appelant une API inexistante
  passait le lint vert et ne se révélait qu'en CI.
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
  inert réussit sans rien lire. Les deux mentent. ⚠ Le même `inert` ment aussi à un `toBeVisible` —
  il ne teste QUE la présence dans le DOM/CSS, pas l'`inert`. Seule une **action pointeur** (un
  `click`) le révèle : Playwright la fait échouer avec « intercepts pointer events » sur l'overlay,
  puis timeout quand l'élément visé se détache au retrait du voile. D'où le patron
  `landOnMatchesCalendar` (`tests/e2e/support.ts`) : `settleVeil` encadre la SEULE action pointeur
  du helper, pas ses lectures `toBeVisible`.
- 🔴 **jsdom n'a AUCUN moteur de mise en page** : `boundingBox`, `scrollHeight` et
  `getBoundingClientRect` y valent 0. Le **contraste** et le **reflow** (WCAG 1.4.10) ne se testent
  qu'en **Playwright**. Un test jsdom sur ces sujets est vert par construction — il n'atteste rien.
  C'est pour ça que `frontend/src/test/textOpacityGuard.test.ts` (A11Y-22, 2026-09-18) existe : un
  garde STATIQUE (grep des sources `.tsx` sur `text-<jeton>/NN`/`opacity-[3-6]0`) qui rougit dans
  Vitest, avant le scan de contraste Playwright — sans lui une régression d'opacité sur du texte
  resterait verte jusqu'au prochain `a11y-contrast.spec.ts`.
- 🔴 **Un scan a11y authentifié sur `/matchs` ne peint RIEN si le club seedé CI n'a aucune
  `Fixture`** — `app:bccl:seed` ne pose que des `TeamMatchHabit`/`MatchSlotRotation`, jamais de
  rencontre : l'écran rend un `EmptyState` (« Aucun match importé »), le scan tourne sur du vide et
  son témoin rougit avant même d'atteindre le contraste. `tests/e2e/a11y-contrast.spec.ts`
  (2026-09-18) POSTe désormais son propre amical HOME placé avant de scanner, nettoyé en `finally`
  — patron à reprendre pour tout nouveau scan sur un écran sans donnée garantie par le seed ou
  l'onboarding. Chaque écran authentifié désigne en outre son PROPRE témoin de contenu rendu
  (grille week-end / région nommée / carte `[data-slot-id]` / `h1`) plutôt qu'un témoin global
  (`shadow-sm`/`role=region`/testid) — `/planning` (`WeekGrid`) ne porte AUCUN des trois, un témoin
  global le déclarait « vide » alors qu'il était peint.
- 🔴 **Le survol d'un fond `bg-accent` plein n'est jamais une `opacity`** — c'est le jeton
  `bg-accent-hover` (`--accent-hover`, `accentHoverForMode` dans `shared/lib/color.ts`, posé par
  `useApplyClubTheme` à côté de `--accent`/`--accent-foreground`). `hover:opacity-90` compositait
  l'accent OPAQUE vers la surface : en clair le fond s'éclaircissait pendant que le texte blanc
  restait blanc, cassant l'AA (blanc/accent 4,85 → 4,26, sous 4,5:1 — la puce « Amical » pressée de
  `/matchs`, 2026-09-18). `destructive` (`bg-destructive`) et l'avatar de `ClubPage` (`bg-muted`)
  restent sur `opacity-90`, délibérément hors scope (ex-P4-244, fermé sans correctif au triage
  roadmap 2026-09-25) — même piège à surveiller si un futur fond plein en hérite.
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
- **Superadmin : jamais de login dans une spec** — le projet Playwright `setup`
  (`tests/e2e/superadmin.setup.ts`) fige UNE session par run (`storageState`), réutilisée sans
  retry par le projet `superadmin` ; un login par spec, multiplié par les retries, brûle le
  quota `admin_auth` (5/15 min par IP) et déguise un 429 en régression produit (P4-201).
