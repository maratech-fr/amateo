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
  themables, pas un logo.

Consommé UNIQUEMENT par `App\Service\BrandAssets::pdfLogoDataUri()`, qui l'inline en data URI :
le worker Puppeteer ne doit dépendre d'aucun fetch réseau, le document reste auto-portant.
