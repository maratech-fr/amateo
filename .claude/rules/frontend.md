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
  retry — **jamais rendre un échec comme du vide** ; gardé contre la Nᵉ récidive par
  `frontend/src/test/readStateGuard.test.ts`, liste NOMINATIVE des pages de route de `app/routes.tsx`
  référençant le patron + exemptions motivées + complétude, patron `pageHeaderGuard.test.ts`,
  UXS-09/10), `ActionVeil` (voile de navigation/sauvegarde global — `app/ActionVeil.tsx`) ;
  **primitives** `shared/components/ui/*` (Button, Modal, Select, Input, Card, StepRail, Menu APG,
  **Listbox** — sélecteur riche à choix unique (couleur/icône, compte, sous-ligne, option
  désactivée motivée), patron APG, P4-164 PR-1, maison des sélecteurs qui dépassent le `<select>`
  natif, recherche intégrée au panneau au-delà de 8 options réelles (P4-198) —, **StatusPill** — la pastille partagée, icône + texte, variantes warning/accent/accent-solid/neutral,
  P4-173 puis P4-177, `accent-solid` (fond accent PLEIN, un badge mis en avant plutôt qu'un état)
  PR 5/7 série « uniformité des écrans » —, **DayMultiPicker** — le sélecteur multi-jours partagé
  (`<fieldset>`/`<legend>`, boutons `aria-pressed`, libellé court visible + nom accessible complet,
  `tone` accent/destructive), PR 6/7 série « uniformité des écrans » —, VenueSwatch…) ; `SourceBadge` (AUTO/MANUEL) est désormais lui aussi une maison
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
  différente), ni les contrôles SEGMENTÉS — ceux-ci ont depuis leur propre maison **`SegmentedControl`**
  (`shared/components/ui/segmented-control.tsx`, UXC-28, audit 2026-10-03) : rangée de boutons nus
  dans UN conteneur bordé (`role="group"` nommé, segments `aria-pressed`, 36 px), union discriminée
  `mode="single"|"multiple"`, compteur par segment optionnel — vues planning, axe équipe/coach/
  gymnase, « Période », pivot « Regrouper par », filtre adversaires, « Types ». Gardé par
  `frontend/src/test/segmentedControlGuard.test.ts` (un conteneur `bg-card`+`p-0.5` fait main rougit) ; `snapshotFile`
  (`shared/lib/fileSnapshot.ts`, P3-7) est la maison unique du snapshot mémoire d'un `File` avant
  envoi — ferme le piège `ERR_UPLOAD_FILE_CHANGED` (fichier relu sur disque à l'envoi, déguisé en
  « Problème de connexion ») — consommée par `TeamsImportModal.tsx` et `ImportFbiDialog.tsx` ;
  **couleurs/espacements** = tokens du thème (`text-warning`,
  `text-muted-foreground`, `bg-muted`, `border-border`…), **jamais un `#hex`** ni une classe sans
  jeton (`text-warning-foreground` était un no-op, P4-130). `PRODUCT_ACCENT` (`shared/lib/product.ts`)
  est la SEULE maison d'un hex d'accent produit ; les valeurs statiques d'accent d'`index.css` sont
  la sortie EXACTE de sa dérivation, gardée par `src/test/accentTokenParity.test.ts` — **on ne les
  édite jamais à la main, on les recalcule** (`specs/courantes/identite-visuelle-produit.md`).
  `BrandIcon` (`shared/components/ui/brand-icon.tsx`) est la première exception admise à « jamais
  un `#hex` » **en composant React** (ses trois arcs) ; `BrandSplash`
  (`shared/components/ui/brand-splash.tsx`, P4-252) en est une seconde — les MÊMES trois teintes du
  mark, recopiées pour dessiner l'icône + le « eo » teal du splash de connexion animé — et les
  **SVG d'asset statiques de marque** (`public/brand/*.svg` — `favicon.svg`,
  `fond-light.svg`/`fond-dark.svg`, P5-16) une troisième, pour la même raison : un fichier servi tel
  quel n'a pas de jeton de thème à consommer.
  `BrandMark` (`shared/components/ui/brand-mark.tsx`), le logo COMPLET
  (icône + mot) posé partout où le produit se nomme comme MARQUE (login/inscription, écrans
  système, console admin — jamais pour une mention dans une phrase), n'en porte aucune : le mot
  hérite `currentColor`, un seul ton dans tous les thèmes (un second ton teal codé en dur tombait
  sous la barre de contraste sur fond clair, retiré).
- 🔴 **Tout écran principal rend `PageHeader`** (`shared/components/ui/page-header.tsx`) —
  jamais un `<h1` brut : trait `border-accent` + titre à gauche, bouton « Signaler » TOUJOURS
  visible à droite, gardé par `frontend/src/test/pageHeaderGuard.test.ts` (liste nominative des
  pages principales + exemptions `<h1` nominatives). Le canal feedback (`FeedbackButton`,
  `FeedbackDialog`) vit dans `shared/feedback/` avec la
  brique, une primitive `shared/` ne remontant jamais vers une feature (AUD-FRT-21).
- 🔴 **Heure affichée = « 21:00 », durée affichée = « 1h30 » — deux foyers uniques, jamais
  fabriqués à la main** (normes d'uniformité des écrans, GO fondateur sur captures A/B,
  2026-09-30) : toute heure de pendule passe par `shared/lib/time.ts::formatMinutes` (jamais
  `frClock` — supprimé — ni un « 21h »/« 09h » assemblé à la main) ; toute durée de créneau passe
  par `shared/lib/duration.ts::formatDuration`, forme COMPACTE (jamais la forme aérée
  `time.formatDurationMinutes` — supprimée). Gardé par
  `frontend/src/test/timeFormatGuard.test.ts` (grep statique des sources `src/**`, zéro exemption
  nominative). Hors portée, délibérément : les durées ÉCOULÉES/CUMULÉES de la console superadmin
  et des gymnases (`venueStats.formatHours`), et les exemples rédigés des bulles d'aide.
- 🔴 **Enregistrer/Annuler d'une ligne en édition = TEXTE, jamais une icône seule (N1) ; toute
  suppression passe par une confirmation, jamais un clic direct (N2)** — mêmes normes fondateur
  du 2026-09-30, PR 2/7 de la série « uniformité des écrans ». N1 : patron `IdealSlotsEditor`
  (boutons `Enregistrer`/`Annuler` en texte) et le bouton d'enregistrement de `ConstraintsStep`.
  N2 : `ConfirmDialog` avant suppression d'un créneau idéal, d'une fenêtre d'accès match
  (`MatchWindowsEditor`), d'une passerelle (`TeamLinksSection`), d'une indisponibilité de gymnase
  (`VenueUnavailabilityCard`), d'une réservation ou d'un lot mutualisé au récap (`RecapStep`) et
  d'une doléance coach (`CoachWishesModal`, PR 5/7) — seule exception : retirer une pastille de
  LIAISON coach/joueur↔équipe (`CoachesStep`) reste immédiate, geste réversible sans conséquence.
  `WeekWorkbench` (suppression de match) est une exemption légitime du garde, pas une lacune : ses
  deux chemins de suppression confirment déjà DANS leurs enfants (`AwayList`, `PlacementPanel`).
  N2 gardé par `frontend/src/test/deleteConfirmGuard.test.ts` (grep statique `src/features/**` : un
  fichier qui lie ET invoque un `useDelete…` sans référencer `ConfirmDialog`/`DeleteConfirm`
  rougit, sauf exemption nominative motivée) ; N1 n'a pas de garde automatique, seulement la revue.
- 🔴 **Un bouton d'AJOUT porte toujours un libellé VISIBLE, jamais une icône « + » seule** (P4-285,
  décision fondateur 2026-10-07, famille « uniformité des écrans ») : patron `ConstraintsPage`
  (`<Button size="sm"><Plus className="size-3.5" />Ajouter …</Button>`), texte = nom accessible
  (pas d'`aria-label` redondant). Variante DISCRÈTE `variant="ghost"` (sans bordure) sur une ligne
  de tableau (`OpponentsPage`) ; le save/cancel d'un formulaire d'ajout retombe sur N1 (texte —
  `IdealSlotsEditor`). Gardé par `frontend/src/test/addButtonLabelGuard.test.ts` (scan par ÉLÉMENT
  JSX de `src/features/**`+`src/app/**` hors admin : un `<Button>` `size="icon"`/`"icon-sm"` dont
  l'`aria-label` commence par « Ajouter » rougit).
- 🔴 **Tout bandeau d'information passe par `NoticeBanner`** (`shared/components/ui/notice-banner.tsx`
  — fond opaque `bg-surface-<ton>`, bordure, rayon, padding, texte `text-foreground`), jamais une
  boîte faite main (série « uniformité des écrans », PR 4/7, 2026-10-01 — les bandeaux de
  Planning/Matchs/Assistant/Cockpit ont été ramenés dessus ; portée étendue à `src/shared/**`
  au reliquat UX de l'audit 2026-10-03, UXC-27 — `CreditsBanner`). Gardé par
  `frontend/src/test/bannerPrimitiveGuard.test.ts` (grep statique `src/features/**` ET
  `src/shared/**` hors console admin : un `role="status"`/`"alert"` + une classe de bordure de
  ton sur la MÊME ligne hors `NoticeBanner` rougit).
- 🔴 **Toute pastille d'état passe par `StatusPill`** (`shared/components/ui/badge.tsx` — icône +
  texte, bordure + fond teinté, variantes warning/accent/accent-solid/neutral), jamais un
  `<span>` arrondi recodé à la main (série « uniformité des écrans », PR 5/7, 2026-10-01 —
  `SocleDeviationPanel`, `ToReplaceList` et les pastilles accent plein de `ClubPage`/
  `MembersSection`/`ReconciliationView` ramenées dessus ; au passage, les derniers états vide/
  échec/chargement faits main de `PendingMembersSection`/`ClubPage`/`ReleaseNotesPage`/
  `ConfigurationPage` migrent sur `EmptyHint`/`LoadErrorHint`/`Spinner`). Gardé par
  `frontend/src/test/pillPrimitiveGuard.test.ts` (grep statique `src/features/**` : une ligne
  portant à la fois la FORME d'une pastille (`rounded-full` + `text-xs`) ET une classe de TON
  (teinte/bordure warning|destructive|accent|success, ou une surface `bg-surface-*`) hors
  `StatusPill` rougit — les pastilles NEUTRES `bg-muted`/`border-border` ne sont PAS attrapées,
  largeur de grep documentée, leur passage relève de la revue). Exemptions nominatives :
  `features/admin/**` (console superadmin, hors écrans de l'app club), `CoachesStep` (pastilles de
  LIAISON coach/joueur↔équipe, hors contrat StatusPill), `CampaignDialog` (puces de FILTRE
  interactives, relèvent de `FilterChip`), `DriftBanner` (bouton-bascule, pas une pastille d'état).
  Hors portée, délibérément : les quatre indices de chargement restés en TEXTE (`ClubPage` §
  statistiques et § offres, `PeriodTeams`, `PeriodVenues`).
- 🔴 **Le toast d'erreur d'une mutation vit UNE fois, au NIVEAU HOOK** (`useMutation({ onError })`),
  jamais passé à `mutate(vars, { onError })` (FRT-38, 2026-10-06). Le filet global
  `MutationCache.onError` (`shared/lib/queryClient.ts`) ne se DÉSARME que sur
  `mutation.options.onError` (niveau hook) : un `onError` de niveau `mutate()` ne le voit pas, donc
  un toast d'erreur posé là en fait DEUX (ou double le toast du hook). Gardé par
  `frontend/src/test/mutateOnErrorToastGuard.test.ts` (grep statique `src/features/**`+`src/app/**` :
  un `onError` passé à `.mutate(`/`.mutateAsync(` dont le CORPS référence `toast.` rougit — un
  `onError` qui ne fait que poser de l'état local, p.ex. `setError`, ou appelle un handler NOMMÉ
  n'est PAS attrapé). Exemptions nominatives motivées : `useRetouchGestures.ts` (patron
  split-feedback documenté `planning/queries.ts:51-74` — le hook TAIT les erreurs MÉTIER pour que la
  page les toaste avec CONTEXTE : noms d'équipes, timeout nommé, surlignage ; le reliquat de double
  toast PARTIEL sur transport = P4-306) et `AdminDashboardPage.tsx` (messages contextuels runtime
  `job.label`/`club.name`). Les messages SERVEUR passent par `errorMessage()` (patron UXS-13).
- 🔴 **La largeur d'un sélecteur passe par `wrapperClassName`, jamais `className`** (`Select`,
  `Listbox`, `TeamSelect`, `VenueSelect` — PR 3/7 de la série « uniformité des sélecteurs »,
  2026-10-01) : le contrôle intérieur (`<select>`/trigger) est toujours `w-full`, une classe
  `w-`/`min-w-`/`max-w-`/`flex-`/`shrink-`/`grow-`/`basis-` posée en `className` le vise, jamais
  la boîte que la ligne flex mesure. Gardé par ESLint (`frontend/eslint.config.js`,
  `no-restricted-syntax` sur `src/features/**`/`src/app/**`, admin exempté) en deux volets : un
  `<select>` JSX natif brut est interdit (utiliser `Select`, ou `TeamSelect`/`VenueSelect` pour un
  picker d'équipe/gymnase), et une classe de largeur **littérale** en `className` sur ces quatre
  composants rougit — un `cn(...)`/une variable n'est pas couvert par l'AST (choix documenté,
  reste à la revue).
- 🔴 **Hauteur d'un contrôle de ligne = 36 px (`h-9`) ; la variante dense est NOMMÉE, jamais une
  classe forcée** (série « uniformité des écrans », PR 7/7, 2026-10-01) : `Input`/`Select`
  rendent `h-9` par défaut, `Button` `default`/`icon` restent à 40 px (CTA pleine page, pied de
  modale) et une ligne dense prend `size="sm"` ou le nouveau `size="icon-sm"` (36 px, bouton-icône
  de ligne). La variante compacte `compact` (`h-8`, prop sur `Input`/`Select`) est réservée aux
  TABLEAUX denses (`TeamsStep`, `PeriodTeams`, `RedateEventsDialog`) — jamais mélangée à du `h-9`
  dans la même ligne. Les rares `<textarea>` (pas de `<input>` nu dans `features/`) passent par
  `FIELD_CLASS` (`shared/components/ui/field.ts`), le foyer unique de la classe de champ natif,
  même apparence que `Input` sans la hauteur (multi-lignes). Gardé par ESLint
  (`frontend/eslint.config.js`, `no-restricted-syntax` sur `src/features/**`/`src/app/**`, admin
  exempté) : une classe `h-7`/`h-8`/`h-10`/`h-11` littérale en `className` sur
  `Button`/`Input`/`Select`/`Listbox`/`TeamSelect`/`VenueSelect` rougit (`h-7` = 28 px ajouté au
  reliquat UXC-29 de l'audit 2026-10-03 : les puces de filtre et segments étaient sous la norme) —
  même limite que la règle
  de largeur (PR 3/7) : un `cn(...)`/une variable/un template literal n'est pas couvert par l'AST,
  reste à la revue. **Ligne d'ajout/édition dont l'action ne tient pas sur une ligne à 1280 px →
  DEUX rangées structurées** (jamais un bouton d'action orphelin tombé par `flex-wrap`) : rangée 1
  = la sélection, rangée 2 = les valeurs + le bouton d'action à droite — patron `RuleFields`/
  `CoachFields` de `features/matches/ConstraintsPage.tsx` (règle de club, indisponibilité coach),
  décision fondateur 2026-10-01. NR e2e `tests/e2e/layout-inline-rows.spec.ts` (deux rangées à
  36 px, `Type` au-dessus, 0 débordement horizontal) ; le builder de contraintes du wizard
  (`ConstraintsStep`) partage le même risque et n'a pas encore été repris (P4-283).
- 🔴 **La raison d'une désactivation doit être DÉCOUVRABLE au clavier/lecteur d'écran, pas qu'au
  survol** (A11Y-30, audit 2026-10-03) : un `<button disabled>` natif sort de l'ordre de tabulation,
  son `title`/`aria-describedby` ne sont jamais annoncés. Quand un bouton est désactivé POUR UN
  MOTIF, passer la raison en **prop `disabledReason`** de `Button` (`shared/components/ui/button.tsx`) —
  il rend alors `aria-disabled` (focalisable), pose `title` (souris) + `aria-describedby`→`<span
  sr-only>` (clavier/AT), et neutralise le clic ; garder `disabled` à côté pour la condition. Un
  `title={cond ? raison : undefined}` sur un bouton `disabled` est le vieil anti-patron. Exception
  LÉGITIME : quand la raison est déjà en CLAIR à côté (texte visible — `WeekPickerDialog`), ne PAS
  doubler avec `disabledReason`. Hors portée du mécanisme : `<select>`/`<option>`/`<input>` désactivés
  (pas de `disabledReason`), à traiter autrement. Pas de garde automatique fiable (un `title`
  d'action n'est pas une raison) — la revue tient la règle.
- 🔴 **Un bouton d'action en LISTE porte un nom accessible CONTEXTUALISÉ, jamais un verbe nu
  répété** (A11Y-28, audit 2026-10-03) : `aria-label={`Supprimer l'équipe ${name}`}`, pas
  `aria-label="Supprimer"` dix fois de suite (un lecteur d'écran ne saurait pas laquelle). Gardé par
  `frontend/src/test/genericAccessibleNameGuard.test.ts` (`aria-label` littéral « Supprimer »/
  « Modifier »/« Retirer »/« Éditer » nu dans `features/**`/`app/**` rougit).
- 🔴 **Tout choix de jours passe par `DayMultiPicker`, tout libellé de jour par `shared/lib/days.ts`**
  (`shared/components/ui/day-multi-picker.tsx` — PR 6/7 série « uniformité des écrans »,
  2026-10-01) : maison unique du sélecteur multi-jours (patron APG toggle button, `<fieldset>`/
  `<legend>` nommant le groupe, libellé COURT visible « Lun », nom accessible COMPLET « lundi »,
  `tone` `accent`/`destructive` pour la polarité de l'état pressé, valeurs ISO 1-7 inchangées
  côté API) et du libellé de jour (`DAYS`, `dayLabelShort`, `dayLabelLong`, `dayLabelLongCap`) —
  absorbe les tables locales dispersées (wizard, matchs, doléances, club, gymnases, créneaux
  idéaux), germe exact du bug D-22 (une copie de table « s'arrêtait au samedi »). Gardé par
  `frontend/src/test/dayPickerGuard.test.ts` (grep statique `src/features/**`/`src/app/**` : un
  triplet de libellés de jours consécutifs littéraux hors `shared/lib/days.ts` rougit, exemptions
  nominatives motivées) — hors portée délibérée : les lettres seules d'un en-tête de calendrier
  (`MonthCalendar`, pas une table de libellés).
- 🔴 **Un changement d'apparence VOULU sur un des 9 écrans de `visual-reference.spec.ts`
  (login, register, planning semaine, matchs calendrier/semaine type/contraintes club, club,
  wizard coachs/contraintes) exige une re-baseline** — procédure et commande unique :
  `docs/testing/testing-strategy.md` §1. Un rouge `toHaveScreenshot` sur ce spec sans changement
  d'apparence voulu est une régression, pas une image à régénérer.
- 🔴 **Les racines de shell ne portent plus `bg-background` depuis le fond d'écran commun**
  (P5-16, `AppLayout.tsx`/`AuthLayout.tsx`) : le fond commun vit sur `body` (`index.css`), et une
  racine qui poserait `bg-background` par-dessus le masquerait entièrement. L'en-tête d'`AppLayout`
  et les cartes restent OPAQUES (`bg-background`/`bg-card` posés dessus, pas sur la racine) — le
  fond ne vit que dans les zones vides. Un écran qui veut au contraire un fond NU (déjà chargé
  visuellement, ou système) pose `bg-background` sur sa PROPRE section, pas sur un shell partagé —
  patron `GenerationScene.tsx` (racine `bg-background`, décor déjà dense) et `system-screen.tsx`
  (inchangé, hors lot).
- 🔴 **Toute surface qui porte du texte est opaque** (P4-265) : `bg-card` ou un jeton
  `--surface-warning|accent|destructive|muted` (`color-mix` sur `--card`, `index.css`) — jamais une
  teinte `bg-<jeton>/NN` comme fond AU REPOS (deux `background-color` Tailwind ne se composent pas,
  et une teinte seule laisse traverser le fond à motifs). La surbrillance garde sa teinte `/NN`,
  toujours préfixée (`hover:bg-accent/10`) : seul le repos devient plein. **Le texte posé sur une
  surface teintée reste `text-foreground`** (jamais `text-warning`/`text-accent`, sous l'AA sur leur
  propre teinte). Gardé par `frontend/src/test/surfaceOpacityGuard.test.ts` (portée
  `shared/components/ui/*.tsx`, ≤ 5 exemptions nominatives). **Un CONTRÔLE DE SAISIE partagé**
  (champ, sélecteur, zone de texte) est soumis à une contrainte PLUS STRICTE (retour terrain
  2026-09-27, même fichier de garde, liste nominative `INPUT_CONTROL_FILES` — `input.tsx`,
  `select.tsx`, `listbox.tsx`, `password-input.tsx`, `team-select.tsx`, `venue-select.tsx`,
  `new-password-fields.tsx`) : `bg-transparent` au repos y tombe aussi (pas seulement une teinte
  `/NN`), car un champ transparent sur le fond à motifs du body rend son `placeholder` illisible —
  contrairement à un bouton `ghost`/`outline`, qui reste transparent (il vit sur une surface déjà
  opaque).
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
- 🔴 **Toute classe `animate-*` porte `motion-reduce:animate-none`, tout `.css` qui anime a un volet
  `prefers-reduced-motion`** (A11Y-31, WCAG 2.3.3 · audit 2026-10-03) : une animation est du confort,
  jamais de l'information, et doit s'effacer pour qui a demandé « moins de mouvement ». Gardé par
  `frontend/src/test/motionReduceGuard.test.ts` (grep statique `src/**`, patron `textOpacityGuard`) : une
  classe `animate-<x>` (hors `animate-none`) sur une ligne `.tsx` sans `motion-reduce:`, ou un fichier
  `.css` de `src/` qui déclare `animation:` sans requête `prefers-reduced-motion`, rougit dans Vitest —
  AVANT le scan Playwright, car jsdom n'a ni moteur de layout ni `matchMedia`. Un site animé borné
  AUTREMENT (JS `matchMedia` comme `brand-splash`, bloc CSS frère) entre dans `TSX_EXEMPTIONS` (≤ 5).
- 🔴 **Un scan a11y authentifié sur `/matchs` ne peint RIEN si le club seedé CI n'a aucune
  `Fixture`** — `app:bccl:seed` ne pose que des `TeamMatchHabit` (créneaux idéaux), jamais de
  rencontre : l'écran rend un `EmptyState` (« Aucun match importé »), le scan tourne sur du vide et
  son témoin rougit avant même d'atteindre le contraste. `tests/e2e/a11y-contrast.spec.ts`
  (2026-09-18) POSTe désormais son propre amical HOME placé avant de scanner, nettoyé en `finally`
  — patron à reprendre pour tout nouveau scan sur un écran sans donnée garantie par le seed ou
  l'onboarding. Chaque écran authentifié désigne en outre son PROPRE témoin de contenu rendu
  (grille week-end / région nommée / carte `[data-slot-id]` / `h1`) plutôt qu'un témoin global
  (`shadow-sm`/`role=region`/testid) — `/planning` (`WeekGrid`) ne porte AUCUN des trois, un témoin
  global le déclarait « vide » alors qu'il était peint.
- 🔴 **Tout « aujourd'hui » passe par `shared/lib/clock.ts`, jamais `new Date()`** — seule maison
  qui compose l'override dev (`?today=`) et l'horloge simulée SERVEUR d'un club démo
  (`/api/me` → `club.simulatedToday`, posée/décidée côté back par `App\Clock\ClubClock`, la seule
  capacité à décider si un club a une horloge active — réservée à un compte DÉMO, 2026-10-02).
  Un composant React qui LIT la date **en rendu** utilise `useTodayISO()`/`useTodayDate()`
  (réactifs, `useSyncExternalStore` — ils se recalent quand la date serveur arrive après le
  premier rendu) ; `todayISO()`/`todayDate()` restent pour une lib pure hors rendu React.
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
