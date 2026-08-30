# Identité visuelle par club (logo + couleur d'accent)

Last verified @ 2026-08-30 (**rotation de fraîcheur** `documentation-update`, zone non touchée par
cette PR — re-vérifié contre le code, tout juste : `Club.logoUrl`/`accentColorDark`/`accentPalette`
dans l'entité (`backend/src/Entity/Club.php:138-154`) ✓, `PATCH /api/club/appearance`
(`ClubAppearanceController.php:34`) ✓, `useApplyClubTheme.ts` lit `accentColor`/`accentColorDark`/
`accentPalette` sur `me.club` ✓, et `GenerationWaiting.tsx` n'affiche toujours aucun logo/initiale
de club — sa seule occurrence de « logo » est le commentaire qui acte la décision fondateur du
2026-08-17 ✓. L'historique vit dans git : `git log -p --follow specs/courantes/identite-visuelle-club.md`)

> **LIVRÉ (2026-07-02)** — accent par club + logo + extraction 3 couleurs + écran « Gestion du club ». Détail livré ci-dessous ; ce qui reste ⬜ est du confort (voir « Questions ouvertes »).
>
> **Livré** : `Club.accentColor` (**accent clair**) + `Club.accentColorDark` (**accent sombre distinct**, nullable → dérivé de l'accent clair si absent — ancien comportement) + `accentPalette` + `logoUrl` (backend) · endpoint `PATCH /api/club/appearance` (accentColor · accentColorDark · palette) · upload/serve logo via abstraction `LogoStorage` (locale en dev, swappable prod) `POST/DELETE /api/club/logo` + `GET /api/clubs/{id}/logo` (public) · application front `--accent`/`--accent-foreground` (AA auto) via `useApplyClubTheme` — **une couleur par mode** : l'accent sombre explicite est appliqué tel quel en dark, sinon dérivation legible de l'accent clair · écran `/club` (hub) section Identité (**deux sélecteurs accent : thème clair + thème sombre** ; upload logo + **cropper cercle : zoom + cadrage, export PNG cadré**, y compris recadrage du logo existant + extraction 3 couleurs → suggestion accent + palette) · affichage logo **header seul** · **onglet de nav actif = pill accent** (AA-safe) · **barre d'accent (`border-l`) sur les titres de page** (Planning · Profil · Gestion du club · Matchs) pour une présence accent générale.
>
> **Décision — l'écran d'attente de génération n'affiche PLUS le logo/l'initiale du club (2026-08-17, fondateur)** : jusque-là le centre de l'écran portait un cercle pulsé avec le logo (ou, à défaut, l'initiale du nom du club). Il est remplacé par une **scène animée** (mini-grille de créneaux qui se remplissent, ballon qui rebondit, terrain en filigrane) — **AUCUN logo ni initiale par-dessus**, la scène EST le contenu. Elle reste sur MARQUE sans logo : ses couleurs lisent uniquement les tokens de thème (`--card`/`--muted`/`--border`/`--muted-foreground`) et l'accent du club via `--accent` — jamais de littéral de couleur, jamais de logo re-fetché. Comportement détaillé (composition de la scène, `prefers-reduced-motion`) : [`frontend-spec.md`](../../frontend/docs/frontend-spec.md) §6.2. `GenerationWaiting` ne prend plus de props (`initial`/`logoUrl` supprimées) — `GenerateStep` n'a donc plus besoin de `useMe()` du tout (l'appel a été retiré) ; `PlanningPage` le garde (autres usages : renommage, logo affiché ailleurs sur la page).
>
> **Reste ⬜** : stockage prod réel (impl S3/objet à écrire quand la cible est connue) · usage plus poussé des 3 couleurs (teintes signature au-delà de `--accent-2`) · login marque produit (inchangé) · assets par sport pour cette scène (P5-16, `evolution/ecrans-ui-design.md` — la scène livrée est générique basketball codée en dur, pas encore un asset désigné par sport).
>
> **Décision — quand uploader le logo** : **après** la création du club, dans l'écran « Gestion du club » (pas à l'inscription). L'inscription reste minimale (nom/email/ffbb, friction basse — l'utilisateur n'a souvent pas son logo sous la main) ; l'identité est un réglage fait ensuite, potentiellement par un autre gestionnaire. C'est l'implémentation actuelle.

## Réfs (à jour)

- Application de l'accent : `frontend/src/shared/hooks/useApplyClubTheme.ts` (lit `accentColor` / `accentColorDark` / `accentPalette` depuis `/api/me`, dérive `--accent-foreground` en AA).
- Design tokens : `frontend/src/index.css` (`@theme`, slots `--accent`).
- Mode clair/sombre : `frontend/src/shared/stores/themeStore.ts` (+ slot `accent`).
- **Pré-paint du thème** : `frontend/src/main.tsx` (`readPersistedThemeMode`) pose la classe `.dark` **avant** le premier rendu React. Sans lui, l'arbre se rend en clair puis un effet bascule : flash du mauvais thème **et** animation `transition-colors` qui laisse les surfaces à des couleurs intermédiaires **sub-AA** (A11Y-06).
- Écran de réglage : `frontend/src/features/club/ClubPage.tsx` + `LogoCropper.tsx`.
- Surfaces de marque : `frontend/src/app/AppLayout.tsx` (logo au header, fallback icône `CalendarCheck2`). L'écran d'attente de génération (`frontend/src/features/planning/GenerationWaiting.tsx`, consommé par `GenerateStep.tsx` et `PlanningPage.tsx`) n'est **plus** une surface de marque au sens logo — elle lit seulement l'accent via `--accent`.

---

<details><summary><b>Historique — base de réflexion initiale (superseded par le LIVRÉ ci-dessus), conservée pour trace</b></summary>

> ⚠️ Les statuts ⬜ / 🟡 de ce bloc décrivent l'état **de départ** (avant livraison), pas un
> reste-à-faire. Ce qui reste réellement ouvert est listé dans l'en-tête « Reste ⬜ ».
> Statut : ✅ livré · 🟡 partiel/scaffoldé · ⬜ à faire.

## Pourquoi

Le design system est construit autour d'**une seule couleur d'accent** (`--accent`) qui pilote primary / actif / highlight ; les surfaces et le texte restent neutres et AA-contrastés. L'intention explicite (commentaire `frontend/src/index.css`) : *« `--accent` is overridden per club later (logo…) »*. Aujourd'hui l'accent est un **bleu neutre par défaut**, identique pour tous les clubs — pas d'identité.

## État du scaffolding (ce qui existe déjà)

- 🟡 **`themeStore` a un slot `accent`** (`frontend/src/shared/stores/themeStore.ts`) — actuellement `null`, **jamais lu ni écrit**. C'est le point d'injection prévu.
- 🟡 **`--accent` en variable CSS** avec variantes **dark/light** (`--accent` + `--accent-foreground`), déjà consommée partout via les tokens Tailwind.
- ⬜ **Aucun champ `logo` / `color` / `accent` sur l'entité `Club`** (backend) — rien à persister.
- 🟡 **Surfaces où la marque apparaîtrait** : header (`AppLayout` → icône générique `CalendarCheck2`), écran d'attente de génération (`GenerateStep` → **initiale** du club dans un cercle qui pulse), page login, export PDF.
- ✅ Thème dark/light complet (`themeStore`, toggle) — l'accent devra fonctionner dans les deux.

## Périmètre de la feature

### 1. Couleur d'accent par club (le cœur)
- ⬜ **Backend** : champ `Club.accentColor` (hex/oklch) exposé dans `/api/me`.
- ⬜ **Frontend** : au login / `me`, écrire l'accent dans `themeStore.accent` et l'appliquer en surchargeant la variable CSS `--accent` (au niveau `:root` ou du layout).
- ⬜ **Contraste** : dériver `--accent-foreground` (et les variantes dark/light) pour rester **AA** quelle que soit la couleur choisie (garde-fou de lisibilité).
- ⬜ **Fallback** : couleur choisie absente → bleu neutre actuel (comportement d'aujourd'hui).

### 2. Logo par club
- ⬜ **Backend** : champ `Club.logoUrl` (ou upload + stockage) exposé dans `/api/me`.
- ⬜ **Upload** : écran de réglages club (image → validation format/taille → stockage).
- ⬜ **Affichage** : remplacer l'icône générique du header + l'**initiale** de l'écran d'attente par le logo ; option login + PDF.

### 3. Dérivation couleur ← logo (optionnel / plus tard)
- ⬜ Extraire la couleur dominante du logo uploadé pour **pré-remplir** `accentColor` (l'utilisateur peut ensuite ajuster). Alternative simple : choix manuel d'une couleur, sans extraction.

## Découpage possible (du plus petit au plus gros)

1. **MVP accent** : `Club.accentColor` + application front (slot `accent` déjà prêt) + garde-fou contraste. Petit, gros impact visuel.
2. **Logo** : champ + upload + affichage (header, écran d'attente).
3. **Extraction couleur depuis le logo** : confort, non bloquant.

## Questions ouvertes (toutes TRANCHÉES — voir l'en-tête « Livré »)

1. **Accent = choix manuel ou dérivé du logo ?** → **les deux** : extraction de 3 couleurs du logo qui *pré-remplit* une suggestion, ensuite éditable (deux sélecteurs : thème clair + thème sombre).
2. **Stockage du logo** → upload via l'abstraction `LogoStorage` (locale en dev, swappable). *Reste ouvert* : l'implémentation objet/S3 pour la prod.
3. **Garde-fou contraste** → **AA automatique** : `--accent-foreground` est dérivé pour rester lisible quelle que soit la couleur choisie.
4. **Le logo remplace-t-il l'initiale ?** → le logo s'affiche quand il existe, **fallback** sur l'icône générique sinon (header) / l'initiale du club (écran d'attente).

</details>
