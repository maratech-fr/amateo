# backend/assets/brand — le mark produit servi côté backend

Actifs de MARQUE que le backend inline dans un document qu'il génère (aujourd'hui : le
pied de page « Généré avec … » de l'export PDF, servi par `App\Service\BrandAssets`).

## `icon.svg`

L'icône produit — les trois arcs concentriques, **géométrie DÉFINITIVE du handoff
marque** (`business/7-marque/design_handoff_logo_loaders/README.md`).

- **Provenance** : recopie de la géométrie de `frontend/src/shared/components/ui/brand-icon.tsx`
  (mêmes centres, rayons, épaisseurs, `pathLength`/`stroke-dasharray`, rotations et teintes).
  Recopiée EXPRÈS : par convention, zéro import cross-zone entre `backend/` et `frontend/` — les
  deux zones sont indépendantes par construction (`CLAUDE.md` §2). Si la géométrie de marque
  bouge, elle bouge dans les deux fichiers.
- **Arcs SEULS, sans disque blanc** : le mark se pose ici sur une surface claire (le pied de page
  du PDF), ce n'est pas une vignette d'onglet — le disque blanc vit dans le favicon, pas ici.
- **Couleurs EN DUR** : c'est le mark de marque, ses teintes sont fixes par définition
  (`#B51C8A` / `#D47800` / `#46AFAC`) — la règle « jamais un `#hex` » vise les surfaces d'interface
  themables, pas un logo. Ces trois teintes sont aussi déclarées en constante
  `App\Service\BrandAssets::MARK_TINTS` (recopie assumée depuis ce fichier) et consommées par
  `App\Mail\EmailTemplateRenderer`, qui en DÉRIVE (mélange vers le blanc, jamais telles quelles) le
  fond très pâle de la carte d'e-mail. Si la géométrie/les teintes de marque bougent, elles bougent
  ici, dans `brand-icon.tsx` ET dans `MARK_TINTS`.

Consommé UNIQUEMENT par `App\Service\BrandAssets::pdfLogoDataUri()`, qui l'inline en data URI :
le worker Puppeteer ne doit dépendre d'aucun fetch réseau, le document reste auto-portant.

## `email-icon.png`

L'icône produit posée dans la **signature des e-mails** — pastille COULEUR sur **disque
BLANC**, 128×128, ~4 Ko.

- **Provenance** : dérivée de `business/7-marque/fichiers-sources_icone-couleur_fond-blanc.png`
  (1000×1000 RGBA, icône couleur sur fond blanc opaque). Régénération (GD, depuis la racine du
  dépôt) : redimensionnement à 128 px puis masque circulaire (les coins passent transparents), ce
  qui donne un **disque** blanc — et non un carré — quel que soit le fond du client de messagerie
  (mode clair comme sombre). Contrainte : **≤ 128 px et ≤ 20 Ko**.
- **PNG et non SVG** : les clients de messagerie (Outlook, Gmail) ne rendent pas fiablement un SVG.
  Le disque blanc est ici INDISPENSABLE (à l'inverse de `icon.svg`, qui se pose sur une surface
  claire connue) : la signature s'affiche sur un fond inconnu, le disque garantit le contraste.
- **Fond transparent hors du disque** : le mode sombre d'un client laisse voir sa propre couleur
  autour du cercle (cf. maquette `captures/email-signature.png`), jamais un carré blanc.

Consommé UNIQUEMENT par `App\Service\BrandAssets::emailLogoPngBytes()`, qui renvoie les octets
bruts embarqués en pièce inline (Content-ID) : l'e-mail voyage auto-portant, sans fetch réseau
côté destinataire.
