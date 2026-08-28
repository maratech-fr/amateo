# Frontend Strategy — TDD, Stack Fixée & Anti-patterns

Last verified @ 2026-08-29 (`documentation-update`, FRT-20/P4-117 — la prescription §1 « Queries
TanStack Query » disait « mock `ky` » : **faux**, corrigé — vérifié sur `frontend/src` que seuls
6 fichiers mockent `@/shared/api/client` contre le module `./api` voisin, patron réellement vivant
dans `features/cockpit/queries.test.tsx` et `features/wizard/queries.test.tsx` (nouveau ce lot).
Reste non re-touché cette passe : versions/plafonds §1-§2 (vérifiés le 2026-08-27, rien ne les a
fait bouger depuis). Historique des passes : `git log -p --follow frontend/docs/frontend-strategy.md`.)

> **Statut : le rebuild est LIVRÉ.** Les formulations « pour le rebuild » ci-dessous sont
> historiques ; le document reste la référence vivante des **versions de la stack**, des
> **anti-patterns bannis** et des **règles de préservation d'infrastructure**.
>
> Fixe le mandat de test, les versions de la stack, les anti-patterns et les règles de
> préservation d'infrastructure. Le détail fonctionnel (routes, composants, wizard) est dans
> `frontend-spec.md` et `frontend-wizard.md` — ce document ne les duplique pas.
>
> ⚠️ **`frontend/package.json` fait foi.** Ce fichier a laissé dériver ses tableaux de
> versions pendant des semaines tout en se présentant comme un verrou : il « figeait » des
> versions que personne n'utilisait. En cas de doute, lire le `package.json`, pas ce tableau.

---

## 1. Testing Strategy — TDD Mandatory

**TDD is MANDATORY for the frontend rebuild plan. Write tests FIRST, watch them fail (RED), implement minimal to pass (GREEN), then refactor (REFACTOR).**

Aucune exception. Chaque composant, hook, store, route et intégration API doit suivre le
cycle RED → GREEN → REFACTOR avant d'être considéré livrable.

### Règles d'application

| Étape | Action | Critère de sortie |
|------|--------|-------------------|
| **RED** | Écrire le test unitaire / d'intégration AVANT toute implémentation. Lancer le test — il doit échouer pour la bonne raison (assertion manquante, import absent, type error). | Sortie console montre l'échec attendu, pas une erreur de compilation non liée. |
| **GREEN** | Implémenter le code minimal pour faire passer le test. Pas de code défensif non testé. Pas de feature non demandée. | Tous les tests du cycle passent (exit 0). |
| **REFACTOR** | Améliorer la structure (extraction, renommage, typage) sans changer le comportement. Re-lancer les tests après chaque refactor. | Tests toujours verts après refactor. |

### Périmètre de test obligatoire

- **Composants UI** : tests de rendu (React Testing Library) — props, états, accessibilité ARIA.
- **Hooks personnalisés** : `renderHook` + scénarios de cycle de vie.
- **Stores Zustand** : tests d'état, d'actions, de `persist`/`migrate`.
- **Queries TanStack Query** : tests avec un `QueryClient` de test **réel** (`renderHook`,
  jamais un double du hook lui-même) et le module `./api` **voisin** mocké — pas `ky`
  directement (patron vivant : `features/cockpit/queries.test.tsx`,
  `features/wizard/queries.test.tsx`). Un test de COMPOSANT, lui, mocke légitimement les hooks
  de sa propre feature (`vi.mock("./queries", …)`) : le composant n'est pas responsable
  d'aller chercher la donnée — voir `specs/evolution/roadmap.md` (AUD-FRT-20) pour l'état de la
  couverture module par module.
- **Routes** : tests de navigation (React Router memory router), guards d'auth, redirections.
- **Intégration API** : mock `ky` via MSW ou interceptor, vérification des payloads et headers.

### Outils de test (versions fixées)

**Deux plafonds, pas un — et ils ne se recouvrent pas** (P4-116, 2026-08-21). Un test d'écran
peut rougir de deux façons qui n'ont pas la même cause et ne se règlent pas au même endroit :

| Plafond | Qui l'impose | Valeur | Ce qu'il garde |
|---|---|---|---|
| `testTimeout` | Vitest (`vitest.config.ts`) | **15 s** (défaut 5 s) | transformer un test **PENDU** en échec |
| `asyncUtilTimeout` | testing-library (`src/test/setup.ts`) | **5 s** (défaut **1 s**) | le budget de `findBy*` / `waitFor` |

⚠ **`findBy*` et `waitFor` n'obéissent PAS à `testTimeout`.** C'est le piège qui a rendu le
diagnostic d'origine incomplet : un `findByRole` qui abandonne au bout d'une seconde rapporte
« Unable to find an element », un échec qui RESSEMBLE à une assertion fausse — on ne le relie
pas spontanément à la charge de la machine. Les échecs mesurés sous contention tombaient à
**1,3 s**, très loin des 5 s qu'on croyait en cause.

⚑ **Pourquoi 15 s et pas 5 s.** Le cas le plus lourd du dépôt met **5,2 s sans aucune charge
concurrente** (`PeriodStructure.test.tsx` › « déplacer un créneau réservé » : une grille hebdo
entière, des centaines de cellules, re-rendue à chacun de ses quatre gestes). C'est du travail
réel. Un plafond qui rougit là-dessus ne mesure plus rien — il produit du bruit selon qui
tourne à côté. ⚠ **Le corollaire à ne pas perdre** : `slowTestThreshold` est posé à **3 s** pour
que « lent » veuille encore dire quelque chose maintenant que le plafond a bougé (le défaut de
300 ms surlignait à peu près tous les tests d'écran, donc ne signalait plus rien). Un test
au-delà de 3 s est colorié dans le rapport : c'est là qu'on regarde si le scénario a dérivé,
**avant** qu'il n'atteigne le plafond.


| Outil | Version (`frontend/package.json`) | Rôle |
|------|---------|------|
| Vitest | 4.x | Runner de test (config `vitest.config.ts` : jsdom, `globals`, setup `src/test/setup.ts`, exclut `tests/e2e/`) |
| @vitest/coverage-v8 · @vitest/ui | 4.x | Couverture · UI de debug |
| @testing-library/react | 16.x | Rendu et queries DOM |
| @testing-library/user-event | 14.x | Simulation d'interaction |
| @testing-library/jest-dom | 7.x | Matchers DOM |
| jsdom | 30.x | Environnement DOM |
| msw | 2.x | Mock réseau HTTP |
| @playwright/test | 1.x | E2E (`frontend/tests/e2e/`) |
| **vitest-axe** · **@axe-core/playwright** | 0.x · 4.x | **Assertions a11y** — suite unitaire (`src/test/a11y.test.tsx`) + spec de contraste e2e (`tests/e2e/a11y-contrast.spec.ts`) |
| storybook · @storybook/react-vite | 10.x | Atelier de composants (`npm run storybook`) |

> ⚑ **Ces tables portent la MAJEURE, jamais la mineure** (décision du 2026-08-19, rotation de
> fraîcheur). Elles donnaient `^4.1`, `^29.1`, `^10.68`… et **quatre avaient déjà dérivé** : une
> mineure bouge à chaque lot Dependabot, personne ne repasse ici, et le doc ment en silence. La
> majeure, elle, porte du SENS (React 19 → `createRoot`, Vite 8, Tailwind 4) et ne bouge qu'une
> fois par an. **La version exacte vit dans `frontend/package.json`** — cette table dit ce que
> `package.json` ne dit pas : à quoi sert chaque outil. On ne recopie plus, on pointe.
>
> Ces tableaux ne sont pas un verrou technique (rien ne les vérifie automatiquement) : ils ne
> valent que par leur exactitude.

### L'accessibilité est bloquante, pas indicative

`frontend/eslint.config.js` re-sévérise **tout le set `jsx-a11y` recommandé** en `error` via un
unique interrupteur `A11Y_LEVEL` (garde-fou WCAG 2.2 AA). Le remappage préserve les options
réglées de chaque règle et **laisse désactivées** celles que `recommended` désactive
délibérément (ex. `label-has-for`, qui double-signalerait des `label`/`id` correctement
associés). `label-has-associated-control` connaît les composants maison
(`Input`, `Select`, `TeamSelect`) pour ne pas crier au faux positif sur un
`<label>…<Input/></label>`. Repasser à `warn` ne se fait que pour débloquer temporairement un
gros refactor.

**Ce que le linter NE voit PAS : la taille des cibles.** WCAG 2.5.8 (AA) demande **24 × 24 px**
minimum, et aucune règle `jsx-a11y` ne mesure un rendu. Convention du dépôt, à appliquer à la
main sur tout bouton à **icône nue** : `rounded p-1` autour d'une icône `size-4` → 24 px.
Quand le padding casserait une densité voulue (pastille, poignée de tri), le compenser par une
**marge négative de même valeur** (`p-1 -m-1`, `p-1.5 -m-1.5` pour une icône `size-3`) : la
surface cliquable grandit, la mise en page ne bouge pas. Un `aria-label` ne dispense de rien —
il sert les lecteurs d'écran, pas la motricité (audit AUD-A11Y-12, 2026-08-08).

**Ce que le linter NE voit PAS non plus : une modale plus haute que l'écran.** WCAG 1.4.10
(reflow) exige que le contenu reste atteignable ; un panneau centré (`items-center`) qui
dépasse déborde **en haut ET en bas**, hors viewport, et sans zone défilante le seul recours
est de dézoomer le navigateur. **Le comportement vit dans les DEUX composants partagés**
(`shared/components/ui/modal.tsx` et `confirm-dialog.tsx` — deux copies du même markup) :
panneau `flex flex-col max-h-[calc(100dvh-2rem)]`, en-tête `shrink-0`, contenu enveloppé dans
`min-h-0 overflow-y-auto`. **Aucun écran ne doit re-borner sa hauteur localement** — c'est ce
que trois d'entre eux faisaient, avec trois valeurs arbitraires différentes, pendant que les
autres restaient cassés. `dvh` et non `vh` (sur mobile `vh` ignore la barre d'adresse), et
`min-h-0` est ce qui rend le défilement possible : sans lui un enfant flex refuse de rétrécir
sous son contenu et la zone « défilante » ne défile jamais. Gardé par
`modal-overflow.test.tsx` — jsdom n'ayant aucun moteur de mise en page, le test épingle les
classes qui portent le contrat, faute de pouvoir mesurer le débordement (même limite qu'A11Y-06
pour le contraste). Retour fondateur 2026-08-11.

### La passe de design — quand on invoque `ui-ux-pro-max`, et quand on s'en abstient

Le pack `ui-ux-pro-max` est installé (décision fondateur révisée le 2026-08-11, état des lieux
§2), et son usage est **borné**. Il ne s'invoque pas à chaque PR frontend : 5 packs de design en
contexte permanent, ce sont des doctrines contradictoires à chaque session — c'est le motif qui
avait fait écarter leur installation, et il tient toujours. Sa valeur est le **crible ponctuel**.

**Règle (élargie le 2026-08-21, décision fondateur) : une passe de design se lance quand un écran
naît, change d'APPARENCE, **ou qu'une décision d'INTERACTION est arrêtée**.**

L'énoncé d'origine ne parlait que d'apparence, et bornait la passe aux écrans **publics**. Les deux
limites sont tombées le 2026-08-21 sur le lot C (l'écran de chargement bloquant) : écran **interne**
au wizard, et défauts qui n'avaient rien de visuel — voir la ligne de résultats ci-dessous.

| Cas | Passe design |
|---|---|
| Nouvel écran, nouvelle page (publique **ou interne**), refonte visuelle | **oui** |
| Changement de mise en page, de couleurs, de typographie | **oui** |
| **Décision d'interaction** : ce qui bloque, ce qui attend, ce qui prend le focus, ce qui s'annonce à un lecteur d'écran, ce dont on peut sortir | **oui** |
| Correctif de comportement sans décision d'écran, test, renommage, refactor | non |
| Correction d'un bug UI **déjà identifié** | non — on corrige, et on pose un garde |

⚑ **La passe se lance AVANT que la décision soit figée**, pas en relecture après coup : sur le lot C
elle a renversé un choix déjà arbitré par le fondateur (voir ci-dessous). Passée après
l'implémentation, elle aurait coûté une réécriture.

**Dans un agent**, pas dans le fil principal : une passe sur plusieurs écrans consomme beaucoup
de contexte et ne doit remonter que ses findings. Les agents `general-purpose` et `coder` portent
l'outil `Skill` ; `Explore`, `planner` et les `cavecrew-*` ne l'ont pas.

⚠ **Le skill ne MESURE rien, et l'écrire ici évite de le croire.** Il lit du code et des décisions
de design ; il ne rend aucune page, ne calcule aucun contraste, ne mesure aucun débordement. Ce
qu'il sait faire, en revanche, c'est **confronter une décision à un corpus de règles** — et là il
tranche (lot C, ci-dessous). Distinguer les deux est ce qui rend la règle utilisable : on
l'interroge sur des CHOIX, jamais sur un RENDU. Mesuré le 2026-08-11 : sa base de
99 règles UX ne contient **rien** sur la hauteur d'une modale — sa seule règle « modale » porte
sur la confirmation d'un geste destructif, et sa ligne la plus proche dit « no horizontal
scroll », l'axe opposé. **Il n'aurait pas attrapé le défaut de reflow du même jour.** Ce qui
valide, ce sont les gardes : Vitest, l'e2e Playwright, les tests d'a11y.

Ce qu'il apporte quand on le sollicite, mesuré trois fois :

- **Landing, PR #502** (apparence) : 2 échecs WCAG de contraste invisibles aux gardes jsdom, 2 bugs
  de rendu, une rupture de ton, et 17 tirets cadratins de cadence IA.
- **Lot C, 2026-08-21** (interaction) : il a renversé **deux décisions déjà arbitrées**. (1) Le rôle
  ARIA du voile : `role="alertdialog"` écarté au profit de `role="status"` + `aria-live="polite"` —
  `alert`/`alertdialog` est réservé à ce qui exige une attention immédiate, vole le focus, et
  PROMET un dialogue dont on peut sortir. (2) La règle « on ne relâche jamais les clics » écartée :
  `progressive-loading` et `escape-routes` interdisent un blocage long sans issue — d'où l'abandon
  explicite qui annule la requête, seule sortie qui ne rouvre pas le trou de concurrence. Il a aussi
  listé sept manques (barème `z-index`, retour du focus, flou trompeur, contraste par thème, chemin
  d'échec, chiffres tabulaires, `inert`). **Aucun de ces défauts n'était visuel** — c'est pourquoi la
  règle ne parle plus seulement d'apparence.
- **Largeurs, 2026-08-21** (P4-107 3ᵉ tranche) : il a imposé **une borne à la décision** — une
  échelle qui grandit avec le viewport doit TERMINER sur un plafond fixe (`DON'T Full-width text on
  large screens`, `DON'T Let text span full viewport width`) — et rappelé la seule mesure chiffrée
  de son corpus, 65-75 caractères par ligne, **valable à l'intérieur d'un conteneur élargi** : c'est
  d'elle que vient le `[&_p]:max-w-prose` de `FichePage`. ⚑ **Et une de ses conclusions a été
  ÉCARTÉE, ce qui vaut d'être écrit** : il désignait le vrai coupable dans le shell pleine largeur
  (son corpus nomme `w-full (no max-width)` comme l'anti-pattern) et prescrivait de le re-capper à
  `max-w-7xl`. C'est la décision fondateur du 2026-08-18, prise sur usage réel d'écrans denses —
  **une règle générique de corpus ne renverse pas un retour terrain**. La passe TRANCHE contre un
  corpus ; elle ne connaît ni l'usage ni l'historique du produit.

---

## 2. Stack Versions Fixed

Les versions suivantes sont **figées** pour toute la durée du rebuild. Aucune mise à jour
de version majeure ou mineure sans décision explicite et re-vérification de compatibilité.

| Package | Version (`frontend/package.json`) | Rôle | Notes |
|---------|--------------|------|-------|
| react / react-dom | 19.x | Framework UI / rendu DOM | React 19 — pas de ReactDOM.render, createRoot obligatoire (voir §3) |
| vite | 8.x | Bundler / dev server | Plugin `@tailwindcss/vite` |
| typescript | 6.x | Typage | `~6.0` = patch libre, minor figée |
| tailwindcss | 4.x | CSS utility-first | Configuration via CSS `@theme`, pas `tailwind.config.js` (voir §3) |
| @tanstack/react-query | 5.x | Server state | v5 — pas de `onSuccess` (voir §3) |
| zustand | 5.x | Client state | v5 — `migrate()` requiert null check (voir §3) |
| ky | 2.x | Client HTTP | v2 — API fetch moderne |
| @dnd-kit/core + sortable + utilities | ^6.3 / ^10.0 / ^3.2 | Drag & drop | Accessible ; utilisé pour le tri des équipes (wizard) |
| react-router | 8.x | Routing | Data router (`createBrowserRouter`), **`lazy` par route** (P4-6), nested layouts. ⚠ paquet **`react-router`**, PAS `react-router-dom` |
| lucide-react | 1.x | Icônes | SVG tree-shakeable |
| @sentry/react | 10.x | Reporting d'erreurs | **Erreurs seules** — pas d'APM, pas de replay, `tracesSampleRate: 0` (quota free tier préservé). DSN absent → init sautée, SDK inerte. ⚠ **L'activer demande DEUX gestes** (P4-65) : poser `VITE_SENTRY_DSN` au build **ET** autoriser l'hôte d'ingestion du DSN dans `connect-src` (`docker/frontend/csp.conf`, qui n'autorise aucun tiers). Le DSN seul initialise le SDK et la CSP jette chaque envoi **en silence** ; un garde de build (`frontend/tooling/sentryCspGuard.ts`) refuse désormais cette combinaison. INF-01 |
| @radix-ui/react-label + react-slot | ^2.1 / ^1.1 | Primitives UI | Base des composants shadcn-style de `shared/components/ui/` |

> **FullCalendar n'est PAS installé** : la grille planning est un composant custom
> (`src/features/planning/WeekGrid.tsx`). La liste exhaustive des dépendances vit dans
> `frontend/package.json` — source de vérité.

### Règles de verrouillage

1. `package.json` utilise des plages `^`/`~` ; `package-lock.json` est la source de
   vérité effective des versions installées — tout changement de version doit être
   reflété dans le lockfile.
2. Une mise à jour de version majeure = un commit dédié + re-run complet des tests
   (Vitest) + vérification `tsc --noEmit` + `npm run build`.

---

## 3. Anti-patterns Banned

Les patterns suivants sont **interdits** dans le code du rebuild. Tout PR les introduisant
est rejeté automatiquement.

| # | Anti-pattern | Pourquoi banni | Correct à la place |
|---|-------------|----------------|-------------------|
| 1 | `ReactDOM.render(...)` | Supprimé en React 19 — lance un avertissement puis casse en production. | `createRoot(container).render(...)` |
| 2 | `onSuccess` dans `useQuery` / `useMutation` (TanStack Query v5) | Supprimé en v5 — causait des effets de bord implicites et des fuites de state. | `useEffect` sur `data`/`isSuccess`, ou `select` pour transformer les données. |
| 3 | `migrate()` sans null check dans Zustand 5 | `persist` v5 passe `persistedState` potentiellement `null` — un `migrate` qui assume un objet non-null lance une `TypeError`. | `migrate: (persistedState: unknown, version: number) => { if (persistedState === null) return initialState; ... }` |
| 4 | `@apply` dans des composants Tailwind v4 | Tailwind v4 déprécie `@apply` dans les composants — casse l'extraction utility-first et le tree-shaking CSS. | Composer avec des classes utility directement, ou extraire un composant React réutilisable. |
| 5 | `tailwind.config.js` (fichier JS de config) | Tailwind v4 remplace la config JS par la directive CSS `@theme` dans le fichier CSS principal. Le fichier JS est ignoré ou cause des conflits. | Définir les tokens (couleurs, fonts, breakpoints) via `@theme { ... }` dans `src/index.css`. |
| 6 | Lire `error.response` dans un `catch` d'appel ky | ky 2.x **consomme lui-même** le corps de la réponse d'erreur et l'expose en `error.data` avant tout consommateur — re-lire la réponse lance `body stream already read`. C'est aussi pourquoi le client n'a **pas** de hook `beforeError`. | Lire **`error.data`** (`shared/api/errors.ts`, `errorMessage()`). |
| 7 | `data ?? []` sur une query en premier chargement | Fabrique un **vide crédible** (« aucun créneau », « aucun réglage ») qui pousse le gestionnaire à re-saisir (doublons) ou à valider une période qu'il croit vide. Symétriquement, traiter `isError` comme fatal détruit un écran qui fonctionne alors que seul un refetch d'arrière-plan a échoué. | `readState()` / `readFailed()` (`shared/lib/readState.ts`) — trois états sur le seul critère « a-t-on une donnée ? ». |

> L'anti-pattern historique « `eslint-config-prettier` pas en dernier » a été retiré : le
> projet n'utilise ni prettier ni `eslint-config-prettier` (aucun script `format`).

### Détection automatique

- **ESLint** (`frontend/eslint.config.js`) : `@eslint/js`, `typescript-eslint`,
  `eslint-plugin-react-hooks`, `eslint-plugin-react-refresh`, `@tanstack/eslint-plugin-query`,
  `eslint-plugin-jsx-a11y`. L'anti-pattern n°1 est **réellement bloqué** par une règle
  `no-restricted-syntax` qui interdit `ReactDOM.render`.
- **TypeScript** : `npx tsc -b --force`.
  ⚠️ **Jamais `tsc --noEmit`** : le `tsconfig.json` racine est un fichier **solution**
  (`"files": []` + `references`), donc `--noEmit` n'y voit **aucun fichier** — il sortait 0
  sans rien vérifier pendant que la CI (qui fait bien `tsc -b`) tombait sur les erreurs. Le
  `--force` est nécessaire aussi : un `tsbuildinfo` périmé court-circuite la vérification.
- **Code review** : checklist obligatoire dans le template de PR.

---

## 4. Infrastructure Reuse

Le rebuild est un **raz ciblé sur le code source** — l'infrastructure Docker existante doit
être **préservée**.

### Fichiers à préserver (NE PAS SUPPRIMER)

| Fichier | Rôle | Action |
|---------|------|--------|
| `docker/frontend/Dockerfile` | Image Docker du frontend (build multi-stage + Nginx) | **Préserver tel quel** — adapter uniquement si la structure de build change. |
| `docker/frontend/nginx.conf` | Config Nginx (proxy `/api` → backend, `/exports` → backend, `/bundles/` → backend, `/.well-known/mercure` → hub, `/engine/` → engine, en-têtes de sécurité + CSP, SPA fallback) | **Préserver tel quel** — la config proxy est validée et fonctionnelle. |

> ⚠️ **Écart connu, non tranché** : le bloc `location /engine/` → `http://engine:8000/` existe
> toujours dans `docker/frontend/nginx.conf`, alors que le proxy `/engine` a été **supprimé**
> côté Vite (FRT-17) au nom de la frontière « le frontend ne contacte jamais l'engine »
> (`CLAUDE.md` §2). Aucun code de `frontend/src/` ne l'appelle, mais il ouvre une route que
> cette frontière interdit. Décision fondateur en attente.

### Périmètre du raz

- **Raz s'applique à** : `frontend/src/` uniquement (composants, hooks, stores, routes,
  styles, types, utils).
- **Raz ne s'applique PAS à** : `docker/frontend/`, `frontend/public/` (assets statiques
  si présents), `frontend/index.html` (point d'entrée HTML), `frontend/package.json` et
  `frontend/package-lock.json` (mis à jour selon §2, pas razé).

### Règle de préservation

> Toute opération de raz ou de reset du frontend doit explicitement exclure
> `docker/frontend/Dockerfile` et `docker/frontend/nginx.conf`. Ces fichiers représentent
> l'infrastructure de déploiement validée et ne sont pas du code source applicatif.

---

## 5. Références croisées

| Document | Relation |
|----------|----------|
| `frontend-spec.md` | Spécification forward complète (routes, composants, stack) — ce document en est le complément stratégique. |
| `frontend-wizard.md` (T12) | Spécification du wizard d'onboarding — non dupliquée ici. |
| `backend-inventory.md` | Inventaire backward du backend — référencé par `frontend-spec.md`. |
| `openapi-snapshot.json` | Snapshot OpenAPI du backend — source de vérité pour les contrats API. |
| `AGENTS.md` | Contexte agent (commandes dev, architecture, gotchas). |
