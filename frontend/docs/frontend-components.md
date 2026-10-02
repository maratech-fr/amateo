# Conventions API, Layout et primitives UI partagées

Last verified @ 2026-10-02 (`documentation-update`, lot horloge PR D — en-tête `AppLayout.tsx`
confronté : `DemoClockWidget` ajoutée juste après `DemoBadge`, avant `DevClock`). Reste de la table
hérité des passes précédentes (PR 7/7 hauteurs, PR 6/7 `DayMultiPicker`, PR 4/7 bandeaux, PR 3/7
sélecteurs, P4-252 splash de connexion, PR #1031 `Listbox`/`VenueSelect`), non rejoué ligne à ligne
cette fois — historique : `git log -p --follow` sur ce fichier). **§3 est la maison unique
des primitives UI partagées** (décision fondateur 2026-09-26) : les entrées déménagées depuis
`frontend/AGENTS.md` § « Primitives that matter » sont vérifiées contre
`frontend/src/shared/components/ui/` (`ls` : tous les fichiers cités existent).

> **Où est la vérité :**
>
> | Sujet | Doc canonique |
> |---|---|
> | Routes, state, contrat API, arborescence livrée | [`frontend-spec.md`](frontend-spec.md) §2 / §8 / §9 / §10 |
> | Wizard (6 étapes) + mode période | [`frontend-wizard.md`](frontend-wizard.md) |
> | Cycle de vie du planning (valider / rouvrir / pointeur) | [`planning-lifecycle-validated.md`](../../specs/courantes/planning-lifecycle-validated.md) + ADR-0002 |
> | Cockpit (accueil temporel, radar) | [`accueil-cockpit-temporel.md`](../../specs/courantes/accueil-cockpit-temporel.md) |
> | Module matchs | [`module-matchs.md`](../../specs/courantes/module-matchs.md) |
> | Doléances coachs (#10) — dont la page publique `/doleances/{token}` | [`types-de-planning.md`](../../specs/courantes/types-de-planning.md) §E5 |
> | Console superadmin (`/admin`) | [`superadmin-auth.md`](../../specs/courantes/superadmin-auth.md) |
> | Conventions agent, pièges, primitives partagées | [`../../frontend/AGENTS.md`](../../frontend/AGENTS.md) |
> | `AppLayout` / `AuthLayout` / `AdminAuthLayout` | section « Layout » ci-dessous |
> | Primitives UI partagées (`Button`, `Listbox`, `Modal`, `StatusPill`…) | **§3 ci-dessous — maison unique** |

---

## 1. Conventions de nommage API

> **Important :** l'OpenAPI snapshot utilise `snake_case` pour les paths
> multi-mots (ex: `/api/priority_tiers`, `/api/schedule_diagnostics`,
> `/api/schedule_slot_templates`, `/api/sport_categories`,
> `/api/team_coaches`, `/api/venue_training_slots`). Le frontend doit utiliser
> les paths exacts de l'OpenAPI, pas une convention kebab-case dérivée. Les
> query params suivent la même convention (`schedule_id`, `season_id`).

Référence : `specs/courantes/openapi-snapshot.json` — paths des ressources API
Platform + opérations custom déclarées sur les ressources (`/api/schedules/{id}/generate`,
`/api/schedules/{id}/export-pdf`, `/api/clubs/{id}/import-teams`). Les routes
Symfony custom (`/api/me`, `/api/register`, `/api/password/*`, `/api/memberships/*`,
`/api/schedules/{id}/validate|reopen`, `/api/teams/reorder`,
`/api/club/appearance`, `/api/club/logo`, …) n'y figurent **pas** — inventaire
complet dans `backend-inventory.md` §3.

---

## Layout (AppLayout / AuthLayout / AdminAuthLayout)

Trois layouts pour les écrans authentifiés/non authentifiés + un layout wizard (non détaillé
ici, voir `frontend-wizard.md`).

### AuthLayout / AdminAuthLayout

**Routes :** `AuthLayout` habille `/login`, `/register` et les autres écrans publics non-admin
(table complète : `frontend-spec.md` §2) ; `AdminAuthLayout` habille `/admin/login` seul.

Les deux sont une carte centrée sur fond plein écran (`AuthLayout` : le fond d'écran commun
app/vitrine posé sur `body`, P5-16 — sa racine ne porte pas `bg-background` ;
`AdminAuthLayout` : `bg-console-surface` sombre + halos décoratifs, hors du fond commun, UXC-12),
sans navigation. La marque est le logotype complet **`BrandMark`**
(`shared/components/ui/brand-mark.tsx`, `role="img"` nommé `PRODUCT_NAME`) — `AuthLayout`
l'affiche en couleur, bascule thème clair/sombre juste à côté ; `AdminAuthLayout` le teinte en
blanc sur son fond sombre, sous-titré « Console sécurisée ». Gardé par `AuthLayout.test.tsx` /
`AdminAuthLayout.test.tsx` : le logotype nommé est présent, aucun texte nu ni icône
`CalendarCheck2` ne subsiste.

### Splash de connexion (`LoginSplash` / `BrandSplash`, P4-252)

Au submit du formulaire de `/login` (`features/auth/LoginPage.tsx`), le logo joue une animation
« Signature » **une fois par tentative** — jamais à la simple arrivée sur `/login`, jamais sur les
autres écrans publics d'`AuthLayout`. Trois pièces :

- **`shared/stores/loginSplashStore.ts`** : la machine à phases `idle → intro → (breathing) →
  outro → idle`, ou `intro|breathing → cancelling → idle` sur identifiants refusés. Ne porte que la
  grammaire des transitions légales ; le chronométrage vit dans `BrandSplash`.
- **`shared/components/ui/brand-splash.tsx`** (+ `brand-splash.math.ts` pour les formules pures,
  recopiées du handoff marque `business/`, hors dépôt) : le rendu SVG/HTML animé en
  `requestAnimationFrame` — icône (trois arcs) + mot produit (`PRODUCT_NAME`, jamais un littéral)
  dont les deux dernières lettres sont en teal (`#hex` en dur, même exception documentée que
  `BrandIcon`, `.claude/rules/frontend.md`). Intro (icône qui glisse + mot qui sort du trait) ;
  si l'app n'est pas prête, le logo respire en opacité (1 ↔ ~0,55, ~1,6 s cycle) jusqu'à ce
  qu'elle le soit, puis outro en miroir de l'intro. `prefers-reduced-motion` → logo statique, pas
  de respiration ni de glisse, juste les fondus d'entrée/sortie. Le mot est composé dans une
  police **dédiée** « Poppins Signature » (Poppins Medium 500, sous-ensemble latin embarqué
  `public/fonts/poppins-500-latin.woff2`, `@font-face` dans `index.css` — Google Fonts au runtime
  est interdit par la CSP `font-src 'self'`) — cette famille ne sert QUE ce logotype animé, jamais
  la typo de l'app (`system-ui`).
- **`app/LoginSplash.tsx`** : l'orchestrateur, monté dans `RootShell` (donc **persistant** au
  `navigate("/")` que déclenche un login réussi — un montage dans `LoginPage` aurait été démonté
  par la navigation). Overlay plein écran en `createPortal(document.body)`, `z-[70]` — au-dessus du
  voile d'action générique `ActionVeil` (`z-[60]`) : `useLogin` (`features/auth/queries.ts`) porte
  `meta: { veil: false }` pour cette raison (liste des exemptions au voile :
  `frontend/AGENTS.md` § « Toute mutation VOILE l'écran »). « Prêt » = login résolu + query `me` en
  succès + navigation `idle` + route hors `/login` (une adhésion en attente rendue sur `/waiting`
  compte donc comme prête). Pendant que l'overlay bloque, le contenu routé est rendu `inert`
  (patron `ActionVeil`) — sauf en phase `cancelling`, où la main revient aussitôt au formulaire.
  Identifiants refusés → `cancel()` efface l'overlay en douceur, le message d'erreur de
  `LoginPage` reste inchangé et le focus revient au champ e-mail (`emailRef`, effet déclenché par
  le changement d'`error`).

### AppLayout

Layout de l'espace authentifié (`frontend/src/app/AppLayout.tsx`) : un unique `<header>`
(`border-b`, `h-14`) puis `<main>` — **pas de sidebar** (table réelle des routes sous
`AppLayout` : `frontend-spec.md` §2).

En-tête, de gauche à droite :
- Le lien d'accueil (`NavLink to="/"`) : `aria-label` = nom du club sinon `PRODUCT_NAME`, à
  TOUTE largeur — c'est lui qui porte le nom ACCESSIBLE, pas le texte visible. Visuellement :
  `BrandIcon` (icône produit, toujours rendue) puis le blason du club (`<img alt="">`,
  seulement si `logoUrl`) puis un `<span>` du nom (club ou `PRODUCT_NAME`). Ce `<span>` est
  masqué sous `sm` (`hidden sm:inline`, `truncate` conservé ≥ `sm`, P4-261) — il ne se tronque
  plus visuellement jusqu'à un caractère (« B… »), il disparaît proprement ; le nom accessible
  du lien ne bouge pas.
- Les **pastilles d'OFFRE** (`BetaBadge` puis `CreditBadge`, 2026-09-29), juste après le lien
  d'accueil, dans la même grappe de marque — pas dans la nav de droite. Les deux lisent l'offre
  du club servie par `/api/me` (`entitlements`), jamais un état recalculé, et sont mutuellement
  exclusives en pratique (rien en payant/démo, rien sur les pages publiques puisque `AppLayout`
  ne monte que dans l'espace authentifié) :
  - `BetaBadge` (`app/BetaBadge.tsx`) — offre `beta` (`entitlements.planCode`) seulement, sinon
    `null`. Bouton-disclosure « BÊTA » en teal PRODUIT (`PRODUCT_ACCENT` via `accentForMode`,
    jamais l'accent du club — recette `StatusPill` : bordure/fond teintés, texte
    `text-foreground` pour l'AA) qui ouvre un popover **non modal** (`role="dialog"
    aria-modal="false"`) : Échap ferme et rend le focus à la pastille, clic extérieur ferme sans
    forcer le focus, le CTA « Signaler un problème » — lui aussi en teal PRODUIT (fond
    `accentForMode(PRODUCT_ACCENT)`, texte `readableForeground`, surchargés en style inline sur la
    primitive `Button`, jamais `--accent`) — ferme le popover et appelle `onReport`
    (câblé par `AppLayout` sur le même `FeedbackDialog` que le menu du compte). La bêta se
    termine club par club via l'attribution de plan de la console superadmin — aucun
    interrupteur produit.
  - `CreditBadge` (`shared/credits/CreditBadge.tsx`) — déplacé ici depuis la nav de droite,
    offre Découverte bridée seulement (`useCredits()` rend `null` hors Découverte bridée).
    Libellé « Découverte · N crédits » (accord singulier/pluriel), `StatusPill` variante
    `warning` **permanente** (l'ancien seuil ≤ 5 crédits a disparu — l'ambre marque l'offre,
    plus le solde), enveloppée d'un `Link` vers `/club` (le seul écran où consulter/faire
    évoluer l'offre).
- `DemoBadge` (`app/DemoBadge.tsx`) — juste après `BetaBadge`/`CreditBadge`, dans la même grappe :
  elle S'AJOUTE aux pastilles d'offre plutôt que d'en remplacer une. Lit `club.isDemo` (`/api/me`,
  jamais recalculé) ; `null` pour tout vrai club. `StatusPill` variante `neutral`, texte « Démo » —
  décision fondateur : une démo se dit, elle ne se cache pas.
- `DemoClockWidget` (`app/DemoClockWidget.tsx`) — juste après `DemoBadge` : pastille-bouton
  « Aujourd'hui : … » (ou « Horloge : aujourd'hui ») pour un club `isDemo` SEUL (`null` sinon, le
  serveur refuse 403 de toute façon) ; au clic, popover champ date + « Appliquer »/« Revenir à
  aujourd'hui » (texte, N1) qui pose `POST /api/club/clock` puis invalide `/api/me`.
- `DevClock`, seulement en `import.meta.env.DEV`.
- La nav de droite (`<nav>`, ne se rétracte JAMAIS, y compris sous 360 px — décision fondateur
  desktop-first/mobile V2 ; le débordement horizontal résiduel de l'en-tête à cette largeur est
  une dette DISTINCTE, en Vision) : `SeasonSelector`, l'item « Matchs »
  (verrouillé — `aria-disabled`, non cliquable — tant que `me.seasonPlan.chosenScheduleId` est
  nul, même condition que `SocleGuard` côté serveur), la bascule thème clair/sombre, puis le
  `Menu` du compte : Club (`/club`), Profil (`/profile`), Nouveautés (`/nouveautes`), Signaler
  un bug (`FeedbackDialog`), Confidentialité (`/confidentialite`), Se déconnecter.

`<main aria-busy={navigating}>` (`navigating` dérivé de `useNavigation()`) rend
`ReadonlySeasonBanner`, `SeasonTransitionBanner`, `CreditsBanner` puis `<Outlet />`. Aucun skip
link n'est présent dans ce composant (grep `main-content`/`sr-only` sans résultat dans
`AppLayout.tsx`).

### Test Cases — Layout

**Given** `useMe()` n'a pas encore de club chargé
**When** `AppLayout` se rend
**Then** l'icône produit (`BrandIcon`, seul `<svg>` au `viewBox="0 0 1000 1000"`) est présente
**And** le nom affiché est `PRODUCT_NAME`
(`frontend/src/app/AppLayout.test.tsx` — « rend TOUJOURS l'icône produit, même sans club
chargé » / « sans club chargé : icône produit + nom produit »)

**Given** le club a un `logoUrl`
**When** `AppLayout` se rend
**Then** le blason du club (`<img alt="">`) apparaît dans le `<header>` à côté de l'icône
produit, et son nom (pas `PRODUCT_NAME`) est affiché
(`AppLayout.test.tsx` — « affiche le blason du club à côté de l'icône produit quand il
existe »)

**Given** le club n'a pas de `logoUrl`
**When** `AppLayout` se rend
**Then** aucune balise `<img>` n'apparaît dans le `<header>`
**And** l'ancienne icône de repli `CalendarCheck2` n'est plus utilisée
(`AppLayout.test.tsx` — « sans blason : aucune image de club, l'icône produit suffit » /
« n'utilise plus l'icône de repli CalendarCheck2 »)

**Given** le club a chargé (peu importe l'offre)
**When** `AppLayout` se rend
**Then** `BetaBadge` et `CreditBadge` apparaissent dans la grappe de marque (parent du lien
d'accueil), APRÈS ce lien
**And** ni l'un ni l'autre n'apparaît dans la nav de droite (`<header> <nav>`)
(`AppLayout.test.tsx` — « place les deux pastilles dans la grappe de marque, APRÈS le lien
d'accueil, hors de la nav »)

**Given** le club courant est un club de démonstration (`club.isDemo`)
**When** `AppLayout` se rend
**Then** `DemoBadge` apparaît dans la grappe de marque, APRÈS le lien d'accueil, hors de la nav de
droite — en plus des pastilles d'offre, jamais à leur place
(`AppLayout.test.tsx` — « place aussi la pastille « Démo » dans la grappe de marque, hors de la
nav de droite » ; `DemoBadge.test.tsx` — rien pour `isDemo` faux ou club non chargé)

**Given** l'offre bêta (`BetaBadge` rendu)
**When** son CTA « Signaler un problème » est activé (`onReport`)
**Then** `FeedbackDialog` s'ouvre — le même canal que « Signaler un bug » du menu du compte
(`AppLayout.test.tsx` — « BÊTA → onReport ouvre le canal de signalement (FeedbackDialog) »)

**Given** un gestionnaire connecté navigue vers `/club` en viewport 360 px
**When** la page se charge
**Then** le lien d'accueil reste visible et garde son nom accessible (`aria-label` = nom du
club, établi par un témoin `GET /api/me` — jamais un littéral)
**And** le `<span>` visuel du nom est MASQUÉ (`toBeHidden`)
**When** le viewport repasse à 1280 px
**Then** le `<span>` du nom redevient visible, le nom accessible ne change pas
(`frontend/tests/e2e/width-calibration.spec.ts` — « en-tête à 360 px : le nom du club se
masque sous sm, le lien d'accueil garde son nom accessible », P4-261 ; ⚠ ce test verrouille le
comportement du NOM, pas le non-débordement de l'en-tête — à 360 px le produit déborde encore,
dette trackée en Vision)

---

## 3. Shared Components — maison unique des primitives UI

Composants UI réutilisables transversaux à toutes les pages. La quasi-totalité vit dans
`frontend/src/shared/components/ui/` — la seule exception notée ci-dessous (`SourceBadge`) est
signalée comme telle. Au-delà de l'évident (`button`, `input`, `card`, `label`), les
entrées suivantes portent une règle produit ou un piège d'accessibilité — les réutiliser plutôt
qu'en récrire une copie locale.

| Composant | Rôle | Props clés | Utilisé par |
|-----------|------|------------|-------------|
| `Button` | Bouton avec variants + sizes. **Désactivé, il INFORME** (P4-127e) : plus de `pointer-events-none` — les `title=` d'explication vivent, curseur `not-allowed`, survols bornés aux boutons actifs (`hover:enabled:`) ; la doctrine « raison en clair à côté » reste la norme pour les cas importants. `size="icon-sm"` (36 px, `size-9`, PR 7/7 série « uniformité des écrans », 2026-10-01) est le bouton-icône d'une LIGNE (aligné sur `Input`/`Select` `h-9`) — `size="icon"` (40 px) reste le bouton-icône AUTONOME (CTA pleine page, pied de modale) | `variant`, `size`, `disabled`, `children` | Toutes les pages |
| `Input` (`input.tsx`) | Le `<input>` natif STYLISÉ — la SEULE façon de rendre un champ texte/date/heure/nombre sur une ligne dans `src/features/**`/`src/app/**` (pas d'`<input>` nu). Hauteur UNIFORME `h-9` (36 px, PR 7/7 série « uniformité des écrans », 2026-10-01) — comme `Select`, pour que tout contrôle d'une ligne partage la même hauteur. `compact` (`h-8`, même PR) est une variante DENSE réservée aux tableaux serrés (`TeamsStep`, `PeriodTeams`, `RedateEventsDialog`), jamais mélangée à du `h-9` dans la même ligne. Fond OPAQUE `bg-card` (jamais nu, retour terrain 2026-09-27). Le `<textarea>` (seul champ natif qui ne peut pas être un `Input`) prend `FIELD_CLASS` (`shared/components/ui/field.ts`), la même apparence sans la hauteur fixe | `compact`, `className`, props natives (`type`, `value`, `onChange`…) | Tous les écrans à saisie — wizard, matchs, club, cockpit, doléances |
| `PageHeader` (`page-header.tsx`) | **La brique d'en-tête d'écran, maison unique** : trait `border-accent` + titre `text-2xl` à gauche, puis à droite les `actions` de page et, TOUJOURS en dernier, le bouton « Signaler » (`FeedbackButton`) — pour qu'une erreur se remonte depuis n'importe quel écran. Vit dans `shared/` : une brique partagée ne remonte jamais vers une feature (AUD-FRT-21), donc le canal feedback vit avec elle dans `shared/feedback/`. Gardé par `src/test/pageHeaderGuard.test.ts` : chaque page principale (liste nominative dans le garde) rend `PageHeader`, et aucun `<h1` brut ne subsiste ailleurs dans `src/` hors exemptions nominatives (wizard — son propre en-tête d'étape ; console admin ; écran système ; `RouteErrorBoundary`) | `title`, `screen` (libellé d'écran joint au signalement), `scheduleId`, `leading` (avant le titre, ex. blason), `beside` (après le titre, ex. pastille/actions de renommage), `actions`, `subtitle`, `showFeedback` (défaut `true`, à `false` seulement sur une page publique consultée déconnectée) | Planning, Matchs (tous les onglets, `screen` = route de l'onglet), Club, Profil, Nouveautés, Confidentialité, Accueil/cockpit |
| `Select` (`select.tsx`) | Le `<select>` natif STYLISÉ — la SEULE façon de rendre un sélecteur natif dans `src/features/**`/`src/app/**` (garde ESLint, § ci-dessous) : jour, statut, catégorie, durée, type de règle… tout ce qui ne porte ni couleur ni sous-ligne (au-delà, `TeamSelect`/`VenueSelect`/`Listbox`). **`wrapperClassName`** (PR 3/7 « uniformité des sélecteurs », 2026-10-01) porte la largeur du champ — même piège que `Listbox` : le `<select>` intérieur est toujours `w-full`, une classe `w-`/`min-w-`/`max-w-`/`flex-`/`shrink-`/`grow-`/`basis-` posée en `className` viserait ce contrôle interne, jamais la boîte que la ligne flex mesure. Défaut rétro-compatible : `wrapperClassName` omis = conteneur `relative` seul, `className` reste réservé à la hauteur/aux marges. Hauteur UNIFORME `h-9` (36 px) par défaut, `compact` (`h-8`, PR 7/7 série « uniformité des écrans », 2026-10-01) pour les tableaux denses (`TeamsStep`) — même variante et même garde que `Input` | `wrapperClassName`, `compact`, `className`, options en enfants | Pickers simples applicatifs (`FixtureFormDialog`, `PlanningToolbar`, `ConstraintsPage`, wizard) |
| `Listbox` | Sélecteur riche à choix unique — patron APG listbox, trigger `button` (nom accessible = libellé + valeur), roving `tabIndex` (pas `aria-activedescendant`), Escape arrête sa propagation (ne remonte jamais dans une modale hôte), Tab ferme sans sélectionner, couleur/icône, compte, sous-ligne, option désactivée motivée (visible, jamais retirée), **`badge`** (nœud d'état aligné à droite avant le compte — ex. `StatusPill` « À vérifier » du contrôle de cohérence de position, `backend/docs/geo-api.md` §5 —, texte annoncé `aria-describedby`, distinct du `count` tabulaire). Maison des sélecteurs qui dépassent le `<select>` natif (`select.tsx` garde les ~20 pickers simples : jours, statuts, catégorie, durée…). **Recherche dans le panneau à partir de 8 options réelles** (P4-198, `SEARCH_THRESHOLD` — placeholder et `leadingOptions` exclus du seuil et du filtre) : combobox éditable délibérément écarté (aucun gain a11y, casse la lecture du trigger), le panneau devient alors un wrapper `[input + div role="listbox"]` (un `<input>` n'est pas un enfant valide de `role="listbox"`) ; sous le seuil, DOM et comportement byte-identiques. **Panneau PORTÉ sous `document.body` en `position: fixed`** (2026-09-15) : il dépasse une modale ou une zone défilante comme un `<select>` natif — géométrie prise sur le rect du trigger (re-mesurée sur `resize`/`scroll`), bascule vers le haut contre le viewport, `z-[100]` au-dessus des modales, `aria-controls` trigger → panneau, `mousedown` du panneau arrêté avant `document`. **`wrapperClassName`** (défaut `"w-full"`, forwardé par `TeamSelect`/`VenueSelect`) porte la largeur du champ (trigger + popover, qui la reprend) — un champ étroit (ex. le rôle de coach de `CoachesStep`, `w-40`) passe la sienne. Helper de test : `src/test/pickListboxOption.ts` (ouvrir + choisir par libellé) | `value`, `onValueChange`, `options` \| `groups`, `leadingOptions` (options de tête toujours visibles), `placeholder`, `searchLabel` (nom accessible du champ de recherche, défaut « Rechercher »), `wrapperClassName` | `team-select.tsx`, `venue-select.tsx` (tous deux migrés, P4-164) |
| `TeamSelect` | Chaque picker d'équipe de l'app (contraintes, coachs, matchs, import FBI) — bâti sur `Listbox` (P4-164 PR-1) : groupé par rang de priorité, même ordre que l'étape Équipes, **couleur du rang** comme pastille (une équipe n'a pas de couleur propre — décision fondateur, pas de champ backend, le `color` du tier est réutilisé). Les appelants passent `onValueChange` (pas un `onChange` DOM) et, en option, `optionMeta(team)` pour un compte/sous-ligne/désactivé aligné à droite, au lieu de l'ancien suffixe texte `optionLabel`. Reclasser une équipe met à jour l'ordre **partout** | `onValueChange`, `optionMeta` | contraintes, coachs, matchs, `ImportFbiDialog` |
| `VenueSelect` | Chaque picker de gymnase de l'app — reconstruit sur `Listbox` (P4-164 PR-2) : pastille `Venue.color` sur **chaque option ET le trigger** (l'ancienne limite « la liste ouverte reste textuelle » a disparu avec le `<select>` natif) ; API `onValueChange`, `placeholder` sélectionnable (valeur `""`), `leadingOptions` typées, l'état effectif de période d'un gymnase (désactivé / jour fermé / indisponible) en `sub`, la pastille « À vérifier » du contrôle de cohérence de position (§ ci-dessus) en `badge` — le nom reste intact, plus de concaténation `"nom — état"`. Plus aucun `<select>` gymnase hors ce composant | `onValueChange`, `leadingOptions`, `sub`, `badge` | `VenuesStep`, `PeriodVenues`, `ConstraintsStep`, `ReservationPanel`, `PlacementPanel`, `cockpit/VenueUnavailabilityCard.tsx`, `cockpit/DayDialog.tsx`, `matches/ConstraintsPage.tsx` (interdiction de gymnase, PR 3/7), `matches/IdealSlotsEditor.tsx`, `planning/ExportMenu.tsx` |
| `DayMultiPicker` (`day-multi-picker.tsx`) | Le sélecteur multi-jours PARTAGÉ, maison unique (série « uniformité des écrans », PR 6/7, 2026-10-01) — patron APG **toggle button** : chaque jour est un `<button aria-pressed>` dans un `<fieldset>` dont `<legend>` nomme le groupe (fourni par l'appelant, visible ou `sr-only`). Libellé COURT visible (« Lun », `dayLabelShort`), nom accessible COMPLET (« lundi », `aria-label` via `dayLabelLong`) — un lecteur d'écran n'entend jamais « Lun, Mar ». `tone` encode une POLARITÉ de l'état pressé (`accent` = couleur du club, le défaut ; `destructive` = polarité « bloqué »), jamais un simple habillage — la forme reste identique. Les DEUX saisies de vœux coach (publique ET modale gestionnaire) sont en `accent`, couleur du club (arbitrage fondateur 2026-10-01) ; `destructive` reste une variante d'API disponible mais plus consommée par aucun écran. Valeurs ISO 1-7 inchangées côté API. Foyer des libellés de jours : `shared/lib/days.ts` (`DAYS`, `dayLabelShort`, `dayLabelLong`, `dayLabelLongCap`), absorbant les tables locales dispersées (wizard, matchs, doléances, club, gymnases, créneaux idéaux) — germe du bug D-22. Gardé par `frontend/src/test/dayPickerGuard.test.ts` (grep statique `src/features/**`/`src/app/**` : un triplet de libellés de jours consécutifs littéraux hors ce foyer rougit) | `value`, `onChange`, `legend`, `legendVisible`, `days`, `tone` (`"accent" \| "destructive"`, défaut `accent`), `disabled` | `wizard/steps/ConstraintsStep.tsx`, `matches/ConstraintsPage.tsx`, `coach-wishes/CoachWishForm.tsx` (modale interne), `coach-wishes/WishTeamStep.tsx` (page publique `/doleances/{token}`) |
| `Menu` / `MenuItem` | Dropdown accessible (burger, motif APG menu-button) — focus au 1er item à l'ouverture, flèches ↑/↓ (roving), Esc/Tab ferment + rendent le focus au déclencheur, clic-dehors, `z-50` au-dessus du plein écran wizard, sans dépendance. **Activer un item referme le menu et rend le focus au déclencheur par défaut** (`restoreFocusOnSelect`, défaut `true`, P4-207) — à mettre `false` seulement quand le déclencheur sera DÉMONTÉ par la sélection (l'appelant refocalise lui-même son remplaçant via `triggerRef`, ref externe forwardée sur le `<button>` déclencheur) | `label`, `trigger`, `children` / `onSelect` \| `to` (NavLink, état actif), `icon`, `restoreFocusOnSelect`, `triggerRef` | AppLayout (menu compte : Club · Profil · Thème · Logout), `ConflictResolutionControl` (menu de statut, `triggerRef` sur le bouton « Traiter ») |
| `FilterToggle` | La case à cocher PARTAGÉE d'un filtre d'affichage (« Seulement avec un match à domicile »…) — un `<input type="checkbox">` `size-4` + libellé `text-muted-foreground`, ligne entière cliquable (`<label>` enveloppant). Présentation seule, l'état vit chez l'appelant (miroir d'URL). Née P4-207 du patron déjà inline dans `ReviewQueue.tsx` — convertie depuis (UXC-21, 2026-09-18) : `ReviewQueue.tsx` consomme désormais `FilterToggle` pour ses deux interrupteurs, plus aucune copie locale | `checked`, `onChange`, `children` | `ConflictsPage`, `ReviewQueue` |
| `EmptyState` / `EmptyBlock` / `EmptyHint` | Les TROIS étages du vide, une seule maison (`empty-hint.tsx`, UXC-17). **Règle de choix** (UXC-10, tranchée en ralliant les sites inline) : une **vue entière** sans rien à montrer → `EmptyState` (Card pointillée) ; une **grille/panneau** vide dans un écran par ailleurs peuplé → `EmptyBlock` (bloc pointillé) ; une **liste/résultat de filtre** vide, en ligne dans le flux → `EmptyHint` (paragraphe discret). `EmptyBlock`/`EmptyHint` portent une prop `variant` (`SurfaceSkin`, P4-149, 2026-08-30) : `app` (jetons de thème, **défaut**) ou `console` (jetons `--console-*`) — même patron que les Onglets ci-dessous | `EmptyState` : `icon`, `title`, `description` ; `EmptyBlock`/`EmptyHint` : `children`, `className`, `variant` (`SurfaceSkin`, défaut `app`) | PlanningPage (State) · grilles horaires (Block) · la plupart des listes/filtres vides (Hint) · `features/admin/` (`variant="console"`) |
| `Modal` | Modal accessible (focus trap, Escape, backdrop), hauteur bornée + contenu défilant, largeur par **palier nommé** (`size`: sm/md/lg/xl — échelle et plafonds dans `MODAL_WIDTH`, `frontend-spec.md` §6.9), pied d'actions ÉPINGLÉ hors défilement (P4-127d — le pied reçoit les actions et le microcopy qui les qualifie, les conséquences restent dans le corps). Délibérément **pas de prop `className`** : six appelants avaient chacun patché leur propre `max-w-…` avant la 3ᵉ tranche de P4-107. Sa zone de contenu défilante porte une gouttière `p-1 -m-1` (padding + marge négative miroir, contenu inchangé visuellement) — ne jamais la retirer : un ancêtre `overflow-y-auto` rogne un `focus-visible:ring-2` qui déborde, un champ en bas d'une longue modale perdrait son anneau de focus pile au bord (2026-09-20, `e60fbb1f`) | `label`, `title`, `onClose`, `children`, `footer`, `size` | cockpit, wizard, matchs, planning, admin |
| `FichePage` | Le cadre des pages « fiche » (Club, Profil, Nouveautés) : 832 px centrés, paragraphes bornés à la longueur de ligne lisible (`frontend-spec.md` §6.9). Une nouvelle fiche l'utilise, elle ne recode jamais son propre `mx-auto max-w-*` | `className` (rythme vertical de la page), `children` | ClubPage, ProfilePage, ReleaseNotesPage |
| `NoticeBanner` (`notice-banner.tsx`) | Le bandeau d'information PARTAGÉ — un fait qui décrit ou limite ce qui est possible ici (semaines sous vacances, fenêtre déjà planifiée, échec réseau). Remplace `WarningPanel` (P4-265) : même structure, palette étendue par `tone` (`warning`/`accent`/`destructive`/`muted`), fond **opaque** dédié par ton — jeton `--surface-<ton>` (`bg-surface-<ton>`, `index.css`), jamais une teinte `/NN` (ne compose pas avec le fond à motifs du body). Texte toujours `text-foreground` (`--surface-accent` suit la couleur du club, un `text-accent` dessus tomberait sous l'AA). `role` optionnel, élargi à quatre valeurs : par défaut décrit un état stable (pas de région live) ; un appelant asynchrone passe `role="status"`/`role="alert"` ; `role="region"`/`"note"` (+ `ariaLabel`) servent un bandeau-repère permanent dont on veut garder le landmark. `message`/`children` séparés (l'icône ne décore que le texte, une action en `<div>` dans le même `<p>` que `message` casserait le HTML) — `message` est désormais OPTIONNEL : un bandeau-liste ou une composition riche (boutons, sous-listes) passe tout par `children`, la primitive ne fournissant alors que la boîte. **Maison unique de tous les bandeaux d'info de l'app** (série « uniformité des écrans », PR 4/7, 2026-10-01) : les derniers bandeaux faits main de Planning, Matchs, Assistant (wizard) et Cockpit y ont été ramenés — gardé par `src/test/bannerPrimitiveGuard.test.ts` (un `role="status"`/`"alert"` + une bordure de ton sur la même ligne hors `NoticeBanner`, dans `src/features/**` hors console admin, rougit) | `tone` (défaut `warning`), `icon` (décoratif), `message` (optionnel), `children` (le reste du contenu : action en FRÈRE du paragraphe, ou composition riche si `message` est omis), `role`, `ariaLabel`, `className` | Planning, Matchs, Assistant (wizard), Cockpit — tout bandeau d'info de `src/features/**` hors console admin |
| `ConfirmDialog` | Dialogue de confirmation (action destructive) — panneau jumeau de `Modal`, largeur lue dans `MODAL_WIDTH.sm` | `open`, `title`, `description`, `confirmLabel`, `confirmPhrase`, `onConfirm`, `onCancel` | Suppression d'équipe, reset club |
| `DeleteConfirm` (`delete-confirm.tsx`) | Confirmation destructive qui **annonce ses impacts** (« N réservations seront retirées ») — supprimer sans dire ce que ça emporte est exactement le bug qu'elle empêche | — | Suppression salle/équipe/coach (`frontend-wizard.md` §1) |
| `LoadErrorHint` (`load-error-hint.tsx`) | « la lecture a échoué, voici un réessai » — se combine avec `readState` (`frontend/AGENTS.md` § `readState`) | — | Tout écran/section qui gate sur une lecture échouée |
| `AccordionSection` (`accordion.tsx`) | Section dépliable (`aria-expanded`/`aria-controls`, chevron). Gagne un mode **contrôlé** opt-in (`open`/`onToggle`, rétro-compatible — omettre les deux garde l'état non contrôlé d'origine) pour un appelant qui reflète la section ouverte dans l'URL. Fermer une section contrôlée **démonte** son corps — un appelant qui garde un brouillon dedans doit accepter qu'il se perde à la fermeture. Conteneur **opaque** `bg-card` (P4-265) ; l'état OUVERT se marque par un liseré `border-l-2 border-accent` permanent (pas de fond dédié, aucun décalage de layout à l'ouverture) — le survol garde `hover:bg-accent/10` | `title`, `defaultOpen`, `children`, `open`, `onToggle` | ClubPage (Demandes / Visuel) · Importer (`ReviewQueue.tsx`, `?equipe=`) · `/matchs/configuration` (`ConfigurationPage.tsx`, 5 sections mutuellement exclusives, ancrées `?section=`) |
| `StatusPill` (`badge.tsx`) | La **seule** maison d'une pastille colorée (icône + texte, bordure + fond teinté), variantes `warning`/`accent`/`accent-solid`/`neutral` (P4-173, `accent` ajoutée P4-177, `accent-solid` ajoutée PR 5/7 série « uniformité des écrans », 2026-10-01). Les deux variantes teintées `/10` gardent leur texte `text-foreground`, jamais `text-warning`/`text-accent` (mesuré : les deux tombent sous l'AA — 4,5:1 — sur leur propre teinte `/10`) — c'est l'ICÔNE seule qui porte la couleur de ton (élément graphique, WCAG 1.4.11 ≥ 3:1) ; paires verrouillées dans `tests/e2e/a11y-contrast.spec.ts` pour les deux thèmes. `accent-solid` (fond accent PLEIN, pas de `/NN`) porte au contraire la même paire `bg-accent`/`text-accent-foreground` qu'un bouton accent (AA par construction) — un badge mis en avant (« Votre offre », « vous », provenance « Source : API FFBB »), pas un état. Enveloppe, ne tronque jamais (`whitespace-normal`) ; relaie `title`/`aria-label` pour un appelant dont l'annonce est plus riche que le texte visible (ex. `CreditBadge`) | `icon`, `children`, `variant` (`"warning" \| "accent" \| "accent-solid" \| "neutral"`), `className`, `title`, `aria-label` | `CreditBadge`, `CompromiseList`, `StalenessPill`, `CoachesStep` (« Salarié »), `VenueGeocodeField`/`AddressGeocodeField` (« Recommandé »), `TravelRuleNotice` (« Actif »), `CampaignDialog`, `ConflictRadar`, `SocleDeviationPanel`/`ToReplaceList` (raison d'un replacement), `ClubPage`/`MembersSection` (`accent-solid`), `ReconciliationView` (`accent-solid`, provenance FFBB), et `SourceBadge` (voir ci-dessous) |
| `SourceBadge` (`features/matches/SourceBadge.tsx` — **seule exception à shared/ui**, adossée à `StatusPill`) | La pastille AUTO/MANUEL du module matchs — une seule maison depuis l'absorption des deux copies locales d'origine (2026-09-19). ⚠ `TravelMatrixModal.tsx` ne la consomme plus depuis sa refonte en table N×N (couleur + graisse plutôt qu'un badge, § ci-dessus) — seul `OpponentsPage.tsx` reste consommateur | — | `OpponentsPage.tsx` |
| `StepRail` (`step-rail.tsx`) | Le rail d'étapes de gauche (`<nav className="shrink-0 md:w-44">`), extrait du wizard. Présentation **pure** : `done`/`locked` arrivent déjà CALCULÉS dans le tableau `steps` (aucune connaissance des gates de validation, du mode guidé ou des verrous métier) ; `onSelect` fait remonter le clic, l'appelant possède ses effets. Nom accessible conforme WCAG 2.5.3 (contient le libellé visible ; une étape terminée ajoute « — étape terminée »). Délibérément pas de prop `className` (même raison que `Modal`). Seul consommateur aujourd'hui : le wizard (l'ancien second usage côté module matchs a été retiré avec l'écran `MatchesPage`, remplacé par la barre `WeekCounters`) | `steps`, `onSelect` | Wizard uniquement |
| `Table` / `TableHeader` / `TableBody` / `TableRow` / `TableHead` / `TableCell` / `TableCaption` (`table.tsx`) | La table de données partagée : jetons maison, en-têtes `scope="col"`, conteneur `overflow-x-auto` (une table large défile en interne, jamais la page). `variant="inline"` : table nue (sans bordure/fond/radius, `text-xs`) pour nicher dans une carte déjà encadrée | `variant` (`"default" \| "inline"`) | Listes mois/phase (module matchs), `ConflictLine`'s `ConflictSideDetail` |
| `AddressGeocodeField` (`address-geocode-field.tsx`) | Le geste de géocapture partagé : saisir une adresse, « Localiser » (proxy `GET /api/geocode` → BAN, jamais un tiers direct), choisir un candidat — le candidat FÉDÉRAL remonte via `onPick`, l'appelant décide de son usage (lat/long d'un gymnase, ou siège de club re-géocodé serveur). N'écrase jamais un géocodage existant en silence : un état `located` affiche « Localisé »/« Siège localisé »/« Position saisie à la main » tant que « Modifier l'adresse » n'est pas cliqué explicitement. Les pastilles `StatusPill` « Recommandé »/« correspondance approximative » (P4-178) vivent ici, ainsi que l'avertissement « rue entière — ajoutez le numéro » (précision BAN `type`, lot E 2026-09-30). Prop optionnelle `onManualCoords` (lot E) ouvre l'affordance « Saisir les coordonnées » (couple `lat, lon` ou lien OSM/Google Maps, `shared/lib/parseCoordinates.ts`) — omise, l'affordance n'apparaît pas (ex. siège du club) | `onPick`, `onManualCoords?` | `wizard/steps/VenueGeocodeField.tsx` (fin wrapper, avec `onManualCoords`), `features/club/ClubPage.tsx` (`ClubSiegeSubsection`, sans `onManualCoords`) |
| `HelpButton` (`help-button.tsx`) | Le bouton d'aide contextuelle PARTAGÉ (icône `Info`, 2026-09-30) : ouvre une modale « À quoi sert cette étape/cet écran ? » dont le contenu (`children`, texte libre) est fourni par l'appelant. Maison UNIQUE — un seul patron d'aide, un seul a11y — entre l'assistant de saisie (bouton à gauche du titre d'étape, `WIZARD_STEP_HELP`) et le module Matchs (bouton à gauche de la barre d'onglets, contenu suivant l'onglet actif, `MATCHES_TAB_HELP`) | `label` (titre modale), `triggerLabel` (aria-label du bouton), `children` | `WizardLayout.tsx`, `features/matches/MatchesLayout.tsx` |
| `OpponentLogo` (`opponent-logo.tsx`) | Le logo fédéré d'un adversaire : `sm` (16 px, nu, pas de repli) / `md` (24 px, repli initiales via `features/matches/lib/opponentInitials.ts` si `hasLogo` est faux ou si `<img>` échoue), arrondi, `object-cover`, `loading="lazy"`, `alt=""` (décoratif). Récupère `GET /api/opponents/{code}/logo` seulement si `hasLogo` est vrai (booléen servi, jamais redeviné) | `hasLogo` | `features/matches/AwayList.tsx` |
| Onglets (`tabs.tsx`) | Motif WAI-ARIA (roving tabindex, flèches/Home/End), deux peaux : `console` (admin, sombre, filet `border-b border-white/10`) et `app` (club, **défaut**, barre **opaque** `bg-card` — P4-265, la nav d'onglets ne laisse plus traverser le fond à motifs). ⚠ Des onglets dans une MODALE demandent deux précautions (revue #346) : le piège à focus de `useModalA11y` ignore les sous-arbres `hidden` — sans quoi le « dernier » focusable est un bouton du panneau inactif et Tab sort du dialogue — et toute bascule d'onglet PROGRAMMATIQUE doit emporter le focus, sinon il retombe sur `<body>`. La peau elle-même vit dans un foyer partagé (`shared/lib/surfaceSkin.ts`, `SurfaceSkin = "console" \| "app"`, P4-149) — elle n'appartient à aucun composant en particulier, `tabs.tsx` la consomme (table locale `TAB_SKINS`) au même titre qu'`empty-hint.tsx` | `SurfaceSkin` | Modale de sollicitation (`features/admin/` → déplacé en `shared/` le 2026-08-01) |
| Palette console (jetons `--console-*`, `src/index.css`) | Décision UXC-12/P4-151 : chaque nuance Tailwind consommée par `features/admin/` a un jeton NOMMÉ par son rôle sémantique, construit par ALIASING (`--console-muted: var(--color-slate-500)`) — jamais une valeur `oklch` recopiée à la main — en BIJECTION stricte (une nuance = un jeton). Hors du système de thème clair/sombre de l'app (décision fermée, `etat-des-lieux.md` §2). `white`/`black` restent des littéraux (même décision). Gardé par `consolePalette.guard.test.ts` (`features/admin/`) | — | `features/admin/` |
| `BrandIcon` (`brand-icon.tsx`) | La marque produit elle-même (trois arcs, en-tête d'`AppLayout` + `frontend/public/favicon.svg`), décorative par défaut. Ses couleurs de trait sont des littéraux `#hex` codés en dur **volontairement** — une exception admise à « jamais un `#hex` » (avec `BrandSplash`, § « Splash de connexion » ci-dessus), un logo ayant des tons fixes par définition, pas un jeton thématisable (`.claude/rules/frontend.md` porte la règle) | — | `AppLayout`, favicon |
| `BrandMark` (`brand-mark.tsx`) | Le logo COMPLET (`BrandIcon` + le mot produit), la maison unique partout où le produit se nomme comme MARQUE plutôt que dans une phrase : `AuthLayout`, `system-screen`, `AdminAuthLayout`. Le mot ne porte AUCUNE couleur codée en dur — il hérite `currentColor`, un seul ton dans chaque thème ; seul `BrandIcon` porte les arcs `#hex`. `role="img"` nommé `PRODUCT_NAME`, visuel `aria-hidden` | — | `AuthLayout`, `AdminAuthLayout`, `system-screen` |

---

Détail des pièges d'accessibilité et de couleur transverses (contraste, opacité de texte, accent
club) : `frontend/AGENTS.md` gotchas #7/#11 et `.claude/rules/frontend.md`. Détail de
`readState`/`ActionVeil`/le geste « done » : `frontend/AGENTS.md`.
