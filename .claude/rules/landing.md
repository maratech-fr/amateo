---
paths:
  - "landing/**"
---

# Landing — conventions & pièges (chargé quand landing/ est touché)

> La page de vente publique. **Elle n'a pas d'`AGENTS.md`** : tout ce qui la concerne tient ici.

- **Zéro build.** HTML/CSS statique servi tel quel — pas de npm, pas de bundler, pas de
  transpilation. On édite `index.html` et `assets/` directement. N'introduis **aucune** chaîne de
  build : c'est ce qui rend cette page increvable et déployable seule.
- **Aucun lien avec `frontend/`** — pas d'import de composant, pas de CSS partagé, pas de brique
  commune. Les deux zones se ressemblent par **convention**, jamais par dépendance. Dupliquer une
  couleur ici est le comportement VOULU.
- **Marque, liens et coordonnées vivent dans `config.js` SEUL** — jamais en dur dans `index.html`.
  Le nom commercial **est tranché depuis le 2026-08-15** (produit **Amateo**, éditeur **Maratech**)
  et `config.js` est recalé dessus ; la règle du point unique reste, elle : c'est elle qui a rendu
  le renommage gratuit, et c'est elle qui rendra gratuit un changement de domaine.
  ⚠ `appUrl` s'écrit **sans slash final** — les CTA concatènent (`appUrl + "/register"`).
- **Deux domaines, une machine** : le domaine **nu** sert `landing/`, un **sous-domaine** sert l'app.
  Un lien « Se connecter » vers l'app est donc un lien absolu inter-domaines, pas une route.
  ⚠ Ce ne sont **pas des vhosts nginx** : c'est **Caddy**, sur la VM et hors compose, qui tient la TLS
  et aiguille par domaine (`docs/ops/Caddyfile.example` — `file_server` pour la page, `reverse_proxy`
  pour l'app). Les deux nginx du compose écoutent en 80 DERRIÈRE lui.
- **Passe de design obligatoire** dès qu'on remanie l'apparence — c'est une page **publique** :
  agent `ui-ux-pro-max`, bornée à l'apparence, elle ne valide rien
  (`../../frontend/docs/frontend-strategy.md`, règle du 2026-08-11).
- ⚠ **Le contraste WCAG se vérifie dans un vrai navigateur**, jamais à l'œil ni en jsdom — les
  couleurs sont en `oklch`, la conversion vers sRGB passe par un canvas (P5-5 a corrigé des
  contrastes qui « paraissaient » bons).
- **Aucune donnée personnelle collectée sans mentions légales ni politique de confidentialité**
  (LCEN + RGPD) : un formulaire de contact sur cette page déclenche les deux obligations —
  `business/administratif-mise-en-prod.md` §9.
- **Aucun job CI ne couvre `landing/`** (`.github/workflows/ci.yml` ne la mentionne nulle part) —
  la seule preuve d'une passe (design, contraste, rendu) est un axe joué **à la main** dans un
  vrai navigateur, captures à l'appui dans `captures/` (racine du dépôt, gitignoré).
- **Une capture d'écran de l'app pour la vitrine se prend sur le club de démonstration**
  (`app:demo:seed`, « Démo Basket Club ») — jamais sur un club réel, jamais de nom de personne.
  Patron : `landing/assets/planning.png` (P5-5), `landing/assets/matchs.jpg` (P5-26) — décision
  fermée, `specs/courantes/etat-des-lieux.md` §2.
