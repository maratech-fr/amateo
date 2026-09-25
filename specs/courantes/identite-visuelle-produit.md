# Identité visuelle produit — la base est le produit, l'accent est le club

Last verified @ 2026-09-25 (`documentation-update`, PR B du chantier DA « en-tête marque produit +
favicon », complétée par le logo en remplacement du nom produit en texte). Confronté au code :
`frontend/src/index.css` (blocs `:root`/`.dark`), `frontend/src/shared/lib/product.ts`
(`PRODUCT_ACCENT`), `frontend/src/shared/hooks/useApplyClubTheme.ts`, `frontend/src/shared/lib/color.ts`
(`SURFACES`/`accentForMode`/`accentHoverForMode`), `frontend/src/test/accentTokenParity.test.ts`,
`frontend/src/features/club/ClubPage.tsx` (`DEFAULT_ACCENT`), `landing/index.html` (`--accent`) —
PR A ; `frontend/src/shared/components/ui/brand-icon.tsx`, `frontend/src/app/AppLayout.tsx:58-68`,
`frontend/public/favicon.svg`, `frontend/src/shared/components/ui/brand-mark.tsx`,
`frontend/src/features/auth/AuthLayout.tsx`, `frontend/src/shared/components/ui/system-screen.tsx`,
`frontend/src/features/admin/AdminAuthLayout.tsx` — PR B. Les ratios de contraste cités ont été
recalculés indépendamment (conversion OKLCH → sRGB linéaire, WCAG 2.1) contre les fonds décrits
ci-dessous, pas recopiés d'un commentaire.

> Ce fichier est le pendant **PRODUIT** de [`identite-visuelle-club.md`](identite-visuelle-club.md)
> (qui reste la maison du **CLUB** : logo, upload, palette extraite, écran « Gestion du club »).
> Celui-ci décrit la couche qui existe **avant** et **sous** tout habillage de club : les surfaces
> neutres (fond, carte, encre, bordure) et l'accent que voit un club qui n'a encore rien choisi.

## La règle en une phrase

**La base (surfaces + encre) est celle du produit, jamais celle d'un club — l'accent seul varie
par club, et un club sans couleur reçoit l'accent produit par défaut, dérivé exactement comme le
serait la couleur d'un club.** Un club ne peut pas teinter les surfaces neutres de l'app (décision
fermée, voir `etat-des-lieux.md` §2 — rouvrirait un club voulant aussi teinter le fond/la carte).

## Décision 1 — base CHAUDE (2026-09-25, fondateur)

L'app quittait ses neutres bleu-froid (teinte OKLCH 260, un bleu-gris neutre générique) pour
reprendre la base de la vitrine (`landing/index.html`) — partagée **par convention** (jetons
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

## Décision 2 — accent produit par défaut, dérivé comme un accent de club (2026-09-25, fondateur)

Un club **sans couleur choisie** reçoit désormais le **teal signature du logo** (`#46AFAC`,
identique au `--accent` décoratif de `landing/index.html`, même hex, convention partagée jamais
importée) au lieu de retomber sur des jetons CSS statiques indépendants.

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

## Décision 3 — en-tête : la marque PRODUIT d'abord, le club ensuite (2026-09-25, fondateur, PR B)

L'arbitrage posé en PR A (« l'en-tête est aujourd'hui pris par le logo du CLUB ») est tranché :
l'en-tête de l'app (`frontend/src/app/AppLayout.tsx:58-68`) montre désormais **les deux**, dans un
ordre fixe — l'icône produit ne s'efface jamais devant celle d'un club.

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
  favicon de la vitrine (`landing/assets/brand/icon.svg`, PR précédente) : le disque fait ressortir
  l'icône sur un onglet sombre comme clair. Même géométrie/couleurs que `BrandIcon`, recopiées dans
  le fichier SVG statique (pas de génération depuis le composant React — un favicon n'exécute pas
  de JS).
- **`BrandMark`** (`frontend/src/shared/components/ui/brand-mark.tsx`) : les surfaces où le produit
  se nomme comme MARQUE plutôt qu'en texte de phrase — login/inscription (`AuthLayout`), écrans
  système (`system-screen`), console admin (`AdminAuthLayout`) — portent désormais le logo complet
  (`BrandIcon` + le mot) au lieu du nom en texte nu ; statut **logotype**, exempté de WCAG 1.4.3 pour
  le teal sur fond clair, au même titre que `BrandIcon`.

## Ce qui reste à venir

- **Fond d'écran / motif « ça sent le basket »** (roadmap P4-18) : décision distincte de ce lot —
  la base chaude ne le solde pas, il attend le fond du designer (bandeau/illustrations).
- **PDF (y compris impression N&B), e-mails transactionnels, image OG** (roadmap P5-24) : n'ont
  reçu aucun asset logo à ce jour — les exports PDF suivent leur propre chaîne
  (`PdfGenerator`, `backend/docs/`), non touchée par ce lot.
