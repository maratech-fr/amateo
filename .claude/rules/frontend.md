---
paths:
  - "frontend/**"
---

# Frontend — conventions & pièges (chargé quand frontend/ est touché)

> **Ce fichier ne remplace pas [`frontend/AGENTS.md`](../../frontend/AGENTS.md)** (frontières,
> routage, état serveur/client, primitives, a11y). Il porte **seulement ce qui, non su, rend un test
> VERT à tort** — ces règles-là doivent être en contexte sans que personne ait à les chercher.
>
> Trois parties : (1) les pièges qui rendent un test vert à tort ; (2) les normes d'écran — celles
> gardées par un test tiennent en deux lignes (règle, foyer, garde), celles qui n'ont aucune garde
> restent complètes ; (3) le rôle Membre. Une norme d'écran nouvelle ne naît plus avec sa garde
> statique : une garde statique neuve n'entre que pour la sécurité ou un geste destructeur.

## 1. Pièges qui rendent un test vert à tort

- 🔴 **L'image tooling COPIE le code — la rebâtir AVANT tout test**, sinon la suite valide une
  version périmée et passe : `docker compose --profile tools build frontend-tooling`
  (`make -C frontend install` le fait). ⚠ `docker compose --profile tools run frontend-tooling …`
  NE rebâtit PAS : `run` démarre la dernière image CUITE. **Le signal qui le trahit** : vous ajoutez
  N tests et le total affiché ne bouge pas. Un compte INCHANGÉ après un ajout veut dire « mes tests
  n'existent pas dans l'image », jamais « mes tests passent » ; de même, un test qui reste rouge/vert
  à l'identique après une modification qui aurait dû le retourner accuse l'image, pas le code.
  Lire le COMPTE avant de lire le verdict.
- 🔴 **Le service `frontend` (Nginx :8081) sert un `dist` CUIT dans son image** — pas de bind mount.
  Un `vite build` dans le conteneur tooling est jeté. Avant un e2e qui doit voir ta modification :
  `docker compose build frontend && docker compose up -d --force-recreate frontend`. Seul
  `frontend-dev` (profil `dev`, :5173) monte `./frontend` : hot-reload, pas la cible des e2e.
- 🔴 **Jamais `tsc --noEmit`** : le `tsconfig.json` racine est un fichier *solution* (`"files": []` +
  `references`), donc `--noEmit` voit **zéro fichier**, sort 0 sans rien vérifier, et la CI (`tsc -b`)
  échoue sur ce qu'il a sauté. `make -C frontend lint` fait `tsc -b --force` — le `--force` est requis
  (un `tsbuildinfo` périmé court-circuite le contrôle). `tests/e2e/` et `playwright.config.ts` sont
  couverts par `tsconfig.e2e.json`, référencé depuis le fichier solution.
- 🔴 **axe SAUTE un sous-arbre `inert` — un scan d'a11y sur un écran voilé ne vérifie RIEN**
  (vert, et vide) ; idem pour toute modale, tout `inert`, tout `aria-hidden` posé le temps d'un
  chargement. **Avant un scan axe, attendre que l'écran soit RENDU À L'UTILISATEUR** (`settleVeil`,
  `tests/e2e/support.ts`). ⚠ Un scan trop tôt mesure la couleur d'une transition, qui n'existe qu'un
  instant : il échoue pour rien. ⚠ `inert` ment aussi à `toBeVisible` (présence DOM/CSS seulement) :
  seule une **action pointeur** (`click`) le révèle (« intercepts pointer events », puis timeout).
  Patron `landOnMatchesCalendar` (`support.ts`) : `settleVeil` encadre la SEULE action pointeur du
  helper, pas ses lectures `toBeVisible`.
- 🔴 **jsdom n'a AUCUN moteur de mise en page** : `boundingBox`, `scrollHeight` et
  `getBoundingClientRect` y valent 0 (ni `matchMedia`). Le **contraste** et le **reflow** (WCAG
  1.4.10) ne se testent qu'en **Playwright** ; un test jsdom sur ces sujets est vert par
  construction. D'où les gardes statiques `textOpacityGuard.test.ts` (grep `.tsx` sur
  `text-<jeton>/NN`, `opacity-[3-6]0`) et `motionReduceGuard.test.ts`, qui rougissent dans Vitest
  avant le scan Playwright.
- 🔴 **Un scan a11y authentifié sur un écran sans donnée ne peint RIEN** : le club seedé CI n'a
  aucune `Fixture`, donc `/matchs` rend un `EmptyState` et le scan tourne sur du vide.
  `tests/e2e/a11y-contrast.spec.ts` POSTe son propre amical HOME avant de scanner, nettoyé en
  `finally` — patron pour tout écran sans donnée garantie par le seed. Chaque écran authentifié
  désigne son PROPRE témoin de contenu rendu (grille week-end / région nommée / carte
  `[data-slot-id]` / `h1`), pas un témoin global (`/planning` n'en porte aucun des globaux).
- ⚠ **Un e2e qui passe sans avoir rien mis à l'épreuve est un faux vert** : quand un scénario peut
  devenir vide (modale trop courte pour déborder, liste vide), lui donner un **témoin** qui ÉCHOUE
  en le disant — cf. `tests/e2e/modal-reachability.spec.ts`.
- ⚠ **Une spec e2e qui pose une date calendaire EN DUR rougit dès que l'horloge la dépasse.**
  Calculer la fenêtre depuis `new Date()`, et l'écarter des vacances scolaires quand le geste en
  dépend (`ClosureSegmentation` retire les semaines de vacances avant de découper début·milieu·fin).
  Helpers uniques, jamais réécrits spec par spec : `tests/e2e/support.ts` (`firstHolidayFreeMonday`,
  `schoolHolidayWindows` — feed serveur `GET /api/school-holidays`, jamais un calendrier recopié —,
  `isoLocal`, `frMonthLabel`). `matches.spec.ts` garde un helper LOCAL `futureSaturday` : la vue
  Semaine ne navigue que sur les week-ends qui PORTENT une rencontre.
- **Muter la PROD, pas le mock** : un test qui n'exerce que son double ne garde rien
  (`readState`/`PeriodAnchor` pour react-query — `AGENTS.md` §readState). **Un mock envoie ce que
  l'API envoie** : un champ de date mocké respecte le format servi (`*Date`/`*Deadline` → `Y-m-d`,
  `*At` → RFC 3339), gardé par `backend/tests/CrossStack/FrontMockDateFormatMatchesContractTest.php`
  ([`testing-strategy.md`](../../docs/testing/testing-strategy.md) §2).
- **Superadmin : jamais de login dans une spec** — le projet Playwright `setup`
  (`tests/e2e/superadmin.setup.ts`) fige UNE session par run (`storageState`), réutilisée sans retry
  par le projet `superadmin` ; un login par spec, multiplié par les retries, brûle le quota
  `admin_auth` (5/15 min par IP) et déguise un 429 en régression produit.
- **Tout tourne dans Docker**, frontend compris : toutes les cibles de `frontend/Makefile` passent par
  `docker compose`. Tester sur l'hôte valide une version de Node qui n'est celle de personne.

## 2. Normes d'écran

### Normes écrites en entier ici : aucune garde, ou une garde partielle, ne les tient

- 🔴 **N1 : Enregistrer/Annuler d'une ligne en édition = TEXTE, jamais une icône seule** — patron
  `IdealSlotsEditor` (boutons `Enregistrer`/`Annuler` en texte) et le bouton d'enregistrement de
  `ConstraintsStep`.
- 🔴 **La raison d'une désactivation doit être DÉCOUVRABLE au clavier/lecteur d'écran, pas qu'au
  survol** : un `<button disabled>` natif sort de l'ordre de tabulation, son `title`/
  `aria-describedby` ne sont jamais annoncés. Quand un bouton est désactivé POUR UN MOTIF, passer la
  raison en **prop `disabledReason`** de `Button` (`shared/components/ui/button.tsx`) : `aria-disabled`
  (focalisable), `title` (souris) + `aria-describedby`→`<span sr-only>` (clavier/AT), clic neutralisé ;
  garder `disabled` à côté pour la condition. Un `title={cond ? raison : undefined}` sur un bouton
  `disabled` est l'anti-patron. Exception : raison déjà en CLAIR à côté (texte visible,
  `WeekPickerDialog`). Hors portée : `<select>`/`<option>`/`<input>` désactivés.
- 🔴 **Hauteur d'un contrôle de ligne = 36 px (`h-9`), variante dense NOMMÉE, jamais une classe forcée** :
  `Input`/`Select` rendent `h-9`, `Button` `default`/`icon` restent à 40 px (CTA pleine page, pied de
  modale), une ligne dense prend `size="sm"` ou `size="icon-sm"` ; `compact` (`h-8`, prop d'`Input`/
  `Select`) est réservée aux TABLEAUX denses, jamais mélangée à du `h-9` dans la même ligne.
  Les `<textarea>` passent par `FIELD_CLASS` (`shared/components/ui/field.ts`). ESLint
  (`frontend/eslint.config.js`, `no-restricted-syntax`, `src/features/**` + `src/app/**`, admin
  exempté) refuse une classe `h-7`/`h-8`/`h-10`/`h-11` LITTÉRALE en `className` sur
  `Button`/`Input`/`Select`/`Listbox`/`TeamSelect`/`VenueSelect` ; un `cn(...)`/variable/template
  literal échappe à l'AST — reste à la revue. **Ligne d'ajout/édition dont l'action ne tient pas sur
  une ligne à 1280 px → DEUX rangées** (jamais un bouton d'action orphelin tombé par `flex-wrap`) :
  rangée 1 = la sélection, rangée 2 = les valeurs + le bouton d'action à droite (patron
  `RuleFields`/`CoachFields` de `features/matches/ConstraintsPage.tsx`) ; pas de NR e2e, la mesure en
  pixels n'est pas automatique.
- 🔴 **Un bouton d'AJOUT porte un libellé VISIBLE** (garde : `addButtonLabelGuard.test.ts`, qui ne
  voit que `size="icon"`/`"icon-sm"` + `aria-label` « Ajouter … »). Patron `ConstraintsPage`
  (`<Button size="sm"><Plus className="size-3.5" />Ajouter …</Button>`, pas d'`aria-label` redondant),
  `variant="ghost"` sur une ligne de tableau (`OpponentsPage`). Ligne de TABLEAU à tenir à 360 px : libellé
  masqué VISUELLEMENT sous le palier conteneur (`<span className="sr-only @md:not-sr-only">`), nom
  accessible conservé. Rangée de FORMULAIRE `flex` sans `flex-wrap` qui déborde (patron `VenuesStep`) :
  texte visible raccourci à un verbe (« Ajouter ») + complément dans un `<span className="sr-only">`
  PERMANENT, bouton `shrink-0`. ⚠ jsdom rogne l'espace de tête du span sr-only : intercaler `{" "}`
  entre le libellé court et le span, sinon le nom sort collé (« Ajouterun gymnase »).
- 🔴 **Un changement d'apparence VOULU sur un des 9 écrans de `visual-reference.spec.ts` (login,
  register, planning semaine, matchs calendrier/semaine type/contraintes club, club, wizard
  coachs/contraintes) exige une re-baseline** — procédure et commande unique :
  `docs/testing/testing-strategy.md` §1. Un rouge `toHaveScreenshot` sans changement d'apparence voulu
  est une régression, pas une image à régénérer.
- 🔴 **Les racines de shell ne portent pas `bg-background`** : le fond commun vit sur `body`
  (`index.css`), une racine qui poserait `bg-background` le masquerait. L'en-tête d'`AppLayout` et les
  cartes restent OPAQUES (`bg-background`/`bg-card` posés dessus). Un écran qui veut un fond NU pose
  `bg-background` sur sa PROPRE section (patron `GenerationScene.tsx`, `system-screen.tsx`).
- 🔴 **Le survol d'un fond `bg-accent` plein n'est jamais une `opacity`** : c'est `bg-accent-hover`
  (`--accent-hover`, `accentHoverForMode` dans `shared/lib/color.ts`, posé par `useApplyClubTheme`).
  `hover:opacity-90` composite l'accent vers la surface : le fond s'éclaircit, le texte blanc reste
  blanc, l'AA casse (blanc/accent 4,85 → 4,26). `bg-destructive` et l'avatar de `ClubPage` (`bg-muted`)
  restent sur `opacity-90` — même piège si un futur fond plein en hérite.
- 🔴 **Tout « aujourd'hui » passe par `shared/lib/clock.ts`, jamais `new Date()`** — seule maison qui
  compose l'override dev (`?today=`) et l'horloge simulée SERVEUR d'un club démo (`/api/me` →
  `club.simulatedToday`, décidée côté back par `App\Clock\ClubClock`). Un composant qui LIT la date
  **en rendu** utilise `useTodayISO()`/`useTodayDate()` (réactifs : ils se recalent quand la date
  serveur arrive après le premier rendu) ; `todayISO()`/`todayDate()` pour une lib pure hors rendu.
- 🔴 **Le front n'invente JAMAIS une règle métier — il AFFICHE celle que le backend a calculée.**
  Toute logique qui répond « qu'est-ce qui s'applique / que fait le solveur / ce geste est-il permis »
  n'existe **qu'une fois**. Trois régimes : **(1) le backend dit** — supprimer la redérivation,
  afficher la réponse (le défaut) ; **(2) miroir déclaré** — duplication assumée (réactivité sans
  aller-retour), **déclarée en tête de fichier** ET gardée par un **test de parité** (patron :
  `CoachDoubleBookingDetector` ⇄ `wizard/lib/coachDoubleBooking.ts` ; cross-stack
  `PayloadCapacityMirror` + `CapacityMirrorParityTest`) ; **(3) redérivation silencieuse** — ❌
  interdite. Signe d'alerte : un `switch`/chaîne de conditions sur les valeurs d'un **enum métier
  partagé** (`scope`, `ruleType`, `family`, `lockLevel`, `status`…) pour **décider d'un
  comportement** (pas pour choisir un libellé, ça c'est de la présentation, cf.
  `matches/lib/diagnostic.ts`). Garde : `FrontRederivationRegistryTest` (CrossStack, groupe
  `contract`) — son registre (`backend/tests/CrossStack/FrontRederivationRegistryTest.php`) porte
  TOUS les miroirs front déclarés (fichier front, règle décidée, vérité backend, test de parité ;
  entrée sans parité → rouge) et un détecteur de `switch` décideurs sur les enums de CONTRAINTE
  (module non déclaré → rouge) dont la largeur est un CHOIX documenté (`POLICED_ENUMS`).

### Gardées par un test (règle · foyer · garde)

- 🔴 **Réutiliser le PARTAGÉ avant d'écrire du neuf** : chercher la primitive avant de coder un état
  de chargement / vide / erreur, un bouton, une modale, un badge, une pastille, un formulaire ; si
  elle **manque**, l'AJOUTER au partagé (une seule maison), jamais une variante locale. Recoder à la
  main un spinner nu, un encart d'erreur, une pastille inline là où la primitive existe est une
  incohérence (une page de liste rendait un `<Spinner>` nu en demi-page là où ses sœurs utilisent
  `FullPageSpinner`). **Le catalogue des primitives vit dans
  [`frontend/docs/frontend-components.md`](../../frontend/docs/frontend-components.md) §3**
  (maison unique). Contrats à connaître ici : **jamais rendre un échec de lecture comme du vide** —
  `LoadErrorHint` + `readState`, gardé par `frontend/src/test/readStateGuard.test.ts` (liste
  nominative des pages de route de `app/routes.tsx`) ; `Spinner` inline / `FullPageSpinner` de page ;
  `snapshotFile` (`shared/lib/fileSnapshot.ts`) fige un `File` en mémoire avant envoi
  (`ERR_UPLOAD_FILE_CHANGED` déguisé en « Problème de connexion »).
- 🔴 **Couleurs = tokens du thème** (`text-warning`, `text-muted-foreground`, `bg-muted`,
  `border-border`…), jamais un `#hex` ni une classe sans jeton. `PRODUCT_ACCENT`
  (`shared/lib/product.ts`) est la SEULE maison d'un hex d'accent produit ; les accents statiques
  d'`index.css` sont sa sortie EXACTE — **on ne les édite pas à la main, on les recalcule** — gardé par
  `src/test/accentTokenParity.test.ts` (`specs/courantes/identite-visuelle-produit.md`). Exceptions au
  « jamais un `#hex` » : `BrandIcon`, `BrandSplash`, les SVG statiques `public/brand/*.svg` ; `BrandMark`
  (logo complet : login, écrans système, console admin — jamais une mention dans une phrase) n'en
  porte aucune, le mot hérite `currentColor`.
- 🔴 **Tout écran principal rend `PageHeader`** (`shared/components/ui/page-header.tsx`), jamais un
  `<h1` brut ; « Signaler » toujours visible. Garde : `pageHeaderGuard.test.ts`. Une primitive
  `shared/` ne remonte jamais vers une feature (le canal feedback vit dans `shared/feedback/`).
- 🔴 **Heure = « 21:00 », durée = « 1h30 » — jamais fabriquées à la main** : heure par
  `shared/lib/time.ts::formatMinutes`, durée de créneau par `shared/lib/duration.ts::formatDuration`
  (forme compacte). Garde : `timeFormatGuard.test.ts` (aucune exemption). Hors portée : durées
  écoulées/cumulées de la console superadmin et des gymnases, exemples des bulles d'aide.
- 🔴 **Toute suppression passe par une confirmation, jamais un clic direct (N2)** — `ConfirmDialog` /
  `DeleteConfirm`. Garde : `deleteConfirmGuard.test.ts` (un fichier de `src/features/**` qui lie ET
  invoque un `useDelete…` sans `ConfirmDialog`/`DeleteConfirm` rougit ; exemptions nominatives
  motivées dans le test). Reste immédiat : retirer une pastille de LIAISON coach/joueur↔équipe
  (`CoachesStep`).
- 🔴 **Tout bandeau d'information = `NoticeBanner`** (`shared/components/ui/notice-banner.tsx`), jamais
  une boîte faite main. Garde : `bannerPrimitiveGuard.test.ts` (`src/features/**` et `src/shared/**`
  hors admin).
- 🔴 **Toute pastille d'état = `StatusPill`** (`shared/components/ui/badge.tsx`), jamais un `<span>`
  arrondi recodé. Garde : `pillPrimitiveGuard.test.ts` (forme `rounded-full text-xs` + classe de TON
  sur une ligne ; les pastilles neutres `bg-muted`/`border-border` échappent à la garde, la revue les
  tient ; exemptions nominatives dans le test). Hors portée délibérée : les quatre indices de chargement restés en TEXTE
  (`ClubPage` § statistiques et § offres, `PeriodTeams`, `PeriodVenues`).
- 🔴 **Un `onError` de mutation (toast d'erreur) vit UNE fois, au NIVEAU HOOK** (`useMutation({
  onError })`), jamais passé à `mutate(vars, { onError })` : le filet global `MutationCache.onError`
  (`shared/lib/queryClient.ts`) ne se désarme que sur le `onError` du hook, un toast posé au
  `mutate()` en fait DEUX. Les messages SERVEUR passent par `errorMessage()`. Garde :
  `mutateOnErrorToastGuard.test.ts` (un `onError` de `.mutate(`/`.mutateAsync(` dont le corps
  référence `toast.` rougit ; un `onError` qui pose de l'état local ou appelle un handler NOMMÉ n'est
  pas attrapé ; exemptions nominatives motivées dans le test).
- 🔴 **La largeur d'un sélecteur passe par `wrapperClassName`, jamais `className`** (`Select`,
  `Listbox`, `TeamSelect`, `VenueSelect`) : le contrôle intérieur est toujours `w-full`, une classe de
  largeur/flex en `className` le vise, pas la boîte que la ligne flex mesure. Garde : ESLint
  (`frontend/eslint.config.js`, `no-restricted-syntax`, `src/features/**` + `src/app/**`, admin
  exempté) — classe de largeur **littérale** sur ces composants, et `<select>` natif brut (utiliser
  `Select`/`TeamSelect`/`VenueSelect`) ; un `cn(...)`/variable échappe à l'AST, reste à la revue.
- 🔴 **Une équipe « en entente (CTC) » porte `CtcMark`** (`shared/components/ui/ctc-mark.tsx`) DEVANT son
  nom partout où il s'affiche comme étiquette (sélecteurs via `TeamSelect`) ; exclus : exports, pages
  publiques à token, phrases/toasts/aria (nom nu), console admin. Garde : `ctcMarkGuard.test.ts`
  (liste nominative `CTC_SURFACES` + balayage de complétude sur `teamLabel`).
- 🔴 **Un contrôle segmenté = `SegmentedControl`** (`shared/components/ui/segmented-control.tsx`), jamais
  un conteneur `bg-card p-0.5` fait main. Garde : `segmentedControlGuard.test.ts` (`src/features/**` et
  `src/app/**`, admin exempté).
- **A11Y : un bouton-icône = `<Button variant="ghost" size="icon-sm">`** (cible 36 px, WCAG 2.5.8),
  jamais un `<button>` brut `p-1` autour d'une icône. Garde : `iconTargetSizeGuard.test.ts` (liste
  nominative `SITES` : un site renommé = mise à jour mécanique, jamais suppression) ; l'en-tête de
  `Modal` : `shared/components/ui/modal-signaler.test.tsx`.
- **Textes visibles du flux de retouche : « le système », jamais « moteur »/« solveur »**
  (`docs/glossary.md`). Garde : `engineWordGuard.test.ts` (fichiers nommés dans le test).
- 🔴 **Un bouton d'action en LISTE porte un nom accessible CONTEXTUALISÉ** (`aria-label={`Supprimer
  l'équipe ${name}`}`), jamais un verbe nu répété. Garde : `genericAccessibleNameGuard.test.ts`
  (`aria-label` littéral « Supprimer »/« Modifier »/« Retirer »/« Éditer » nu).
- 🔴 **Tout choix de jours = `DayMultiPicker`** (`shared/components/ui/day-multi-picker.tsx`), **tout
  libellé de jour = `shared/lib/days.ts`** (`DAYS`, `dayLabelShort`, `dayLabelLong`,
  `dayLabelLongCap`). Garde : `dayPickerGuard.test.ts` (triplet de jours consécutifs littéraux hors
  `days.ts`). Contrat `markedDays` : `markedDays` + `markedReason` REPÈRENT des jours (bordure
  pointillée `warning`, `aria-describedby` + `title` = motif) sans toucher sélection ni clic ; sans
  `markedReason`, rien n'est marqué ; un jour désactivé n'est jamais repéré.
- 🔴 **Un écran MIGRÉ affiche ses textes depuis le `texts.ts` de sa feature — jamais un littéral FR en
  dur** : `features/<feature>/texts.ts` (scission `texts/<écran>.ts` au-delà de ~300 lignes ; console
  superadmin : `features/admin/texts/<module>.ts`), `export const <FEATURE>_TEXTS = { … } as const
  satisfies Record<string, string | ((...args: never[]) => string)>`, clés camelCase par INTENTION,
  interpolation par fonctions typées. **JAMAIS comme valeur** : un libellé de jour
  (→ `shared/lib/days.ts`), une heure/durée (→ `time.ts`/`duration.ts`), la marque
  (→ `shared/lib/product.ts`), un identifiant interne, une DONNÉE servie par le serveur. Pas de lib
  i18n (une typo de clé = erreur `tsc`). Un libellé dérivé d'un enum métier passe par une fonction
  typée du module, table clé→libellé `as const` INLINE. Garde : `hardcodedTextGuard.test.ts` (liste
  nominative `MIGRATED_FILES` : nœud texte JSX littéral ≥ 3 lettres ou littéral FR dans un attribut
  de texte rougit ; `texts.ts` hors portée). **`MIGRATED_FILES` est gelée** : la liste ne grandit
  plus, un écran qui y figure reste policé. Côté test : viser la CLÉ
  (`getByText(PLANNING_TEXTS.evictTitle)`), jamais la phrase recopiée ; nouvelle assertion → viser
  d'abord `getByRole(…, { name })`.
- 🔴 **Toute surface qui porte du texte est opaque** : `bg-card` ou un jeton
  `--surface-warning|accent|destructive|muted` (`index.css`), jamais une teinte `bg-<jeton>/NN` au
  REPOS (deux `background-color` Tailwind ne se composent pas, le fond à motifs traverse) ; la
  surbrillance garde `/NN` préfixée (`hover:bg-accent/10`). **Le texte sur surface teintée reste
  `text-foreground`**. Un **contrôle de saisie partagé** (champ, sélecteur, zone de texte) est plus
  strict : `bg-transparent` au repos y tombe aussi (placeholder illisible sur le fond à motifs).
  Garde : `surfaceOpacityGuard.test.ts` (`shared/components/ui/*.tsx` ; liste `INPUT_CONTROL_FILES` ;
  exemptions nominatives dans le test).
- 🔴 **Toute classe `animate-*` porte `motion-reduce:animate-none`, tout `.css` qui anime a un volet
  `prefers-reduced-motion`** (WCAG 2.3.3). Garde : `motionReduceGuard.test.ts` (exemption nominative
  `TSX_EXEMPTIONS` pour un site borné AUTREMENT, p. ex. JS `matchMedia`).
- 🔴 **Aucune chaîne n'est injectée comme HTML, et `@tiptap/*` ne sort pas de
  `features/admin/releaseNoteEditor/`** : `shared/components/ui/rich-text.tsx` construit des éléments
  React, jamais `dangerouslySetInnerHTML`, `.innerHTML =`, `getHTML(`, `srcDoc` hors `sandbox=""`.
  Garde : `noInnerHtmlGuard.test.ts` (zéro exemption). Le bleu des notes = jeton `text-info`, gardé AA
  par `a11y-contrast.spec.ts`. → `docs/security/rich-content.md`.
- **Un test front s'écrit pour ce que seul l'écran porte : un geste, une donnée affichée, un état lié
  à une règle métier, tout bug corrigé côté écran — jamais pour une classe, une couleur, un libellé ou
  un placement.** RED avant l'implémentation
  ([`frontend-strategy.md`](../../frontend/docs/frontend-strategy.md) §1) ; un composant de pure
  présentation naît sans test, prouvé par capture.
- **Passe de design `ui-ux-pro-max`** (skill lancé par l'agent `switch` : elle ne MESURE rien, elle TRANCHE contre son
  corpus) dès qu'un écran naît, change d'apparence, **ou qu'une décision d'INTERACTION est arrêtée**
  (ce qui bloque, ce qui attend, ce qui prend le focus, ce qui s'annonce à un lecteur d'écran, ce dont
  on peut sortir). **Public ET interne.** Se lance **AVANT** que la décision soit figée.

## 3. Rôle Membre — écrans en consultation

Un Membre lit tout, ne modifie rien : le serveur refuse (403), l'écran n'offre même pas le geste.
Primitives dans `src/shared/session/` et `shared/components/ui/`, jamais réécrites écran par écran :

- `useCanManage()` — vrai pour un gestionnaire dont l'adhésion est active ; miroir d'AFFICHAGE
  (`isManagementRole`), le serveur reste seul juge.
- `ManagerOnly` — masque ses enfants (absents du DOM, jamais grisés) pour un Membre : boutons
  d'écriture, « Copier le lien », relance, envoi.
- `ReadOnlyNotice` — bandeau « Consultation : réservé aux gestionnaires » (texte dans
  `SESSION_TEXTS`) sur chaque écran de SAISIE ; pas sur les écrans de lecture (Accueil, Planning,
  Consulter, Conflits…).
- `ReadOnlyFieldset` (`ui/read-only-fieldset.tsx`) — `<fieldset disabled>` pour les zones de saisie
  PURES, sans grisage ; filtres, onglets, tri, « voir » et export restent HORS du fieldset.
- `ClubClosingBanner` (dans `AppLayout`) — date d'effacement d'un club sans gestionnaire
  (`me.club.closingOn`) ; porte le seul geste « Demander à devenir gestionnaire » et l'état de la
  demande (`me.managerRequest`, textes `SESSION_TEXTS`). Sur `/club` la carte « Mon rôle » le porte
  et le bandeau ne le répète pas. Pastille « Demande gestionnaire » de `MembersSection` :
  Accepter = `useChangeMemberRole(id, "admin")` (le sélecteur), Refuser = `…/manager-request/refuse`.
- `SuccessorPicker` (`features/profile/`) — le SEUL gestionnaire nomme un successeur depuis la Zone
  danger du profil (avertissement = `me.soleManagerClubs`, jamais redérivé ; liste des Membres lue
  seulement si `useCanManage()` : jamais de 403 pour un Membre sur `/profile`).
- `ClubApprovalPage` (page publique d'approbation) : textes dans `features/auth/texts.ts`, variantes
  par `kind` (`create` / `reprise` / `promotion`) ; un lien `superseded` n'y dit pas « refusée ».
- ⚠ **Chaque écran lit `useCanManage` lui-même** : jamais d'état de rôle passé en prop depuis un
  parent, jamais un garde global — un écran oublié rend un 403 dans un toast, c'est le signal.
- Un Membre ne déclenche aucun appel sortant : les requêtes FFBB, géocodage et contrôle de
  localisation d'un gymnase sont désarmées (`enabled: canManage`) ; aucun littéral de texte nouveau
  hors `SESSION_TEXTS` / `WIZARD_TEXTS`.
- La preuve est le spec e2e `tests/e2e/member-read-only.spec.ts` (aucun garde statique) : tout
  nouvel écran d'écriture s'y ajoute.
