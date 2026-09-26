# Identité visuelle par club (logo + couleur d'accent)

Last verified @ 2026-09-26 (`documentation-update`, passe « le présent seulement » — bloc
`<details>` (base de réflexion initiale, superseded) supprimé, tout le vivant qu'il portait était
déjà remonté dans l'en-tête « Reste ⬜ » ; ids/dates de PR retirés du corps. Re-vérifié contre le
code : `Club.logoUrl`/`accentColor`/`accentColorDark`/`accentPalette`
(`backend/src/Entity/Club.php:138-154`) ✓, `PATCH /api/club/appearance`
(`ClubAppearanceController::__invoke`, `:34`) ✓, `GenerationWaiting.tsx` sans prop
`logoUrl`/`initial` ✓, `frontend/src/app/AppLayout.tsx` — `BrandIcon` toujours rendue avant le
blason du club, aucun repli glyphe générique ✓. Historique : `git log -p --follow
specs/courantes/identite-visuelle-club.md`.

> **Ce que porte cette identité** : accent par club + logo + extraction 3 couleurs + écran
> « Gestion du club ». Ce qui reste ⬜ est du confort (voir « Reste ⬜ » ci-dessous).
>
> `Club.accentColor` (**accent clair**) + `Club.accentColorDark` (**accent sombre distinct**, nullable → dérivé de l'accent clair si absent) + `accentPalette` + `logoUrl` (backend) · endpoint `PATCH /api/club/appearance` (accentColor · accentColorDark · palette) · upload/serve logo via abstraction `LogoStorage` (locale en dev, swappable prod) `POST/DELETE /api/club/logo` + `GET /api/clubs/{id}/logo` (public) · application front `--accent`/`--accent-foreground` (AA auto) via `useApplyClubTheme` — **une couleur par mode** : l'accent sombre explicite est appliqué tel quel en dark, sinon dérivation legible de l'accent clair · écran `/club` (hub) section Identité (**deux sélecteurs accent : thème clair + thème sombre** ; upload logo + **cropper cercle : zoom + cadrage, export PNG cadré**, y compris recadrage du logo existant + extraction 3 couleurs → suggestion accent + palette) · affichage logo **header seul** · **onglet de nav actif = pill accent** (AA-safe) · **barre d'accent (`border-l`) sur les titres de page** (Planning · Profil · Gestion du club · Matchs) pour une présence accent générale.
>
> **L'écran d'attente de génération n'affiche pas le logo/l'initiale du club** (décision fermée,
> [`etat-des-lieux.md`](etat-des-lieux.md) §2) : le centre de l'écran porte une **scène animée**
> (mini-grille de créneaux qui se remplissent, ballon qui rebondit, terrain en filigrane) — aucun
> logo ni initiale par-dessus, la scène EST le contenu. Elle reste sur MARQUE sans logo : ses
> couleurs lisent uniquement les tokens de thème (`--card`/`--muted`/`--border`/`--muted-foreground`)
> et l'accent du club via `--accent` — jamais de littéral de couleur, jamais de logo re-fetché.
> Comportement détaillé (composition de la scène, `prefers-reduced-motion`) :
> [`frontend-spec.md`](../../frontend/docs/frontend-spec.md) §6.2. `GenerationWaiting` ne prend
> aucune prop `initial`/`logoUrl` — `GenerateStep` n'appelle donc plus `useMe()` ; `PlanningPage`
> le garde (autres usages : renommage, logo affiché ailleurs sur la page).
>
> **Reste ⬜** : stockage prod réel (impl S3/objet à écrire quand la cible est connue) · usage plus poussé des 3 couleurs (teintes signature au-delà de `--accent-2`) · login marque produit (inchangé) · assets **par sport** pour cette scène (la scène reste générique basketball codée en dur — le fond d'écran commun, `identite-visuelle-produit.md` § « Le fond d'écran », est lui aussi générique multi-sport, il ne solde pas ce point) : dépriorisé derrière la ligne « Multi-sport » de `roadmap.md` (🔴, attendre une vraie demande).
>
> **Le logo s'uploade après la création du club**, dans l'écran « Gestion du club » (pas à
> l'inscription) : l'inscription reste minimale (nom/email/ffbb, friction basse — l'utilisateur
> n'a souvent pas son logo sous la main) ; l'identité est un réglage fait ensuite, potentiellement
> par un autre gestionnaire.

## Réfs (à jour)

- Application de l'accent : `frontend/src/shared/hooks/useApplyClubTheme.ts` (lit `accentColor` / `accentColorDark` / `accentPalette` depuis `/api/me`, dérive `--accent-foreground` en AA). **Un club SANS couleur ne retombe plus sur des jetons CSS statiques indépendants** : il dérive l'accent PRODUIT (`PRODUCT_ACCENT`, `shared/lib/product.ts`) par la MÊME voie qu'un accent de club — détail, table des jetons et garde de parité : [`identite-visuelle-produit.md`](identite-visuelle-produit.md).
- Design tokens : `frontend/src/index.css` (`@theme`, slots `--accent`).
- Mode clair/sombre : `frontend/src/shared/stores/themeStore.ts` (+ slot `accent`).
- **Pré-paint du thème** : `frontend/src/main.tsx` (`readPersistedThemeMode`) pose la classe `.dark` **avant** le premier rendu React. Sans lui, l'arbre se rend en clair puis un effet bascule : flash du mauvais thème **et** animation `transition-colors` qui laisse les surfaces à des couleurs intermédiaires **sub-AA** (A11Y-06).
- Écran de réglage : `frontend/src/features/club/ClubPage.tsx` + `LogoCropper.tsx`.
- Surfaces de marque : `frontend/src/app/AppLayout.tsx` — l'en-tête pose **la marque PRODUIT
  d'abord** : icône `BrandIcon` (`shared/components/ui/brand-icon.tsx`) **toujours présente**,
  puis le blason du club (son `logoUrl`) juste après **s'il existe**, même taille (24 px) — pas de
  repli glyphe générique (détail de la composition : `identite-visuelle-produit.md`). L'écran
  d'attente de génération (`frontend/src/features/planning/GenerationWaiting.tsx`, consommé par
  `GenerateStep.tsx` et `PlanningPage.tsx`) n'est **pas** une surface de marque au sens logo — elle
  lit seulement l'accent via `--accent`.
