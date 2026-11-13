---
paths:
  - "landing/**"
---

# Landing — conventions & pièges (chargé quand landing/ est touché)

> La vitrine publique : huit pages statiques (`index.html` accueil, `offres.html`, `faq.html`,
> `fonctionnalites.html`, `vos-donnees.html`, `qui-fait.html`, `evolutions.html` journal,
> `mentions-legales.html`).
> **Elle n'a pas d'`AGENTS.md`** : tout ce qui la concerne tient ici.

- **La page s'adosse à [`specs/courantes/modules-produit.md`](../../specs/courantes/modules-produit.md)**
  — la carte des modules en langage club, promesse + preuve + capture ; toute nouvelle section
  vitrine vérifie ses affirmations contre ce fichier avant d'écrire un mot.
- **Zéro build.** HTML/CSS statique servi tel quel — pas de npm, pas de bundler, pas de
  transpilation. On édite les pages et `assets/` directement. N'introduis **aucune** chaîne de
  build : c'est ce qui rend ce site increvable et déployable seul.
- **Aucun lien avec `frontend/`** — pas d'import de composant, pas de CSS partagé, pas de brique
  commune avec l'app. Les deux zones se ressemblent par **convention**, jamais par dépendance.
  Dupliquer une couleur ici est le comportement VOULU. **Entre les huit pages**, en revanche, le
  socle est partagé : `assets/site.css` (polices, jetons, en-tête, boutons, pied, gabarit
  `main.page` des pages encore à l'ancien habillage) et `assets/site.js` (classe `js` sur `<html>`,
  logo et liens depuis `config.js`, burger, glissement d'ancre, mesure d'audience). **Une feuille et
  un script par page interactive**, en plus du socle : `home.{css,js}`, `offres.{css,js}`,
  `faq.{css,js}` — IIFE autonomes, aucun global partagé, chargés en fin de `<body>` après
  `site.js`. `site.js` marche sans `config.js` (chaque bloc a son `try/catch` et teste la présence
  de ses éléments). **Zéro `<script>` ni `<style>` inline** (le JSON-LD, qui n'est pas du code,
  fait seul exception), **zéro ressource tierce**. **Toute ressource locale (`config.js`, feuilles,
  scripts) porte le MÊME `?v=` sur les huit pages**, `config.js` avant `site.js` avant le script de
  la page (gardes `LandingDiscoverabilityTest`) — à bumper ensemble. Les `url()` d'un fichier CSS
  sont relatifs à lui (`fonts/…`, `brand/…`).
- **La marque s'écrit EN HTML ; liens, coordonnées et logo vivent dans `config.js`** (P4-391,
  2026-10-31, remplace « config.js SEUL »). Un robot qui n'exécute pas le JS (moteur, aperçu de
  messagerie, IA) ne doit jamais lire « … » : `<title>`, `og:title`, `.brand`, `aria-label`,
  `<h1>` et JSON-LD portent le nom en clair dans le HTML servi, et AUCUN script ne réécrit plus
  la marque ni le titre. **Même règle pour les `href` des boutons d'appel** (inscription,
  connexion, confidentialité, démo — P4-407) et pour les mentions légales (éditeur, statut,
  hébergeur) : écrits en clair, jamais `href="#"` ni une obligation légale qui dépend du JS.
  `config.js` reste la source de ce que `site.js` et `system-pages/` consomment (logo, liens
  `appUrl`, mesure d'audience) et la VALEUR DE RÉFÉRENCE des copies en clair. Le nom commercial
  **est tranché depuis le 2026-08-15** (produit **Amateo**). **L'éditeur et le responsable de
  publication sont une PERSONNE, à titre personnel** (`editor`, `legal.editorStatus`), tant
  qu'aucune société n'est immatriculée — jamais « Maratech », « micro-entreprise » ni SIRET dans
  `landing/` (garde `LandingClaimsTest`) ; le jour J, une ligne de roadmap aligne vitrine, app et
  e-mails. `LandingDiscoverabilityTest` et `LandingClaimsTest` lient les deux côtés (HTML ⇄
  `config.js` : `brand`, `editor`, `editorStatus`, `siteUrl`, hébergeur) — **renommer = changer
  `config.js` ET `grep -rn` la valeur dans `landing/` et `system-pages/`** (checklist de
  renommage) ; les tests rougissent sur toute dérive.
  ⚠ `appUrl` s'écrit **sans slash final** — les CTA concatènent (`appUrl + "/register"`).
  **Clé `siteUrl` (P4-302, 2026-10-05)** : l'URL de la vitrine elle-même (domaine nu, même règle
  sans slash final) — consommée par `system-pages/503.html`/`maintenance.html` pour pointer leur
  logotype cliquable vers la page de vente plutôt que vers l'app morte ; point unique, un
  changement de domaine ne touche que cette ligne.
  **Exception P4-391 :** chaque `<img data-brand-logo>` porte aussi `src="assets/brand/mark.svg"` en dur, pour le rendu sans JS ; changer `logo` dans `config.js` impose donc de le changer aussi dans le HTML.
  **Le logo suit le même patron (P5-25, 2026-09-25)** : `config.js` clé `logo` (source définitive
  du handoff marque, `assets/brand/`) injecté par `site.js` sur chaque `[data-brand-logo]` — jamais
  un autre chemin d'asset que le repli ci-dessus. `config.js` est chargé par chaque page avec
  `?v=<date>`, à bumper à chaque ajout/retrait/renommage de clé — sinon un visiteur de retour garde
  l'ancien fichier en cache et toute nouvelle clé lit `undefined` (incident 2026-09-25, logo cassé ;
  l'injection est défensive depuis, mais le `?v=` est ce qui rend le vrai logo tout de suite).
  **Le logotype recopie désormais le patron `BrandMark` de l'app (P4-274, 2026-09-29)** : `logo`
  pointe `assets/brand/mark.svg` (les trois arcs SEULS, sans le disque blanc du favicon
  `icon.svg`) — un mark sans fond — et le MOT (la marque, écrite dans le HTML, en minuscules par CSS) est rendu en TEXTE
  (`.logo-word`) ; repli
  défensif inchangé, si l'icône manque/échoue seul le mot reste (marque toujours lisible).
- **Fond uni, plus de motifs sur la vitrine** : `body { background: var(--b-board) }` (`#f5f7f4`,
  `assets/site.css`). `assets/brand/fond.svg` reste sur disque pour la seule image de partage
  (`scripts/brand-og/og.html`) ; aucune page ni feuille ne le référence. L'app garde son propre
  fond : `specs/courantes/identite-visuelle-produit.md` § « Le fond d'écran ».
- **Exceptions « domaine en dur » (P5-24, étendues par P4-391)** : `og:url`/`og:image`,
  `<link rel="canonical">` de chaque page, le JSON-LD, `sitemap.xml` et `robots.txt` (ligne `Sitemap:`) portent
  des URL ABSOLUES en dur (`https://amateo.app/…`) — un crawler (messagerie, réseau social,
  moteur) ne charge pas `config.js` et n'exécute pas de JS : une valeur injectée n'existerait
  jamais pour lui. Le titre et `og:title`/`og:description` portent aussi la marque en clair
  (« Amateo — … »). Un changement de domaine touche donc ces fichiers EN PLUS de `siteUrl` ;
  `LandingDiscoverabilityTest` (canonical ⇄ `siteUrl`, URL absolues limitées à nos hôtes et aux
  vocabulaires schema.org/sitemaps.org) et `IndexationPolicyTest` (`Sitemap:` ⇄ `siteUrl`,
  sitemap parsable listant TOUTES les pages `landing/*.html`) le rendent mécanique. Chaque page
  porte son propre `<title>`/`og:title`/description (table nommée dans le test, clés == pages). `og:image` pointe
  `assets/brand/og.png` (source versionnée `scripts/brand-og/`, README de régénération à côté —
  piège du cache immuable : renommer le fichier à toute retouche visuelle). Détail :
  `specs/courantes/identite-visuelle-produit.md` § « Ce qui reste à venir ».
- **Balisage JSON-LD `schema.org` (`<script type="application/ld+json">` dans `index.html`)** :
  **STATIQUE, écrit dans le HTML** depuis P4-391 (il était injecté par script depuis `config.js`
  avant, donc invisible à un robot sans JS) — un graphe `SoftwareApplication` (le produit) +
  `Organization` (l'éditeur). Sa description recopie la `<meta name="description">` (garde :
  `LandingDiscoverabilityTest`). `offers` est **omis volontairement** : seul le palier Découverte
  est public (gratuit), les paliers payants restent « sur demande » — inexprimable proprement en
  une offre/fourchette tant que leur prix n'est pas public. L'éditeur y est un nœud `Person`
  (`#editor`, nom = `config.js` `editor`, garde `LandingDiscoverabilityTest`), pas une
  `Organization`. Les sept autres pages n'ont pas ce bloc.
- **Pas de thème sombre, ni bascule, ni asset `-dark`** : la vitrine n'a qu'un rendu clair.
  Aucun `data-theme`, `[data-theme-toggle]`, `localStorage` ni `prefers-color-scheme` dans les pages
  et `assets/*.{js,css}`, aucun fichier `*-dark.*` sous `assets/` (garde `testNoDarkThemeAnywhere`).
  Un asset ne varie donc plus par thème : une capture = un fichier.
- **Polices hébergées, sous licence OFL** : `assets/fonts/` porte `archivo-latin.woff2` (corps,
  400-800), `caveat-latin.woff2` (manuscrit) et `bricolage-latin.woff2` (titres), chacune avec son
  `OFL-*.txt` ; les `@font-face` de `site.css` pointent ces fichiers. Aucune feuille ne charge rien
  d'ailleurs (`@import`, `url()` absolue) — un visiteur ne livre jamais son IP à un service de
  polices. Gardes : `testFontsAreSelfHostedWithTheirLicence`, `testAbsoluteUrlsStayOnOurHosts`
  (balaie aussi `assets/*.{css,js}`). Ajouter une police = son `.woff2` + sa licence, même PR.
- **Tout est lisible sans JavaScript.** Tout élément qui n'a de sens qu'avec JS (compte à rebours,
  curseur de palier, compteur de questions, démonstrations) porte `data-js-only hidden` ; `site.js`
  retire `hidden`. Le texte, les `href`, les onze `<details>` de la FAQ, les logos des clubs et leur
  `alt` sont dans le HTML servi. Sous `prefers-reduced-motion`, rien ne glisse et le compte à rebours
  est figé en jours (garde de forme `testAnimatedScriptsHonourReducedMotion` : tout script animé lit
  ce média ; le comportement se prouve par captures).
- **Compte à rebours de l'accueil** : `div.p-count[data-countdown]` contient `ol[data-deadlines]` et
  **six échéances écrites en dur** (`li[data-date="AAAA-MM-JJ"][data-question]`, dates croissantes ;
  `testCountdownDeadlinesAreWellFormed`). Quand la date réelle varie (comité, zone, championnat), le
  libellé nomme un repère exact et la question dit de quoi la date dépend. **Liste épuisée = bloc
  caché**, le héros s'affiche sans lui. Aucun test ne compare à la date du jour (il casserait à date
  fixe) : la vérification vit dans la doc de release (`docs/ops/deploy.md` § Déployer une release,
  étape 0) — à chaque release, au moins deux échéances à venir ; à la première release après le
  1er juillet, réécrire les six pour la saison suivante depuis le jeu officiel
  `fr-en-calendrier-scolaire` (`ImportSchoolHolidaysCommand`).
- **Clubs affichés (section `#clubs`, « Ils nous font confiance »)** : noms et logos EN CLAIR dans le
  HTML (`<li tabindex="0"><img alt="<nom>" width height><span aria-hidden="true"><nom></span></li>`),
  **liste fermée gardée par `testDisplayedClubsAreTheClosedList`** (constante `DISPLAYED_CLUBS`, nom →
  fichier, ordre exact ; aucun logo orphelin sous `assets/clubs/` ; JPEG ≤ 40 Ko, ≤ 400 px, sans
  métadonnées). Fichiers nommés d'après le nom affiché. **Ajouter ou retirer un club = un `<li>`, un
  fichier sous `assets/clubs/`, une ligne dans `DISPLAYED_CLUBS`, `?v=` bumpé** ; le test rougit si
  l'un des trois manque. La trace de l'accord du club vit hors dépôt.
- **Orange : un petit texte porte `--orange-ink`** (`#a35a00`), pas `--orange` (`#d47800`, réservé aux
  décors et aux chiffres ≥ 24 px gras). Sur fond terrain sombre le texte orange est `--peach`
  (`#ffd5bd`), sur la carte noire `--score-ink` (`#ffc24d`). Contrastes mesurés en navigateur.
- **Mise en page mobile (lot 4, 2026-10-09)** : ≤980px la barre passe au **burger** — un
  `<details class="nav-menu">`/`<summary>` NATIF (ouvre/ferme **sans JS** ; le JS n'ajoute que le
  confort : miroir `aria-expanded`, fermeture au clic sur une ancre, Échap → focus). Le panneau
  (`position:absolute` sous l'en-tête sticky, recouvre, **pas de scroll-lock**) **reprend** les
  liens de la barre — ancres + « Se connecter » + CTA : **deux jeux distincts** (inline masqué en
  mobile, panneau masqué en desktop), jamais partagés, chacun avec ses `href` écrits en clair
  (`site.js` ne fait que les recaler sur `config.js`, `querySelectorAll` sur `[data-cta]`). **En-tête et pied sont
  IDENTIQUES sur les huit pages, burger compris** (`header.site`, `a.logotype[href="/"]`, six entrées
  de `nav.top` : « Comment ça marche », « Fonctionnalités », « Offres », « FAQ », « Se connecter »,
  « Essayer gratuitement » ; seul `aria-current="page"` varie ; gardes
  `testHeaderIsIdenticalAndNamesTheSixEntries`, `testFooterIsIdenticalAcrossPages`). Le burger est
  `display:none` ≥981px (`site.css`, `@media (max-width: 980px)`), les liens inline restent. Le logo est en `flex-shrink:0` (le carré 40px ne
  s'écrase plus). Paragraphes de **contenu** à 16px ≤720px (`.pain p`/`.step p`/`.faq .a`, règle
  placée APRÈS leurs règles de base — à spécificité égale c'est l'ordre source qui tranche, pas la
  media query). Étapes en **1 colonne + séparateur horizontal** <600px. Cibles tactiles ≥44px en
  mobile par padding gonflé + marge négative de compensation (bornées ≤880px, le rendu desktop ne
  bouge pas). (aucun job CI ne couvre `landing/`, cf. ci-dessous.)
- **Contrôle visuel de la vitrine (règle 2026-11-13)** : quatre largeurs — **1920, 1280, 768 et 360 px** —
  avec **JavaScript coupé** et **mouvement réduit émulé**. Le burger (visible ≤980px) se vérifie ouvert
  et fermé à 768 et 360 ; à 1920 et 1280 la barre inline reste. Captures à plat dans `captures/`
  (gitignoré), nommées `vitrine-<chantier>-<page>-<largeur>.png`.
- **Les sept autres pages sont des pages statiques SŒURS d'`index.html`, pas des fragments** :
  même `<head>` (`site.css`, sa feuille de page s'il y en a une), pied IDENTIQUE sur les huit,
  `site.js` en fin de `<body>` après `config.js`. `offres.html` et `faq.html` ont leur propre habillage
  (`<main>`) ; `fonctionnalites`, `vos-donnees`, `qui-fait`, `evolutions` et `mentions-legales` gardent
  encore le gabarit `main.page` de `site.css`. Les liens
  vers l'accueil sont `/` (jamais `index.html…`). Une page ajoutée entre d'office sous les gardes
  (balayage `glob(landing/*.html)`, trait `LandingFiles`) : elle doit avoir son titre dans la
  table du test et son entrée dans `sitemap.xml`.
- **`evolutions.html` est le journal des évolutions, écrit à la main** — une entrée par version
  livrée, `<article class="release" data-version="X.Y.Z">`, ordre décroissant, langage club
  (contrat exact : docblock de `LandingChangelogTest`). **Il est couplé au déploiement** : le step
  « Check the changelog names this version » de `deploy.yml` refuse de construire un tag `v*`
  neuf sans entrée ; l'entrée s'écrit donc par la PR `chore/release-vX.Y.Z` AVANT de taguer
  (`docs/ops/deploy.md` § Déployer une release). Les chiffres de résultat (heures gagnées) ne
  vivent que sur `qui-fait.html`, comme objectif.
- **Le portrait de `qui-fait.html` s'ajoute par un couple de fichiers** (`assets/qui-fait/fondateur.jpg`
  sans métadonnées + `<figure class="portrait">` dans la page, alt = `config.js` `editor`) : l'un
  sans l'autre rougit `LandingClaimsTest`.
- **Glissement d'ancre animé en JS, pas `scroll-behavior: smooth` (P4-258, 2026-09-29)** :
  `html { scroll-padding-top: 76px }` (en-tête sticky 64px + marge ; dans `site.css`) reste pour le saut natif
  (arrivée avec `#hash` dans l'URL, JS absent) ; le clic sur un lien `href="#…"` interne est
  intercepté et animé en `requestAnimationFrame` par `site.js` (easeOutCubic, décélération maîtrisée, coupé net
  si l'utilisateur reprend la main ; la cible est RECALCULÉE à chaque pas — une police ou une image
  qui arrive en cours de route la déplace — puis un `scrollTo` final pose la position exacte) — `scroll-behavior: smooth` a été retiré, il aurait doublé le
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
  art. 6-III — éditeur et responsable de publication nominatifs, hébergeur Scaleway, tous écrits
  en clair ; renvoie vers la politique
  de confidentialité de l'app pour le volet RGPD). **Aucun FORMULAIRE n'est posté sur cette page** —
  la démo passe par un `mailto:` (client de messagerie du visiteur, rien n'est posté côté serveur) :
  un futur formulaire de contact POSTÉ déclencherait, lui, l'obligation RGPD complète —
  `business/administratif-mise-en-prod.md` §9. ⚠ **Nuance depuis P4-276 (mesure d'audience)** : la
  page n'est plus « zéro donnée » au sens strict — Umami mesure l'audience **sans cookie** (IP
  hachée côté serveur, agrégats anonymes), donc **pas de bandeau de consentement** mais une
  **transparence écrite obligatoire** : paragraphe « Mesure d'audience » de `mentions-legales.html`,
  section de `PrivacyPage.tsx` (app), registre `docs/security/rgpd.md` §2. La phrase d'effacement
  de l'espace du club de `vos-donnees.html` est celle de `PrivacyPage.tsx`, mot pour mot (parité
  gardée par `LandingClaimsTest` et le test de la page) : un délai se change des DEUX côtés.
- **Mesure d'audience — clé `analytics` de `config.js` (P4-276, 2026-10-06)** : `analytics:
  { scriptUrl, websiteId }`, point unique comme le reste de `config.js`. Le script Umami n'est
  injecté (par `assets/site.js`, donc sur les huit pages) que sous
  **double garde** : (a) les deux clés non vides ; (b) `location.hostname` égal à l'hôte dérivé de
  `siteUrl` (`new URL(siteUrl).hostname`, aucun nouveau littéral de domaine) — en local, sur :5173
  ou sur un autre domaine, **rien n'est injecté, aucune requête ne part**. Tout est défensif
  (try/catch, ancien cache sans la clé → page intacte). Le `websiteId` n'est **pas un secret** (il
  est visible dans toute page trackée) : sa place en git est correcte. Renseigner/retirer ces clés
  = **bumper le `?v=` commun dans les huit pages** (règle ci-dessus).
  L'instance Umami vit dans la stack prod (`docker-compose.prod.yml`, `docs/ops/prod-stack.md` §
  Mesure d'audience, runbook `deploy.md` §1.11). **Vitrine seulement** — jamais l'app (volet « app »
  de P4-276 encore ouvert en roadmap).
- **Aucun job CI dédié à `landing/`** (`.github/workflows/ci.yml` ne la mentionne nulle part, et
  ni `LandingDiscoverabilityTest`, `LandingClaimsTest`, `LandingChangelogTest` ni
  `IndexationPolicyTest` ne sont des steps du job `blocking-tests` : ils tournent dans
  `backend-tests`). Ces gardes **statiques** (le trait `LandingFiles` porte la lecture commune)
  vivent dans
  `backend/tests/` (lecture des fichiers suivis, HTML parsé sans exécuter ses scripts) et couvrent
  le référencement, les affirmations faites aux clubs et le journal ; la seule preuve d'une passe de design, de contraste ou de rendu reste un
  axe joué **à la main** dans un vrai navigateur, captures à l'appui dans `captures/` (racine du
  dépôt, gitignoré).
- **Une capture d'écran de l'app pour la vitrine s'exige sur le RÉSULTAT, pas sur une méthode
  unique** : (1) l'en-tête porte « Démo Basket Club », jamais un club réel ; (2) **aucune personne
  RÉELLE** — un nom de démo manifestement générique (ex. « Camille Durand ») est admis, remplacé
  dans le DOM au moment de la capture, jamais le nom d'une vraie personne ; (3) les données
  visibles sont publiques (codes d'équipe, clubs adverses, gymnases). Deux façons d'y arriver,
  toutes deux valides : le **seed démo** (`app:demo:seed`) ou une **prise de vue sur une stack
  réelle** avec le nom du club remplacé dans le DOM au moment de la capture. **Les 4 captures
  actuelles** (`planning.png`, `matchs.jpg`, `matchs-importer.jpg`, `matchs-conflits.jpg`) suivent la seconde voie, sur le club BCCL de DÉVELOPPEMENT du bac à sable — pour
  en reprendre une, rejouer `frontend/scripts/capture-landing-shots.mjs`
  (`frontend/scripts/README-capture-landing-shots.md`), qui anonymise le DOM à chaque capture (nom/
  code club remplacés, blasons retirés, chrome de dev masqué, libellés neutres des coachs — « Coach »
  + numéro de clé — remplacés par des prénoms génériques propres au script) et **refuse de capturer**
  sur une base non neutre (aucun coach, ou un coach hors libellé neutre : identités réelles injectées
  ou anciens surnoms — le message donne un compte, jamais une valeur). La vue **Conflits** (qui liste des
  coachs) est admise sous cette condition — un nom de démo fictif suffit, elle n'est plus écartée
  par principe — décision fermée, `specs/courantes/etat-des-lieux.md` §2.
