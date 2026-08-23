---
paths:
  - "system-pages/**"
---

# Pages système — conventions & pièges (chargé quand system-pages/ est touché)

> Les pages servies **quand l'application est morte** : la 503 subie (panne) et la page de
> maintenance choisie. Servies par **Caddy**, sur la VM, hors Docker — jamais par la stack ni
> par le conteneur `frontend`. **Elles n'ont pas d'`AGENTS.md`** : tout ce qui les concerne
> tient ici. Câblage Caddy : [`../../docs/ops/Caddyfile.example`](../../docs/ops/Caddyfile.example) ;
> dépôt + runbook : [`../../docs/ops/deploy.md`](../../docs/ops/deploy.md).

- **Zéro build.** HTML/CSS statique servi tel quel — pas de npm, pas de bundler, pas de
  transpilation. On édite `503.html` et `maintenance.html` directement. N'introduis **aucune**
  chaîne de build : c'est ce qui rend ces pages increvables et déployables seules. C'est la
  propriété qui compte — elles s'affichent **quand tout le reste est mort**, elles ne peuvent
  donc venir ni du conteneur `frontend`, ni de l'app.
- **Aucun lien avec `frontend/` ni `landing/`** — pas d'import de composant, pas de CSS partagé,
  pas d'asset commun. La ressemblance visuelle est une **convention**, jamais une dépendance :
  la palette est **DUPLIQUÉE** de la landing (dupliquer est le comportement VOULU).
- **`system-pages/` est un FRÈRE de `landing/`, jamais dedans.** Le domaine nu sert `landing/`
  avec un `file_server` ; ranger ces pages dans `landing/` exposerait `amateo.app/503.html` à
  côté de la page de vente. Elles ne sont atteignables que par les blocs internes du site `app.`.
- **Marque JAMAIS en littéral dans le HTML.** Le nom n'apparaît que via `landing/config.js`
  (`<script src="/config.js">`, servi du disque par Caddy, même origine) — injecté par un script
  inline `if (window.LANDING_CONFIG) …` dans le logotype et le `<title>`. **Sans lui : titre
  neutre, logotype masqué, lien support masqué, page ENTIÈREMENT fonctionnelle.** Le repli est
  *sans marque*, jamais une marque recréée. Aucun `mailto:` en dur : l'adresse vient de
  `contactEmail`, et le lien reste masqué tant que le config n'a pas chargé.
- **Zéro dépendance réseau, inventaire imposé** : CSS **inline** · police **`system-ui`
  seule** (pas même une police auto-hébergée) · image **SVG inline ou rien** · **< 100 Ko** par
  page (borne DURE) — et [`../../scripts/test-system-pages.sh`](../../scripts/test-system-pages.sh)
  garde en plus une borne bien plus **SERRÉE, < 40 Ko** par page servie : à ~13-16 Ko réels, un
  garde à 100 Ko ne se déclencherait jamais (il ne garderait rien), 40 Ko laisse la marge tout en
  restant un vrai signal (arbitrage fondateur P5-22). Le chargement réseau se limite à
  **`/config.js`** (la marque, sur les DEUX pages) **plus `/maintenance-until`** — une 2ᵉ ressource
  same-origin servie du disque, **en MAINTENANCE seulement** (le compteur de retour ci-dessous).
  Rien d'autre ne vient d'ailleurs.
- **La scène décorative est VOISINE de l'in-app, jamais synchronisée** (P5-22 PR-2) : même dessin
  que `frontend/src/shared/components/ui/system-scene.tsx`, **inliné à la main dans chaque page**,
  palette dupliquée — **aucune source partagée, aucun test de parité**. C'est délibéré : un script
  d'inlinage serait une chaîne de build (interdite), un test de parité fabriquerait l'illusion d'une
  synchro que la règle nie. La ressemblance est une **convention**. Animation = **TRANSFORM pur +
  opacité de base pleine** → sous `prefers-reduced-motion`, `animation: none` suffit ; le bloc ne
  contient **aucun override d'opacité** (transposé d'un écran d'attente, il peindrait un faux
  « plein/succès » sous un titre de panne — même racine que la scène React, PR-1).
- **Compteur de retour = MAINTENANCE seulement** (P5-22 PR-2). La **503 SUBIE n'a AUCUN compteur** :
  sur une panne personne ne sait quand ça revient, un zéro atteint sans que rien ne change détruit la
  confiance, et la page étant en 503 il repartirait à zéro à chaque rechargement (décision fondateur).
  En maintenance, le témoin `maintenance.on` est **ENRICHI** : `echo "2026-…T…+02:00" > maintenance.on`
  y écrit l'heure de retour ; un **`touch` nu reste VALIDE** (fichier vide → pas de compteur, page
  intacte — rétro-compat runbook). La page lit **`/maintenance-until`** (jamais le témoin en clair) et
  en tire « Retour prévu vers HH:MM » + un décompte en TEXTE. Horodatage absent / vide / illisible /
  **déjà dépassé au chargement** → ligne **MASQUÉE**, jamais un compteur inventé ; **sans JS → rien**,
  page fonctionnelle. ⚠ La route `/maintenance-until` DOIT vivre **DANS le `route {}`** de
  `handle @maintenance` (même piège `rewrite`-avant-`handle` que `/config.js`, tranché au
  `caddy adapt`) : hors du bloc maintenance elle **exposerait le témoin** quand l'app est vivante.
- **Thème** : `prefers-color-scheme` en **CSS pur**, palette dupliquée de la landing, les deux
  variantes conçues ensemble. ⚠ Un accent qui « porte du texte » S'INVERSE entre thèmes (sombre
  sur clair / clair sur sombre) : le bouton plein a ses **propres** tokens fond/texte pour rester
  contrasté dans les deux sens — ne pas peindre du blanc sur un accent clair.
- **Passe de design obligatoire** dès qu'on remanie l'apparence — ce sont des pages **publiques** :
  agent `ui-ux-pro-max`, bornée à l'apparence, elle ne valide rien. ⚠ **Le contraste WCAG se
  vérifie dans un vrai navigateur**, dans les DEUX thèmes (jsdom n'atteste rien).
- ⚠ **Le bloc `/config.js` DOIT vivre dans un `route {}`.** Hors `route`, Caddy réordonne les
  directives selon SON ordre canonique, où **`rewrite` passe AVANT `handle`** : le rewrite global
  vers la page tire en premier, l'URI vaut déjà `/system-pages/…` quand le matcher `/config.js`
  est enfin testé, et le bloc devient du **code mort** — la page sert alors son propre HTML au
  `<script src>`, `window.LANDING_CONFIG` reste indéfini, et l'écran tombe silencieusement sur son
  repli sans marque. Vécu et corrigé le 2026-08-22 (revue sécu). **Se tranche au `caddy adapt`,
  jamais à la lecture** : le JSON adapté donne l'ordre réel des handlers.
  Le `status 200` explicite du config.js dans `handle_errors` est du même ordre — sans lui il
  hérite du 502/503/504 de la requête d'origine.
- ⚠ **Une assertion doit DISCRIMINER.** Asserter `LANDING_CONFIG` sur la réponse `/config.js` est
  un faux positif : `system-pages/503.html` contient lui-même `if (!window.LANDING_CONFIG)`, donc
  le test lisait la page d'erreur et passait au vert sur une conf morte. Asserter l'**affectation**
  (`window.LANDING_CONFIG = {`), le **Content-Type JS**, le **statut**, et l'**absence** du
  marqueur de la page. Règle générale : un marqueur présent des DEUX côtés ne prouve rien.
- **Non testé = inexistant** : [`../../scripts/test-system-pages.sh`](../../scripts/test-system-pages.sh)
  (Docker seul, tout au curl) prouve le comportement de bout en bout, sa conf de test étant
  **dérivée par `sed`** de `Caddyfile.example` — une seule source. Toucher une page ou le
  câblage Caddy sans repasser ce script, c'est livrer à l'aveugle.
- ⚠ **Statut `503`, jamais 200** — pour la panne comme pour la maintenance (+ `Retry-After` en
  maintenance). Un 200 ferait indexer la page à la place de l'app, dirait « tout va bien » aux
  sondes (tuant le garde-fou anti-oubli), et servirait du HTML en succès aux `fetch` des onglets
  ouverts. Décision fondateur.
- ⚠ **Une erreur de l'APPLICATION n'est jamais remplacée** : `handle_errors` ne se déclenche que
  sur les erreurs de Caddy lui-même (proxy qui ne joint pas l'amont). Un 404 tenant / 403 / 500
  applicatif traverse le proxy tel quel — les écrans de la SPA restent souverains quand l'app vit.
