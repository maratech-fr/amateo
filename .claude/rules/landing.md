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
  **Clé `siteUrl` (P4-302, 2026-10-05)** : l'URL de la vitrine elle-même (domaine nu, même règle
  sans slash final) — consommée par `system-pages/503.html`/`maintenance.html` pour pointer leur
  logotype cliquable vers la page de vente plutôt que vers l'app morte ; point unique, un
  changement de domaine ne touche que cette ligne.
  **Le logo suit le même patron (P5-25, 2026-09-25)** : `config.js` clé `logo` (source définitive
  du handoff marque, `assets/brand/`) injecté par script sur chaque `[data-brand-logo]` — jamais un
  chemin d'asset en dur dans `index.html`. `config.js` est chargé depuis `index.html` avec
  `?v=<date>`, à bumper à chaque ajout/retrait/renommage de clé — sinon un visiteur de retour garde
  l'ancien fichier en cache et toute nouvelle clé lit `undefined` (incident 2026-09-25, logo cassé ;
  l'injection est défensive depuis, mais le `?v=` est ce qui rend le vrai logo tout de suite).
  **Le logotype recopie désormais le patron `BrandMark` de l'app (P4-274, 2026-09-29)** : `logo`
  pointe `assets/brand/mark.svg` (les trois arcs SEULS, sans le disque blanc du favicon
  `icon.svg`) — un mark thème-neutre — et le MOT (`brand`, en minuscules) est rendu en TEXTE à la
  couleur du thème courant (`.logo-word`, `color: var(--ink)`), un seul rendu clair/sombre ; repli
  défensif inchangé, si l'icône manque/échoue seul le mot reste (marque toujours lisible).
- **Le fond d'écran est un décor CSS, PAS une clé `config.js`** (P5-16, 2026-09-25) :
  `background: var(--paper) url("assets/brand/fond.svg") center / cover no-repeat fixed;` est un
  chemin en dur dans `index.html` — contrairement à la marque/aux liens/au logo (règle
  précédente), un décor visuel n'a pas besoin d'indirection : il ne change jamais par club ni par
  domaine. Détail (opacité, purge C2PA, parité avec l'app) :
  `specs/courantes/identite-visuelle-produit.md` § « Le fond d'écran ».
- **`og:url`/`og:image` (carte de partage) sont des URL ABSOLUES en dur dans `index.html` et
  `mentions-legales.html` (P5-24, 2026-09-29)** — même exception que le fond d'écran ci-dessus,
  pour une raison différente : un crawler de messagerie/réseau social qui construit l'aperçu
  Open Graph ne charge pas `config.js`, il **n'exécute pas de JS** — une valeur injectée par script
  n'existerait donc jamais pour lui. `og:image` pointe `assets/brand/og.png` (source versionnée
  `scripts/brand-og/`, README de régénération à côté — piège du cache immuable : renommer le
  fichier à toute retouche visuelle). Détail : `specs/courantes/identite-visuelle-produit.md`
  § « Ce qui reste à venir ».
- **Balisage JSON-LD `schema.org` (`<script type="application/ld+json" data-brand-jsonld>` dans
  `index.html`, 2026-10-06)** : INJECTÉ par script depuis `config.js` (`brand`/`editor`/
  `siteUrl`/`logo`) — un graphe `SoftwareApplication` (le produit) + `Organization` (l'éditeur),
  **jamais la marque en littéral dans le balisage**, même convention que le titre/`og:title`
  ci-dessus. La description reprend la `<meta name="description">` de la page (point unique, pas
  de recopie). `offers` est **omis volontairement** : seul le palier Découverte est public
  (gratuit), les paliers payants restent « sur demande » — inexprimable proprement en une
  offre/fourchette tant que leur prix n'est pas public. `mentions-legales.html` n'a pas ce bloc
  (page sans offre produit) ; elle porte en revanche son propre `og:title` (« Mentions légales »,
  cohérent avec son `<title>`) au lieu de recopier celui de l'accueil.
- **Thème AU CHOIX, clair par défaut (P4-274, 2026-09-29)** : un mini-script en tête de `<head>`
  (avant le `<style>`, anti-flash) pose `data-theme="dark"` sur `<html>` depuis `localStorage.theme`
  (choix explicite du visiteur) sinon `prefers-color-scheme`, enveloppé (navigation privée,
  cookies refusés → repli clair silencieux). Un bouton `.theme-toggle` (lune/soleil, dans
  `nav.top` — un `<button>`, pas un `<a>`, donc épargné par la règle mobile `nav.top > a { display:none }`
  qui masque les liens inline ≤880px, cf. burger ci-dessous)
  bascule l'attribut et persiste le choix. Jetons sombres **dérivés PAR CONVENTION** des jetons
  `.dark` de l'app (`frontend/src/index.css`, même hue 75, mêmes L/chroma) — jamais importés, les
  deux zones restent indépendantes (règle ci-dessus). Détail des jetons et du fond sombre :
  `specs/courantes/identite-visuelle-produit.md`.
- **Mise en page mobile (lot 4, 2026-10-09)** : ≤880px la barre passe au **burger** — un
  `<details class="nav-menu">`/`<summary>` NATIF (ouvre/ferme **sans JS** ; le JS n'ajoute que le
  confort : miroir `aria-expanded`, fermeture au clic sur une ancre, Échap → focus). Le panneau
  (`position:absolute` sous l'en-tête sticky, recouvre, **pas de scroll-lock**) **reprend** les
  liens de la barre — ancres + « Se connecter » + CTA : **deux jeux distincts** (inline masqué en
  mobile, panneau masqué en desktop), jamais partagés, chacun câblé par `config.js`
  (`querySelectorAll` sur `[data-cta]`). Le **desktop reste intact** (le burger est `display:none`
  ≥881px, les liens inline inchangés). Logo et bascule de thème : `flex-shrink:0` (le carré 40px ne
  s'écrase plus). Paragraphes de **contenu** à 16px ≤720px (`.pain p`/`.step p`/`.faq .a`, règle
  placée APRÈS leurs règles de base — à spécificité égale c'est l'ordre source qui tranche, pas la
  media query). Étapes en **1 colonne + séparateur horizontal** <600px. Cibles tactiles ≥44px en
  mobile par padding gonflé + marge négative de compensation (bornées ≤880px, le rendu desktop ne
  bouge pas). Preuve = banc Playwright 6 largeurs × clair/sombre × burger ouvert/fermé × JS coupé,
  captures `captures/lot4-*` (aucun job CI ne couvre `landing/`, cf. ci-dessous).
- **Convention `-dark` pour toute capture/asset qui varie par thème (P4-274, 2026-09-29)** :
  `nom.ext` → `nom-dark.ext`, même dossier. Le script de bascule de thème essaie systématiquement
  la variante `-dark` en sombre et retombe sur la version claire via `onerror` si une `-dark`
  manque — poser un fichier `*-dark.*` suffit à l'activer, **aucun code à toucher**. Les 4 captures
  actuelles ont chacune leur sœur `-dark` (P5-27, 2026-10-07) ; le repli `onerror` reste un filet
  de sécurité pour un futur asset sans variante sombre.
- **`mentions-legales.html` est une page statique SŒUR d'`index.html`, pas un fragment** (P4-275,
  2026-09-29) : même scaffolding dupliqué VOLONTAIREMENT (thème anti-flash, palette, glissement
  d'ancre — zéro brique partagée même entre pages `landing/`) ; ses valeurs (éditeur, statut,
  hébergeur) viennent du bloc `legal`/`editor` de `config.js`, injectées par son propre script —
  chargé avec le MÊME `?v=`, à bumper ensemble avec `index.html`.
- **Glissement d'ancre animé en JS, pas `scroll-behavior: smooth` (P4-258, 2026-09-29)** :
  `html { scroll-padding-top: 76px }` (en-tête sticky 64px + marge) reste pour le saut natif
  (arrivée avec `#hash` dans l'URL, JS absent) ; le clic sur un lien `href="#…"` interne est
  intercepté et animé en `requestAnimationFrame` (easeOutCubic, décélération maîtrisée, coupé net
  si l'utilisateur reprend la main) — `scroll-behavior: smooth` a été retiré, il aurait doublé le
  lissage. Saut direct (pas d'animation) sous `prefers-reduced-motion: reduce`.
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
- **Mentions légales livrées (P4-275, 2026-09-29)** : `landing/mentions-legales.html` (LCEN
  art. 6-III — éditeur, responsable de publication, hébergeur Scaleway ; renvoie vers la politique
  de confidentialité de l'app pour le volet RGPD). **Aucun FORMULAIRE n'est posté sur cette page** —
  la démo passe par un `mailto:` (client de messagerie du visiteur, rien n'est posté côté serveur) :
  un futur formulaire de contact POSTÉ déclencherait, lui, l'obligation RGPD complète —
  `business/administratif-mise-en-prod.md` §9. ⚠ **Nuance depuis P4-276 (mesure d'audience)** : la
  page n'est plus « zéro donnée » au sens strict — Umami mesure l'audience **sans cookie** (IP
  hachée côté serveur, agrégats anonymes), donc **pas de bandeau de consentement** mais une
  **transparence écrite obligatoire** : paragraphe « Mesure d'audience » de `mentions-legales.html`,
  section de `PrivacyPage.tsx` (app), registre `docs/security/rgpd.md` §2.
- **Mesure d'audience — clé `analytics` de `config.js` (P4-276, 2026-10-06)** : `analytics:
  { scriptUrl, websiteId }`, point unique comme le reste de `config.js`. Le script Umami n'est
  injecté (dans `index.html` ET `mentions-legales.html`, scripts dupliqués VOLONTAIREMENT) que sous
  **double garde** : (a) les deux clés non vides ; (b) `location.hostname` égal à l'hôte dérivé de
  `siteUrl` (`new URL(siteUrl).hostname`, aucun nouveau littéral de domaine) — en local, sur :5173
  ou sur un autre domaine, **rien n'est injecté, aucune requête ne part**. Tout est défensif
  (try/catch, ancien cache sans la clé → page intacte). Le `websiteId` n'est **pas un secret** (il
  est visible dans toute page trackée) : sa place en git est correcte. Renseigner/retirer ces clés
  = **bumper le `?v=` de `config.js` dans les DEUX pages** (règle du point unique ci-dessus).
  L'instance Umami vit dans la stack prod (`docker-compose.prod.yml`, `docs/ops/prod-stack.md` §
  Mesure d'audience, runbook `deploy.md` §1.11). **Vitrine seulement** — jamais l'app (volet « app »
  de P4-276 encore ouvert en roadmap).
- **Aucun job CI ne couvre `landing/`** (`.github/workflows/ci.yml` ne la mentionne nulle part) —
  la seule preuve d'une passe (design, contraste, rendu) est un axe joué **à la main** dans un
  vrai navigateur, captures à l'appui dans `captures/` (racine du dépôt, gitignoré).
- **Une capture d'écran de l'app pour la vitrine s'exige sur le RÉSULTAT, pas sur une méthode
  unique** : (1) l'en-tête porte « Démo Basket Club », jamais un club réel ; (2) **aucune personne
  RÉELLE** — un nom de démo manifestement générique (ex. « Camille Durand ») est admis, remplacé
  dans le DOM au moment de la capture, jamais le nom d'une vraie personne ; (3) les données
  visibles sont publiques (codes d'équipe, clubs adverses, gymnases). Deux façons d'y arriver,
  toutes deux valides : le **seed démo** (`app:demo:seed`) ou une **prise de vue sur une stack
  réelle** avec le nom du club remplacé dans le DOM au moment de la capture. **Les 4 captures
  actuelles** (`planning.png`, `matchs.jpg`, `matchs-importer.jpg`, `matchs-conflits.jpg`, et leurs
  sœurs `-dark`) suivent la seconde voie, sur le club BCCL de DÉVELOPPEMENT du bac à sable — pour
  en reprendre une, rejouer `frontend/scripts/capture-landing-shots.mjs`
  (`frontend/scripts/README-capture-landing-shots.md`), qui anonymise le DOM à chaque capture (nom/
  code club remplacés, blasons retirés, chrome de dev masqué). La vue **Conflits** (qui liste des
  coachs) est admise sous cette condition — un nom de démo fictif suffit, elle n'est plus écartée
  par principe — décision fermée, `specs/courantes/etat-des-lieux.md` §2.
