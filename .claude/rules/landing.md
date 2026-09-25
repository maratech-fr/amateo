---
paths:
  - "landing/**"
---

# Landing — conventions & pièges (chargé quand landing/ est touché)

> La page de vente publique. **Elle n'a pas d'`AGENTS.md`** : tout ce qui la concerne tient ici.

- **La page s'adosse à [`specs/courantes/modules-produit.md`](../../specs/courantes/modules-produit.md)**
  — la carte des modules en langage club, promesse + preuve + capture ; toute nouvelle section
  vitrine vérifie ses affirmations contre ce fichier avant d'écrire un mot.
- **Zéro build.** HTML/CSS statique servi tel quel — pas de npm, pas de bundler, pas de
  transpilation. On édite `index.html` et `assets/` directement. N'introduis **aucune** chaîne de
  build : c'est ce qui rend cette page increvable et déployable seule.
- **Aucun lien avec `frontend/`** — pas d'import de composant, pas de CSS partagé, pas de brique
  commune. Les deux zones se ressemblent par **convention**, jamais par dépendance. Dupliquer une
  couleur ici est le comportement VOULU.
- **Marque, liens, coordonnées et logo vivent dans `config.js` SEUL** — jamais en dur dans
  `index.html`. Le nom commercial **est tranché depuis le 2026-08-15** (produit **Amateo**, éditeur
  **Maratech**) et `config.js` est recalé dessus ; la règle du point unique reste, elle : c'est
  elle qui a rendu le renommage gratuit, et c'est elle qui rendra gratuit un changement de domaine.
  ⚠ `appUrl` s'écrit **sans slash final** — les CTA concatènent (`appUrl + "/register"`).
  **Le logo suit le même patron (P5-25, 2026-09-25)** : `config.js` clé `logo` (source définitive
  du handoff marque, `assets/brand/`) injecté par script sur chaque `[data-brand-logo]` — jamais un
  chemin d'asset en dur dans `index.html`. `config.js` est chargé depuis `index.html` avec
  `?v=<date>`, à bumper à chaque ajout/retrait/renommage de clé — sinon un visiteur de retour garde
  l'ancien fichier en cache et toute nouvelle clé lit `undefined` (incident 2026-09-25, logo cassé ;
  l'injection est défensive depuis, mais le `?v=` est ce qui rend le vrai logo tout de suite).
- **Palette recalée sur le logo (P5-25, 2026-09-25)** : `--accent` (`#46afac`, teal signature) est
  **décoratif seul** — il ne tient que 2,50:1 sur `--paper`, sous la barre texte. Le texte, les
  liens, les boutons et **les anneaux de focus** portent `--accent-ink` (`#2e7876`, ≥ 4,5:1 partout
  où il est posé) ; `--accent-deep` (`#215857`) sert au survol ; `--accent-soft` (`#e9f5f5`) sert de
  fond. Ne jamais remonter le teal signature sur du texte ou un focus — un accent de marque n'y
  monte que s'il passe 4,5:1 mesuré (3:1 pour un indicateur de focus WCAG 2.2).
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
- **Une capture d'écran de l'app pour la vitrine s'exige sur le RÉSULTAT, pas sur une méthode
  unique** : (1) l'en-tête porte « Démo Basket Club », jamais un club réel ; (2) **aucune personne
  RÉELLE** — un nom de démo manifestement générique (ex. « Camille Durand ») est admis, remplacé
  dans le DOM au moment de la capture, jamais le nom d'une vraie personne ; (3) les données
  visibles sont publiques (codes d'équipe, clubs adverses, gymnases). Deux façons d'y arriver,
  toutes deux valides : le **seed démo** (`app:demo:seed`, patron `landing/assets/planning.png`,
  P5-5) ou une **prise de vue sur une stack réelle** avec le nom du club remplacé dans le DOM au
  moment de la capture (patron `landing/assets/matchs.jpg`, P5-26, 2026-09-25 — blason retiré du
  DOM). La vue **Conflits** (qui liste des coachs) est admise sous cette condition — un nom de
  démo fictif suffit, elle n'est plus écartée par principe (patron
  `landing/assets/matchs-conflits.jpg`, 2026-09-25) — décision fermée,
  `specs/courantes/etat-des-lieux.md` §2.
