# Identité visuelle produit — la base est le produit, l'accent est le club

Last verified @ 2026-09-26 (passe « présent » — dates/ids de PR retirés des titres de section,
`Décision 1/2/3` gardés car cités depuis `roadmap.md` P5-24 et `etat-des-lieux.md` §3). Confronté
au code cette passe : `frontend/src/shared/lib/product.ts` (`PRODUCT_ACCENT = "#46AFAC"`),
`frontend/src/index.css` (règles `body`/`.dark body` sur `fond-{light,dark}.svg`),
`frontend/src/test/brandBackground.test.ts` (purge `c2pa`/`<metadata`/rect de sol). Non re-vérifié
cette passe (reformulé au présent tel quel) : `useApplyClubTheme.ts`, `color.ts`
(`SURFACES`/`accentForMode`/`accentHoverForMode`), `accentTokenParity.test.ts`, `ClubPage.tsx`
(`DEFAULT_ACCENT`), `brand-icon.tsx`, `favicon.svg`, `brand-mark.tsx`, `system-screen.tsx`,
`AdminAuthLayout.tsx` — historique des vérifications précédentes : `git log -p --follow`. Les
ratios de contraste cités plus bas ne sont pas recalculés cette passe (le fond n'y touche pas —
c'est un décor, pas un jeton de couleur de texte).

> Ce fichier est le pendant **PRODUIT** de [`identite-visuelle-club.md`](identite-visuelle-club.md)
> (qui reste la maison du **CLUB** : logo, upload, palette extraite, écran « Gestion du club »).
> Celui-ci décrit la couche qui existe **avant** et **sous** tout habillage de club : les surfaces
> neutres (fond, carte, encre, bordure) et l'accent que voit un club qui n'a encore rien choisi.

## La règle en une phrase

**La base (surfaces + encre) est celle du produit, jamais celle d'un club — l'accent seul varie
par club, et un club sans couleur reçoit l'accent produit par défaut, dérivé exactement comme le
serait la couleur d'un club.** Un club ne peut pas teinter les surfaces neutres de l'app (décision
fermée, voir `etat-des-lieux.md` §2 — rouvrirait un club voulant aussi teinter le fond/la carte).

## Décision 1 — base CHAUDE

L'app reprend la base de la vitrine (`landing/index.html`), plutôt que des neutres bleu-froid
génériques (teinte OKLCH 260) — partagée **par convention** (jetons
dupliqués dans `frontend/src/index.css`, jamais un import de `landing/`, les deux zones restant
indépendantes par construction, `CLAUDE.md` §2).

- **Clair** : fond papier `#faf9f7`, carte blanche `#ffffff`, encre `#464646` — **le gris exact du
  mot « amat » du logo**, pas le `#191714` (quasi-noir) de la vitrine. `--muted` ≈ `#f5f3f0`
  (`oklch(0.965 0.004 80)`), bordures `#e7e3dc`.
- **Sombre** : **translation de teinte** (260 → 75) à L (luminosité) et chroma inchangés, pas une
  nouvelle échelle — `--background oklch(0.19 0.006 75)` (≈ `#151311`), `--card oklch(0.23 0.006 75)`
  (≈ `#1f1d1a`), `--foreground oklch(0.96 0.004 75)`, `--muted-foreground oklch(0.68 0.015 75)`.
  `frontend/src/shared/lib/color.ts` (`SURFACES`) porte la conversion sRGB de ces deux fonds, à
  garder synchronisée avec `index.css` (commentaire en tête du fichier).

## Décision 2 — accent produit par défaut, dérivé comme un accent de club

Un club **sans couleur choisie** reçoit le **teal signature du logo** (`#46AFAC`, identique au
`--accent` décoratif de `landing/index.html`, même hex, convention partagée jamais importée) —
jamais un jeton CSS statique indépendant.

- **Maison unique de l'hex** : `PRODUCT_ACCENT` dans `frontend/src/shared/lib/product.ts` — aux
  côtés de `PRODUCT_NAME`/`PUBLISHER_NAME`, seule maison des littéraux d'identité produit
  (`CLAUDE.md` §1). **Aucun autre `#hex` d'accent produit n'a le droit d'exister ailleurs.**
- **Une seule voie de dérivation** : `useApplyClubTheme` calcule sa base par mode —
  `accentDark ?? accentLight ?? PRODUCT_ACCENT` en sombre, `accentLight ?? accentDark ??
  PRODUCT_ACCENT` en clair — puis fait passer CETTE base (couleur de club ou `PRODUCT_ACCENT`) par
  la même dérivation par contraste que n'importe quel accent : `accentForMode` (assombrit/éclaircit
  jusqu'à ≥ 4,5:1 sur `--background` ET `--card` du mode), `readableForeground` (texte noir/blanc),
  `accentHoverForMode` (teinte de survol, contraste préservé). **Il n'y a plus de
  `root.style.removeProperty` sur `--accent`/`--accent-foreground`/`--accent-hover`** pour un club
  sans couleur — seul `--accent-2` (teinte secondaire optionnelle de la palette extraite) garde ce
  repli, faute de valeur produit équivalente. Un club qui a défini sa propre couleur continue de la
  voir appliquée telle quelle — seule la valeur D'ENTRÉE change, la dérivation est identique dans
  les deux cas (donc un club existant, par exemple avec un accent rouge, n'est pas affecté).
- **Les valeurs statiques d'`index.css` sont la sortie EXACTE de cette dérivation**, pas une
  coïncidence à maintenir à la main : `--accent`/`--accent-hover` des blocs `:root` (clair) et
  `.dark` sont respectivement `accentForMode(PRODUCT_ACCENT, mode)` et
  `accentHoverForMode(accentForMode(PRODUCT_ACCENT, mode), mode)`. Elles existent en dur pour être
  visibles **avant** que le hook ne tourne (premier rendu) et sur les surfaces qu'il ne touche pas.
  Deux valeurs pour une seule couleur = dérive possible si l'une bouge sans l'autre — c'est
  exactement ce que garde `frontend/src/test/accentTokenParity.test.ts` : il **lit** `index.css`,
  extrait `--accent`/`--accent-hover` des deux blocs et exige l'égalité stricte avec la sortie de
  `accentForMode`/`accentHoverForMode` sur `PRODUCT_ACCENT`. Une valeur statique éditée à la main
  sans repasser par la dérivation rougit ce test — **on ne les édite jamais directement, on les
  recalcule**.
- `ClubPage.tsx` : `DEFAULT_ACCENT = PRODUCT_ACCENT` (repli du sélecteur de couleur sur l'écran
  « Gestion du club », et valeur reposée par le bouton « Réinitialiser ») — plus aucun hex d'accent
  hors `product.ts` dans le code frontend.

## Table des jetons (valeurs à date, contrastes recalculés contre les fonds ci-dessus)

| Jeton | Clair | Sombre | Rôle |
|---|---|---|---|
| `--background` | `#faf9f7` | `oklch(0.19 0.006 75)` (≈ `#151311`) | fond de page |
| `--card` | `#ffffff` | `oklch(0.23 0.006 75)` (≈ `#1f1d1a`) | surface carte |
| `--foreground` | `#464646` | `oklch(0.96 0.004 75)` | encre — 8,97:1 sur bg / 9,44:1 sur card (clair) |
| `--muted` | `oklch(0.965 0.004 80)` (≈ `#f5f3f0`) | `oklch(0.27 0.006 75)` | surface atténuée |
| `--muted-foreground` | `#6d675d` | `oklch(0.68 0.015 75)` | texte secondaire — 5,32:1 sur bg / 5,60:1 sur card (clair) |
| `--border` / `--input` | `#e7e3dc` | `oklch(0.31 0.006 75)` | ligne |
| `--accent` (défaut produit) | `#317a77` | `#46afac` | = `accentForMode(PRODUCT_ACCENT, mode)` — 4,77:1/5,02:1 sur bg/card (clair), 7,05:1/6,40:1 (sombre) |
| `--accent-foreground` | `#ffffff` | `#000000` | = `readableForeground` de l'accent ci-dessus |
| `--accent-hover` | `#2f7572` | `#4db2af` | = `accentHoverForMode` de l'accent ci-dessus |

Sémantiques (`--destructive`/`--warning`/`--success`/`--diff`) : **valeurs OKLCH inchangées** par ce
lot (seuls leurs `-foreground` en sombre, pour `destructive` et `diff`, suivent la translation
75 pour rester de la même famille chaude que la base sombre). Revalidées contre les nouveaux fonds :
tous les rôles TEXTE (destructive/warning/success, y compris en teinte `bg-*/10|15`) restent
≥ 4,5:1 et tous les rôles GRAPHIQUES (`--diff`, non-texte, WCAG 1.4.11) restent ≥ 3:1, sur les deux
surfaces (`--background`/`--card`) et les deux thèmes ; la paire la plus tendue mesurée est
`text-destructive` sur `bg-destructive/15` en fond clair, à 4,59:1. Détail des paires gardées en
dur : `tests/e2e/a11y-contrast.spec.ts` (inchangé par ce lot).

## Ce qui ne bouge PAS

- **Console superadmin** (`frontend/src/index.css`, bloc `:root` dédié `--console-*`) : surface
  délibérément hors du système clair/sombre (décision UXC-12, `etat-des-lieux.md` §2) — alias des
  nuances Tailwind, aucun rapport avec la base produit.
- **`VENUE_PALETTE`** (`frontend/src/shared/lib/color.ts`) : palette arc-en-ciel des gymnases,
  indépendante du thème par construction (distinguer visuellement des salles, pas une identité
  de marque).
- **Scènes** (`GenerationWaiting`, écrans système `SystemScreen`) : elles ne lisent que des
  jetons de thème (`var(--card|muted|border|muted-foreground|accent)`), jamais un littéral — elles
  héritent donc la base chaude sans aucune modification de leur code, cf.
  [`identite-visuelle-club.md`](identite-visuelle-club.md) et `frontend/docs/frontend-spec.md` §6.8.

## Décision 3 — en-tête : la marque PRODUIT d'abord, le club ensuite

L'en-tête de l'app (`frontend/src/app/AppLayout.tsx:58-68`) montre **les deux**, dans un ordre
fixe — l'icône produit ne s'efface jamais devant celle d'un club.

- **`BrandIcon`** (`frontend/src/shared/components/ui/brand-icon.tsx`) est la maison unique de
  l'icône produit : SVG inline, trois arcs (`<circle>` avec `stroke-dasharray`/`pathLength`), mêmes
  nombres que le handoff marque (`business/7-marque/design_handoff_logo_loaders/`) et que
  `landing/assets/brand/icon.svg` — recopiée par convention, jamais importée (les deux zones
  restent indépendantes, `CLAUDE.md` §2). **Les couleurs des arcs sont en dur** (`#B51C8A`,
  `#D47800`, `#46AFAC`) — seule exception documentée à « jamais un `#hex` »
  (`.claude/rules/frontend.md`) : ce sont les teintes du mark lui-même, pas des jetons de thème
  themables. `aria-hidden` par défaut (décoratif, le sens est porté par le nom écrit à côté) ;
  une prop `title` optionnelle bascule sur `role="img"` pour un usage isolé.
- **Composition de l'en-tête** : `[BrandIcon 24px] · [blason du club 24px si `logoUrl`] NOM` —
  l'icône produit est TOUJOURS rendue, le blason du club (image ronde `object-cover`) suit quand il
  existe, à la même taille (la position porte la hiérarchie, pas la taille). `aria-label` du lien
  d'accueil = nom du club ou `PRODUCT_NAME` (icône et blason restent décoratifs). **Le repli glyphe
  `CalendarCheck2` a disparu** — un club sans logo n'affiche plus qu'un glyphe interchangeable,
  toujours au moins l'icône Amateo.
- **Favicon** (`frontend/public/favicon.svg`) : la marque violette générique (`#863bff`) est
  remplacée par l'icône Amateo posée sur un **disque plein blanc** (`r=490`) — même règle que le
  favicon de la vitrine (`landing/assets/brand/icon.svg`) : le disque fait ressortir
  l'icône sur un onglet sombre comme clair. Même géométrie/couleurs que `BrandIcon`, recopiées dans
  le fichier SVG statique (pas de génération depuis le composant React — un favicon n'exécute pas
  de JS).
- **`BrandMark`** (`frontend/src/shared/components/ui/brand-mark.tsx`) : les surfaces où le produit
  se nomme comme MARQUE plutôt qu'en texte de phrase — login/inscription (`AuthLayout`), écrans
  système (`system-screen`), console admin (`AdminAuthLayout`) — portent le logo complet
  (`BrandIcon` + le mot), jamais le nom en texte nu. Le mot hérite `currentColor` (UN seul ton, dans
  tous les thèmes) — **seul `BrandIcon` porte des couleurs en dur**, `BrandMark` n'en porte aucune :
  un second ton teal sur les deux derniers caractères ne tiendrait que ~2,5:1 sur le fond papier
  clair (sous la barre), et `aria-hidden` n'exempte pas le texte rendu de la règle color-contrast
  d'axe — la piste « logotype exempté de WCAG 1.4.3 » ne tient pas ; décision fondateur : une
  marque n'a pas deux visages.

## Le fond d'écran

Un seul fond, **identique app et vitrine** : les motifs multi-sport aux 3 couleurs du logo, posés
en `background-image` CSS sur `body` — pas de composant React, pas d'animation, **le fond est
FIGÉ**.

- **Assets** : `frontend/public/brand/fond-light.svg` / `fond-dark.svg` (thème clair/sombre de
  l'app) et `landing/assets/brand/fond.svg` (vitrine, un seul thème — la landing n'a pas de mode
  sombre). Les trois sont des copies **purgées** des SVG livrés par le fondateur (dossier
  `business/`, hors dépôt) : manifeste C2PA retiré (l'original portait la signature « Anthropic
  Claude Content Signing » — ces SVG sortent d'une session Claude, pas d'un outil de design),
  `<metadata>` retirée, `<rect>` de sol retiré (le sol est notre papier, pas le gris de la
  livraison) — chaque copie tombe à quelques Ko. Recopiées par convention (comme `BrandIcon`
  ⇄ `icon.svg`, `CLAUDE.md` §2), jamais partagées par import entre `frontend/` et `landing/`.
- **Pose** : `body { background-image: url("/brand/fond-light.svg"); background-size: cover;
  background-position: center; background-repeat: no-repeat; background-attachment: fixed; }`
  (`frontend/src/index.css`), `.dark body` bascule sur `fond-dark.svg` ; la vitrine pose
  l'équivalent en un seul déclaratif (`background: var(--paper) url("assets/brand/fond.svg")
  center / cover no-repeat fixed;`, `landing/index.html`). `fixed` : parallaxe de fond assumée
  desktop-first — iOS Safari retombe en `scroll` (comportement natif, pas un bug).
- **Sol et opacité** : le sol est notre papier — `--background` côté app, `--paper` côté vitrine
  (`background-color` posé avant l'image). Opacité des motifs : **0,15 clair / 0,17 sombre** dans
  l'app, **0,26** sur la vitrine (contraste éditorial différent, vitrine = une seule page longue).
- **Zones opaques** : l'en-tête d'`AppLayout` (`bg-background` sur le `<header>`, plus sur la
  racine) et les cartes restent opaques — le fond ne vit **que** dans les zones vides. Les racines
  de shell (`AppLayout`, `AuthLayout`) ne portent donc plus `bg-background` : elles laissent
  passer le fond du `body`. `GenerationScene` (attente de génération + moteur indisponible) garde
  `bg-background` sur sa racine — décor déjà chargé (mini-grille, ballon, terrain filigrané), un
  second fond dessous l'aurait surchargé. `system-screen` (écrans système) reste nu, inchangé —
  hors lot. La console superadmin n'a pas de fond (UXC-12, § « Ce qui ne bouge pas »).
- **Exception `#hex` étendue** : la règle « jamais un `#hex` » (`.claude/rules/frontend.md`)
  admettait déjà `BrandIcon` ; les couleurs en dur **à l'intérieur** de ces SVG d'asset statiques
  (`public/brand/fond-*.svg`, `landing/assets/brand/fond.svg` — comme `favicon.svg` avant eux)
  sont la même exception : un fichier SVG servi tel quel n'a pas de jeton de thème à consommer.
- **Garde** : `frontend/src/test/brandBackground.test.ts` tient deux faits qu'aucun autre test ne
  voit — la parité clair/sombre dans `index.css` (`body` référence `fond-light.svg`, `.dark body`
  référence `fond-dark.svg`) et la purge des deux copies servies (ni `c2pa`, ni `<metadata`, ni le
  rect de sol). **Aucune assertion sur la valeur d'opacité** (plage fondateur, ajustable).
- ⚠ **Aucun gate automatisé ne voit les motifs** : ce sont des SVG statiques dans `public/`, hors
  du graphe TypeScript (aucun lint ne les lit) ; le scan de contraste axe (`a11y-contrast.spec.ts`,
  `expectNoContrastViolations`) n'assert que `results.violations` sur les éléments texte — un
  `background-image` décoratif posé sur `body` n'est jamais l'élément évalué. La seule preuve est
  une **passe visuelle manuelle** (login clair/sombre, `/planning` clair/sombre, état vide, 404,
  vitrine — texte lisible partout).
- **Ce qui vit encore de l'ancien cadrage design par sport** (fichier `specs/evolution/`
  supprimé — base neutre, accent bleu générique, fonds froids, familles A/B/C par sport, tout
  supplanté par ce fond commun) — règles encore VIVANTES, reportées ici :
  une **zone protégée** sous le texte reste à ≤ ~15 % d'opacité de motif (le fond commun applique
  0,15-0,26, dans cette fourchette) ; `prefers-reduced-motion` **coupe** toute animation de décor
  (sans effet ici, le fond est figé, mais reste la règle pour tout futur décor animé) ; pas de
  Lottie, pas de police externe (autohébergée seulement) sur un asset visuel.

## Ce qui reste à venir

- **PDF (y compris impression N&B), e-mails transactionnels, image OG** (roadmap P5-24) : n'ont
  reçu aucun asset logo à ce jour — les exports PDF suivent leur propre chaîne
  (`PdfGenerator`, `backend/docs/`), non touchée par ce lot. La cession de droits du logo est
  **signée** (fondateur) — ce n'est plus le préalable qui bloquait ces trois usages.
