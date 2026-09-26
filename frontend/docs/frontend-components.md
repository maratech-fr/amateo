# Conventions API, Layout et primitives UI partagées

Last verified @ 2026-09-26 (rotation de fraîcheur `documentation-update`, sujet sans rapport —
passe doc zone engine). Re-confronté au code : `frontend/src/app/AppLayout.tsx` garde une racine
`min-h-screen text-foreground` (sans `bg-background`), seul le `<header>` le porte
(`bg-background`, ligne ~55) ; `AuthLayout.tsx` ne porte pas non plus `bg-background` sur sa
racine ✓. **§3 primitives UI** re-sondé : `listbox.tsx` exporte toujours
`ListboxOption`/`ListboxGroup`/`ListboxProps` ✓ ; `filter-toggle.tsx` prend toujours
`checked`/`onChange`/`children` ✓ ; `badge.tsx` porte toujours `StatusPill` avec
`variant: "warning" | "accent" | "neutral"` ✓. §1 (endpoints) non re-sondé cette passe — dernière
confrontation à `specs/courantes/openapi-snapshot.json` : `/api/priority_tiers`,
`/api/schedule_diagnostics`, `/api/team_coaches` y existaient tels quels. Reste non re-parcouru
cette passe — historique : `git log -p --follow` sur ce fichier. **§3 est la maison unique des
primitives UI partagées** (décision fondateur 2026-09-26) : les entrées déménagées depuis
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
- `DevClock`, seulement en `import.meta.env.DEV`.
- La nav de droite (`<nav>`, ne se rétracte JAMAIS, y compris sous 360 px — décision fondateur
  desktop-first/mobile V2 ; le débordement horizontal résiduel de l'en-tête à cette largeur est
  une dette DISTINCTE, en Vision) : `CreditBadge`, `SeasonSelector`, l'item « Matchs »
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
signalée comme telle. Au-delà de l'évident (`button`, `input`, `select`, `card`, `label`), les
entrées suivantes portent une règle produit ou un piège d'accessibilité — les réutiliser plutôt
qu'en récrire une copie locale.

| Composant | Rôle | Props clés | Utilisé par |
|-----------|------|------------|-------------|
| `Button` | Bouton avec variants + sizes. **Désactivé, il INFORME** (P4-127e) : plus de `pointer-events-none` — les `title=` d'explication vivent, curseur `not-allowed`, survols bornés aux boutons actifs (`hover:enabled:`) ; la doctrine « raison en clair à côté » reste la norme pour les cas importants | `variant`, `size`, `disabled`, `children` | Toutes les pages |
| `Listbox` | Sélecteur riche à choix unique — patron APG listbox, trigger `button` (nom accessible = libellé + valeur), roving `tabIndex` (pas `aria-activedescendant`), Escape arrête sa propagation (ne remonte jamais dans une modale hôte), Tab ferme sans sélectionner, couleur/icône, compte, sous-ligne, option désactivée motivée (visible, jamais retirée). Maison des sélecteurs qui dépassent le `<select>` natif (`select.tsx` garde les ~20 pickers simples : jours, statuts, catégorie, durée…). **Recherche dans le panneau à partir de 8 options réelles** (P4-198, `SEARCH_THRESHOLD` — placeholder et `leadingOptions` exclus du seuil et du filtre) : combobox éditable délibérément écarté (aucun gain a11y, casse la lecture du trigger), le panneau devient alors un wrapper `[input + div role="listbox"]` (un `<input>` n'est pas un enfant valide de `role="listbox"`) ; sous le seuil, DOM et comportement byte-identiques. **Panneau PORTÉ sous `document.body` en `position: fixed`** (2026-09-15) : il dépasse une modale ou une zone défilante comme un `<select>` natif — géométrie prise sur le rect du trigger (re-mesurée sur `resize`/`scroll`), bascule vers le haut contre le viewport, `z-[100]` au-dessus des modales, `aria-controls` trigger → panneau, `mousedown` du panneau arrêté avant `document`. Helper de test : `src/test/pickListboxOption.ts` (ouvrir + choisir par libellé) | `value`, `onValueChange`, `options` \| `groups`, `leadingOptions` (options de tête toujours visibles), `placeholder`, `searchLabel` (nom accessible du champ de recherche, défaut « Rechercher ») | `team-select.tsx`, `venue-select.tsx` (tous deux migrés, P4-164) |
| `TeamSelect` | Chaque picker d'équipe de l'app (contraintes, coachs, matchs, import FBI) — bâti sur `Listbox` (P4-164 PR-1) : groupé par rang de priorité, même ordre que l'étape Équipes, **couleur du rang** comme pastille (une équipe n'a pas de couleur propre — décision fondateur, pas de champ backend, le `color` du tier est réutilisé). Les appelants passent `onValueChange` (pas un `onChange` DOM) et, en option, `optionMeta(team)` pour un compte/sous-ligne/désactivé aligné à droite, au lieu de l'ancien suffixe texte `optionLabel`. Reclasser une équipe met à jour l'ordre **partout** | `onValueChange`, `optionMeta` | contraintes, coachs, matchs, `ImportFbiDialog` |
| `VenueSelect` | Chaque picker de gymnase de l'app — reconstruit sur `Listbox` (P4-164 PR-2) : pastille `Venue.color` sur **chaque option ET le trigger** (l'ancienne limite « la liste ouverte reste textuelle » a disparu avec le `<select>` natif) ; API `onValueChange`, `placeholder` sélectionnable (valeur `""`), `leadingOptions` typées, l'état effectif de période d'un gymnase (désactivé / jour fermé / indisponible) en `sub` — le nom reste intact, plus de concaténation `"nom — état"`. Plus aucun `<select>` gymnase hors ce composant | `onValueChange`, `leadingOptions`, `sub` | `VenuesStep`, `PeriodVenues`, `ConstraintsStep`, `ReservationPanel`, `PlacementPanel`, `cockpit/VenueUnavailabilityCard.tsx`, `cockpit/DayDialog.tsx`, `matches/ConfigurationPage.tsx`, `matches/MatchSlotRotationsEditor.tsx`, `matches/HabitsLinksDialog.tsx`, `planning/ExportMenu.tsx` |
| `Menu` / `MenuItem` | Dropdown accessible (burger, motif APG menu-button) — focus au 1er item à l'ouverture, flèches ↑/↓ (roving), Esc/Tab ferment + rendent le focus au déclencheur, clic-dehors, `z-50` au-dessus du plein écran wizard, sans dépendance. **Activer un item referme le menu et rend le focus au déclencheur par défaut** (`restoreFocusOnSelect`, défaut `true`, P4-207) — à mettre `false` seulement quand le déclencheur sera DÉMONTÉ par la sélection (l'appelant refocalise lui-même son remplaçant via `triggerRef`, ref externe forwardée sur le `<button>` déclencheur) | `label`, `trigger`, `children` / `onSelect` \| `to` (NavLink, état actif), `icon`, `restoreFocusOnSelect`, `triggerRef` | AppLayout (menu compte : Club · Profil · Thème · Logout), `ConflictResolutionControl` (menu de statut, `triggerRef` sur le bouton « Traiter ») |
| `FilterToggle` | La case à cocher PARTAGÉE d'un filtre d'affichage (« Seulement avec un match à domicile »…) — un `<input type="checkbox">` `size-4` + libellé `text-muted-foreground`, ligne entière cliquable (`<label>` enveloppant). Présentation seule, l'état vit chez l'appelant (miroir d'URL). Née P4-207 du patron déjà inline dans `ReviewQueue.tsx` — convertie depuis (UXC-21, 2026-09-18) : `ReviewQueue.tsx` consomme désormais `FilterToggle` pour ses deux interrupteurs, plus aucune copie locale | `checked`, `onChange`, `children` | `ConflictsPage`, `ReviewQueue` |
| `EmptyState` / `EmptyBlock` / `EmptyHint` | Les TROIS étages du vide, une seule maison (`empty-hint.tsx`, UXC-17). **Règle de choix** (UXC-10, tranchée en ralliant les sites inline) : une **vue entière** sans rien à montrer → `EmptyState` (Card pointillée) ; une **grille/panneau** vide dans un écran par ailleurs peuplé → `EmptyBlock` (bloc pointillé) ; une **liste/résultat de filtre** vide, en ligne dans le flux → `EmptyHint` (paragraphe discret). `EmptyBlock`/`EmptyHint` portent une prop `variant` (`SurfaceSkin`, P4-149, 2026-08-30) : `app` (jetons de thème, **défaut**) ou `console` (jetons `--console-*`) — même patron que les Onglets ci-dessous | `EmptyState` : `icon`, `title`, `description` ; `EmptyBlock`/`EmptyHint` : `children`, `className`, `variant` (`SurfaceSkin`, défaut `app`) | PlanningPage (State) · grilles horaires (Block) · la plupart des listes/filtres vides (Hint) · `features/admin/` (`variant="console"`) |
| `Modal` | Modal accessible (focus trap, Escape, backdrop), hauteur bornée + contenu défilant, largeur par **palier nommé** (`size`: sm/md/lg/xl — échelle et plafonds dans `MODAL_WIDTH`, `frontend-spec.md` §6.9), pied d'actions ÉPINGLÉ hors défilement (P4-127d — le pied reçoit les actions et le microcopy qui les qualifie, les conséquences restent dans le corps). Délibérément **pas de prop `className`** : six appelants avaient chacun patché leur propre `max-w-…` avant la 3ᵉ tranche de P4-107. Sa zone de contenu défilante porte une gouttière `p-1 -m-1` (padding + marge négative miroir, contenu inchangé visuellement) — ne jamais la retirer : un ancêtre `overflow-y-auto` rogne un `focus-visible:ring-2` qui déborde, un champ en bas d'une longue modale perdrait son anneau de focus pile au bord (2026-09-20, `e60fbb1f`) | `label`, `title`, `onClose`, `children`, `footer`, `size` | cockpit, wizard, matchs, planning, admin |
| `FichePage` | Le cadre des pages « fiche » (Club, Profil, Nouveautés) : 832 px centrés, paragraphes bornés à la longueur de ligne lisible (`frontend-spec.md` §6.9). Une nouvelle fiche l'utilise, elle ne recode jamais son propre `mx-auto max-w-*` | `className` (rythme vertical de la page), `children` | ClubPage, ProfilePage, ReleaseNotesPage |
| `WarningPanel` | L'encart d'AVERTISSEMENT — un fait qui limite ce qui est possible ici (semaines sous vacances, fenêtre déjà planifiée). **Une seule boîte** depuis P4-127 : trois sites la re-déclaraient avec deux bordures différentes. ⚠ Ce n'est **pas** une alerte d'erreur : ni `role="alert"` ni `aria-live` — quand le contenu arrive de façon asynchrone, c'est le CONTENEUR de l'appelant qui porte la région live | `icon` (décoratif), `message`, `children` (l'action, en FRÈRE du paragraphe), `className` | `WindowAlreadyPlannedNotice`, `WeekPickerDialog`, `DayDialog` |
| `ConfirmDialog` | Dialogue de confirmation (action destructive) — panneau jumeau de `Modal`, largeur lue dans `MODAL_WIDTH.sm` | `open`, `title`, `description`, `confirmLabel`, `confirmPhrase`, `onConfirm`, `onCancel` | Suppression d'équipe, reset club |
| `DeleteConfirm` (`delete-confirm.tsx`) | Confirmation destructive qui **annonce ses impacts** (« N réservations seront retirées ») — supprimer sans dire ce que ça emporte est exactement le bug qu'elle empêche | — | Suppression salle/équipe/coach (`frontend-wizard.md` §1) |
| `LoadErrorHint` (`load-error-hint.tsx`) | « la lecture a échoué, voici un réessai » — se combine avec `readState` (`frontend/AGENTS.md` § `readState`) | — | Tout écran/section qui gate sur une lecture échouée |
| `AccordionSection` (`accordion.tsx`) | Section dépliable (`aria-expanded`/`aria-controls`, chevron). Gagne un mode **contrôlé** opt-in (`open`/`onToggle`, rétro-compatible — omettre les deux garde l'état non contrôlé d'origine) pour un appelant qui reflète la section ouverte dans l'URL. Fermer une section contrôlée **démonte** son corps — un appelant qui garde un brouillon dedans doit accepter qu'il se perde à la fermeture | `title`, `defaultOpen`, `children`, `open`, `onToggle` | ClubPage (Demandes / Visuel) · Importer (`ReviewQueue.tsx`, `?equipe=`) · `/matchs/configuration` (`ConfigurationPage.tsx`, 5 sections mutuellement exclusives, ancrées `?section=`) |
| `StatusPill` (`badge.tsx`) | La **seule** maison d'une pastille colorée (icône + texte, bordure + fond teinté), variantes `warning`/`accent`/`neutral` (P4-173, `accent` ajoutée P4-177). Les deux variantes teintées gardent leur texte `text-foreground`, jamais `text-warning`/`text-accent` (mesuré : les deux tombent sous l'AA — 4,5:1 — sur leur propre teinte `/10`) — c'est l'ICÔNE seule qui porte la couleur de ton (élément graphique, WCAG 1.4.11 ≥ 3:1) ; paires verrouillées dans `tests/e2e/a11y-contrast.spec.ts` pour les deux thèmes. Enveloppe, ne tronque jamais (`whitespace-normal`) ; relaie `title`/`aria-label` pour un appelant dont l'annonce est plus riche que le texte visible (ex. `CreditBadge`) | `icon`, `children`, `variant` (`"warning" \| "accent" \| "neutral"`), `className`, `title`, `aria-label` | `CreditBadge`, `CompromiseList`, `StalenessPill`, `CoachesStep` (« Salarié »), `VenueGeocodeField`/`AddressGeocodeField` (« Recommandé »), `TravelRuleNotice` (« Actif »), `CampaignDialog`, `ConflictRadar`, et `SourceBadge` (voir ci-dessous) |
| `SourceBadge` (`features/matches/SourceBadge.tsx` — **seule exception à shared/ui**, adossée à `StatusPill`) | La pastille AUTO/MANUEL du module matchs — une seule maison depuis l'absorption des deux copies locales d'origine (2026-09-19) | — | `TravelMatrixModal.tsx`, `OpponentsPage.tsx` |
| `StepRail` (`step-rail.tsx`) | Le rail d'étapes de gauche (`<nav className="shrink-0 md:w-44">`), extrait du wizard. Présentation **pure** : `done`/`locked` arrivent déjà CALCULÉS dans le tableau `steps` (aucune connaissance des gates de validation, du mode guidé ou des verrous métier) ; `onSelect` fait remonter le clic, l'appelant possède ses effets. Nom accessible conforme WCAG 2.5.3 (contient le libellé visible ; une étape terminée ajoute « — étape terminée »). Délibérément pas de prop `className` (même raison que `Modal`). Seul consommateur aujourd'hui : le wizard (l'ancien second usage côté module matchs a été retiré avec l'écran `MatchesPage`, remplacé par la barre `WeekCounters`) | `steps`, `onSelect` | Wizard uniquement |
| `Table` / `TableHeader` / `TableBody` / `TableRow` / `TableHead` / `TableCell` / `TableCaption` (`table.tsx`) | La table de données partagée : jetons maison, en-têtes `scope="col"`, conteneur `overflow-x-auto` (une table large défile en interne, jamais la page). `variant="inline"` : table nue (sans bordure/fond/radius, `text-xs`) pour nicher dans une carte déjà encadrée | `variant` (`"default" \| "inline"`) | Listes mois/phase (module matchs), `ConflictLine`'s `ConflictSideDetail` |
| `AddressGeocodeField` (`address-geocode-field.tsx`) | Le geste de géocapture partagé : saisir une adresse, « Localiser » (proxy `GET /api/geocode` → BAN, jamais un tiers direct), choisir un candidat — le candidat FÉDÉRAL remonte via `onPick`, l'appelant décide de son usage (lat/long d'un gymnase, ou siège de club re-géocodé serveur). N'écrase jamais un géocodage existant en silence : un état `located` affiche « Localisé »/« Siège localisé » tant que « Modifier l'adresse » n'est pas cliqué explicitement. Les pastilles `StatusPill` « Recommandé »/« correspondance approximative » (P4-178) vivent ici | `onPick` | `wizard/steps/VenueGeocodeField.tsx` (fin wrapper), `features/club/ClubPage.tsx` (`ClubSiegeSubsection`) |
| `OpponentLogo` (`opponent-logo.tsx`) | Le logo fédéré d'un adversaire : `sm` (16 px, nu, pas de repli) / `md` (24 px, repli initiales via `features/matches/lib/opponentInitials.ts` si `hasLogo` est faux ou si `<img>` échoue), arrondi, `object-cover`, `loading="lazy"`, `alt=""` (décoratif). Récupère `GET /api/opponents/{code}/logo` seulement si `hasLogo` est vrai (booléen servi, jamais redeviné) | `hasLogo` | `features/matches/AwayList.tsx` |
| Onglets (`tabs.tsx`) | Motif WAI-ARIA (roving tabindex, flèches/Home/End), deux peaux : `console` (admin, sombre) et `app` (club, **défaut**). ⚠ Des onglets dans une MODALE demandent deux précautions (revue #346) : le piège à focus de `useModalA11y` ignore les sous-arbres `hidden` — sans quoi le « dernier » focusable est un bouton du panneau inactif et Tab sort du dialogue — et toute bascule d'onglet PROGRAMMATIQUE doit emporter le focus, sinon il retombe sur `<body>`. La peau elle-même vit dans un foyer partagé (`shared/lib/surfaceSkin.ts`, `SurfaceSkin = "console" \| "app"`, P4-149) — elle n'appartient à aucun composant en particulier, `tabs.tsx` la consomme (table locale `TAB_SKINS`) au même titre qu'`empty-hint.tsx` | `SurfaceSkin` | Modale de sollicitation (`features/admin/` → déplacé en `shared/` le 2026-08-01) |
| Palette console (jetons `--console-*`, `src/index.css`) | Décision UXC-12/P4-151 : chaque nuance Tailwind consommée par `features/admin/` a un jeton NOMMÉ par son rôle sémantique, construit par ALIASING (`--console-muted: var(--color-slate-500)`) — jamais une valeur `oklch` recopiée à la main — en BIJECTION stricte (une nuance = un jeton). Hors du système de thème clair/sombre de l'app (décision fermée, `etat-des-lieux.md` §2). `white`/`black` restent des littéraux (même décision). Gardé par `consolePalette.guard.test.ts` (`features/admin/`) | — | `features/admin/` |
| `BrandIcon` (`brand-icon.tsx`) | La marque produit elle-même (trois arcs, en-tête d'`AppLayout` + `frontend/public/favicon.svg`), décorative par défaut. Ses couleurs de trait sont des littéraux `#hex` codés en dur **volontairement** — la seule exception admise à « jamais un `#hex` », un logo ayant des tons fixes par définition, pas un jeton thématisable (`.claude/rules/frontend.md` porte la règle) | — | `AppLayout`, favicon |
| `BrandMark` (`brand-mark.tsx`) | Le logo COMPLET (`BrandIcon` + le mot produit), la maison unique partout où le produit se nomme comme MARQUE plutôt que dans une phrase : `AuthLayout`, `system-screen`, `AdminAuthLayout`. Le mot ne porte AUCUNE couleur codée en dur — il hérite `currentColor`, un seul ton dans chaque thème ; seul `BrandIcon` porte les arcs `#hex`. `role="img"` nommé `PRODUCT_NAME`, visuel `aria-hidden` | — | `AuthLayout`, `AdminAuthLayout`, `system-screen` |

---

Détail des pièges d'accessibilité et de couleur transverses (contraste, opacité de texte, accent
club) : `frontend/AGENTS.md` gotchas #7/#11 et `.claude/rules/frontend.md`. Détail de
`readState`/`ActionVeil`/le geste « done » : `frontend/AGENTS.md`.
