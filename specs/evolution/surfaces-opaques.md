# Surfaces opaques sur le fond à motifs — le besoin, l'état des lieux, ce qui a été tranché

> **Cadrage VALIDÉ 2026-09-26 — prochain geste : planner** (pas de re-cadrage). Rattachement
> roadmap : **P4-265**.

## Besoin (fondateur, 2026-09-26)

« J'aime beaucoup le fond d'écran mais beaucoup de tuiles sont transparentes ; par exemple
l'accordéon par coach de l'écran Conflits. Je veux un fond opaque en gardant la surbrillance. »

Reformulé : **toute surface qui porte du texte est opaque** sur le fond à motifs posé par `body`
(`specs/courantes/identite-visuelle-produit.md`). La surbrillance (survol, focus, état ouvert)
reste visible — elle ne disparaît pas, elle change de forme quand le fond au repos devient plein.

## Constats vérifiés

- **Primitive accordéon** (`frontend/src/shared/components/ui/accordion.tsx:43`) : le conteneur
  ne porte que `rounded-lg border border-border`, aucune classe `bg-*` — le fond à motifs traverse
  au repos. Survol `hover:bg-accent/10` (ligne 49) reste visible tel quel. État ouvert = le
  chevron seul tourne (ligne 52) ; le corps (ligne 55, `border-t border-border px-4 py-4`) n'a
  pas de fond non plus. Consommée par `ConflictsPage.tsx:588`, `ReviewQueue.tsx:216`,
  `ConfigurationPage.tsx` (4 sections, 133-149), `ClubPage.tsx` (9 sections, 681-734), et le
  wizard — `RecapStep.tsx`, `StructureSummary.tsx`, `PeriodTeams.tsx`, `VenuesStep.tsx`.
- **`ConflictLine.tsx:355-359`** (`TONE_CLASSES`) : les lignes du détail par côté d'un conflit
  portent des teintes `/5`/`/30` (`bg-destructive/5`, `bg-warning/5`, `bg-muted/30`) — dans le
  corps de l'accordéon ci-dessus, donc doublement transparentes.
- **Onglets** (`shared/components/ui/tabs.tsx:27-33`, peau `app`) : aucune classe `bg-*` sur la
  liste ni les boutons. Nav d'onglets de `MatchesLayout.tsx:95` : `border-b border-border` seul.
- **Motif bandeau recopié** — `rounded-{md,lg} border border-{ton}/NN bg-{ton}/{5,10}`, retrouvé
  identique dans plusieurs fichiers indépendants : `PlanningPage.tsx:642`,
  `WeekWorkbench.tsx:284`, `LeagueValidation.tsx:97`, `CockpitPage.tsx:72`,
  `SeasonTransitionBanner.tsx:44`, `CreditsBanner.tsx:37`, `DriftBanner.tsx:25`,
  `ModuleVisitBanner.tsx:30`. `warning-panel.tsx:29` (la primitive `WarningPanel`, seule maison
  partagée existante) porte le même `bg-warning/10` — donc transparente elle aussi. Une partie de
  ces sites vivent dans des modales, qui sont elles-mêmes opaques (`modal.tsx:80`,
  `bg-card`) : le bandeau y est visuellement plus transparent que son propre conteneur.
- **`FbiDeadlineCard.tsx:45`** (`const tone`) : `bg-card` au repos, mais bascule sur
  `bg-warning/5`/`bg-accent/5` dès qu'elle porte une alerte — elle PERD son fond au moment précis
  où elle a le plus besoin d'être lisible.
- **Barres de filtres et listes bordées sans fond** (même patron `rounded-md border border-border`
  sans `bg-*`) : `ConfigurationPage.tsx:203,218` (listes de gymnases), `CalendarControls.tsx:116`
  et `:185` (groupes segmentés), `MatchesFilterBar.tsx:51`, `PlanningToolbar.tsx:215`,
  `OpponentsPage.tsx:200`, `ConflictsPage.tsx:462`, `ReleaseNotesPage.tsx:38`,
  `EntryDeadlinesEditor.tsx:121`, `MatchWindowsEditor.tsx:54`,
  `MatchSlotRotationsEditor.tsx:148` et `:298`, `ConstraintsStep.tsx:1003`,
  `features/matches/TeamLinksSection.tsx:72` et `:148`.
- **Déjà opaques** : `Card` (`card.tsx:8`, `bg-card`), `Modal` (`modal.tsx:80`, `bg-card`),
  `Listbox` (popup, `listbox.tsx:81`, `bg-card`), `EmptyBlock` (`empty-hint.tsx:20`, `bg-card`).
  `Menu` (popup, `menu.tsx:138`) porte `bg-background`, pas `bg-card` — **incohérence notée, pas
  reprise dans ce lot** (le popup d'un menu flotte au-dessus de tout, `bg-background` y reste
  lisible ; l'harmoniser sur `bg-card` est un plus, pas un correctif).
- **Jetons de thème** — `--background`/`--card` définis en clair (`index.css:19-23`) et en sombre
  (`index.css:74-78`) : les deux existent déjà, rien à créer côté couleur de base.
- **Contrastes mesurés** surtout en clair, sur `--card` (`index.css:18-57`) — le thème SOMBRE n'a
  pas eu la même passe de mesure et doit être revérifié une fois les nouveaux fonds posés.
- **Règle actuelle limitée** : `specs/courantes/identite-visuelle-produit.md:173-179` et
  `.claude/rules/frontend.md:81-88` ne couvrent que l'en-tête d'`AppLayout` et les cartes — rien
  pour les surfaces intermédiaires listées ci-dessus.
- **Aucun gate automatisé ne couvre ce motif** : `expectNoContrastViolations`
  (`frontend/tests/e2e/support.ts:112-121`) ne juge que le contraste d'un point donné (axe
  `color-contrast`), jamais la présence d'un fond — un texte lisible sur `background-image` par
  hasard de couleur passerait ce gate sans jamais avoir de fond réel. Le seul garde-fou structurel
  existant, `frontend/src/test/textOpacityGuard.test.ts`, porte sur l'OPACITÉ du texte lui-même
  (A11Y-22), pas sur le fond de son conteneur — un modèle de garde statique à imiter, pas un garde
  qui couvre déjà ce sujet.

## Décisions VALIDÉES par le fondateur le 2026-09-26

1. **Tuiles = `bg-card`** (blanc en clair, anthracite en sombre), jamais `bg-background`.
   Exemple : `accordion.tsx:43` `rounded-lg border border-border` devient
   `rounded-lg border border-border bg-card text-card-foreground`.
2. **Accordéon** : survol `hover:bg-accent/10` gardé tel quel ; **l'état ouvert se marque par un
   liseré teal à gauche de l'en-tête** (`border-l-2 border-accent`), pas par un fond. Interdit :
   un `hover:bg-muted/50` posé sur une carte — `--muted` est bien plus proche de `--background`
   que de `--card` (`index.css:19,21,23`), l'effet y serait quasi invisible.
3. **Bandeaux** : une **primitive partagée** (étendre `WarningPanel` ou créer un `NoticeBanner` —
   au planner de choisir), en variantes warning/accent/destructive/muted, sur des jetons de fond
   **OPAQUES** dédiés — `--surface-<ton>: color-mix(in oklab, var(--<ton>) 10%, var(--card))`
   (+ valeurs sombres dédiées, pas une dérivation automatique). ⚠ `bg-card bg-warning/10` dans une
   même classe ne compose pas (Tailwind n'empile pas deux `background-color`) : il faut le jeton
   `--surface-*` en dur. **Tous les bandeaux migrent, y compris ceux posés dans une modale**
   (uniformité — un bandeau ne doit pas changer de nature selon son conteneur).
4. **Onglets** : **barre opaque** portant la nav (`rounded-lg border border-border bg-card`), le
   trait actif teal (`border-accent`) inchangé.
5. **`EmptyHint`** (texte nu « Aucun… ») : **inchangé** — ce n'est pas une tuile, c'est un état
   vide, il n'a jamais prétendu porter de fond.
6. **Boutons `outline`/`ghost` et `FilterChip`** : **restent transparents** — c'est leur
   conteneur (barre de filtres, groupe segmenté) qui devient opaque, pas le contrôle lui-même.
7. **Barres de filtres et listes bordées** : `bg-card` posé sur le CONTENEUR (`<div role="group">`,
   `<ul>`), pas sur chaque item.
8. **`FbiDeadlineCard`** : ne perd plus jamais son fond — l'état alerte devient
   `bg-surface-warning` au lieu de `bg-warning/5`.
9. **Règle écrite** (maisons canoniques inchangées — `identite-visuelle-produit.md` et
   `.claude/rules/frontend.md`, étendues) : *« une surface qui porte du texte est opaque
   (`bg-card` ou un `--surface-*`) ; une teinte `/NN` n'est jamais le fond principal d'une surface
   posée sur le motif. »* **Garde-fou** : test vitest statique, limité aux primitives
   `shared/components/ui/*` (même modèle que `textOpacityGuard.test.ts` — grep des classes plutôt
   qu'un rendu, puisque jsdom ne calcule ni layout ni fond réel).
10. **Une seule PR** : jetons + primitives d'abord (accordion, tabs, WarningPanel/NoticeBanner,
    listes bordées), puis les sites qui les consomment.

## Hors périmètre

Mobile (desktop-first, V2 — `.claude/rules` decision mémorisée) · console superadmin (peau
`console`, pas de motif — `.claude/rules/frontend.md` §skins) · `landing/` et `system-pages/`
(hors app) · `GenerationScene`/`system-screen` (fond déjà chargé, décision antérieure conservée) ·
aucun changement d'API, de schéma ou de comportement métier — présentation seule.

## Pour le planner

- **Zone** : `frontend/` seule. **Aucun axe §7.1** touché (CLAUDE.md) — pas de NR, pas de Behat.
- **Preuve attendue** : `a11y-contrast.spec.ts` verra enfin ces textes posés sur un fond uni (au
  lieu du fond à motifs derrière un texte transparent) ; contrastes du thème SOMBRE re-mesurés sur
  `--card`/`--surface-*` (le clair l'était déjà, le sombre non — constat ci-dessus) ; passe
  visuelle clair/sombre, captures dans `captures/` ; passe `ui-ux-pro-max`
  (`.claude/rules/frontend.md`) sur les primitives modifiées ; rebuild de l'image tooling avant
  test (`docker compose --profile tools build frontend-tooling`).
- **Tests existants à mettre à jour** : `accordion.test.tsx`, `warning-panel.test.tsx` (et tout
  test qui asserte la classe littérale d'un des sites listés ci-dessus).
