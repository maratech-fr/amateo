# Image de partage (Open Graph) — `og.png`

Source versionnée de l'aperçu affiché quand un lien vers le produit est collé dans une
messagerie ou un réseau social (`og:image`). L'aperçu porte le logotype (`amateo`) sur le
fond de marque, plus la promesse de la vitrine.

## Fichiers

- `og.html` — le gabarit rendu. Copie EXACTE du gabarit validé par le fondateur (2026-09-29),
  aux deux `data:` URI près : le fond et le mark sont référencés en **chemins relatifs** vers
  les assets de marque versionnés (`../../landing/assets/brand/fond.svg` et `mark.svg`), qui
  sont octet-identiques aux URI base64 d'origine. Un rendu ne dépend donc plus d'un blob inline.
- Sortie : `landing/assets/brand/og.png` **et** `frontend/public/brand/og.png` (mêmes octets,
  1200×630). La vitrine et l'app servent chacune sa copie sur son propre domaine.

## Police requise

Le gabarit charge **Bricolage Grotesque** (poids 600 et 800) depuis Google Fonts
(`<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;800">`).
La regénération a donc besoin d'un accès réseau à Google Fonts (ou d'un `@font-face` local
équivalent) — sinon le rendu retombe sur une police système et l'image dérive.

## Régénérer

1. Ouvrir `og.html` **depuis ce dossier** (`file://…/scripts/brand-og/og.html`), ou le servir
   sur un petit serveur statique à la racine du dépôt pour que les chemins relatifs résolvent.
2. Capturer la zone `1200×630` (le `<body>` fait exactement cette taille) — p. ex. avec le
   Chromium du projet (`@playwright/test`), `page.setViewportSize({ width: 1200, height: 630 })`
   puis `page.screenshot({ path, clip: { x:0, y:0, width:1200, height:630 } })`.
3. Copier l'octet obtenu vers les **deux** cibles ci-dessus (mêmes octets).

## ⚠ Cache immuable — changer le NOM à chaque retouche

L'app sert `public/brand/*` avec un cache long (`Cache-Control: max-age=1 an, immutable`).
Un fichier réécrit **sous le même nom** ne sera donc PAS re-téléchargé par un visiteur de
retour, ni re-scrappé par un cache social. À la prochaine retouche visuelle : **renommer le
fichier** (`og.png` → `og-2.png`, etc.) et mettre à jour les trois balises `og:image`
(`frontend/index.html`, `landing/index.html`, `landing/mentions-legales.html`) en même temps.
