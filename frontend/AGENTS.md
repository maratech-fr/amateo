# Amateo — Frontend Agent Context

> React 19 · Vite 8 · TypeScript ~6.0 · Tailwind 4. The web UI of the club-scheduling
> platform. Rebuilt from scratch and **active** — every path below exists in `src/`.
>
> Canonical detail lives in `README.md` (role & boundaries) and
> `docs/frontend-spec.md` (routes, state, API contract). This file is the
> agent cheat-sheet: what breaks, what is a trap, what is non-negotiable.

---

> ⚑ Les pièges qui rendent un test **vert à tort** (image tooling, `dist` cuit,
> `tsc --noEmit`, jsdom sans moteur de mise en page) sont AUSSI dans
> [`.claude/rules/frontend.md`](../.claude/rules/frontend.md), **chargé automatiquement** dès
> qu'un fichier de `frontend/` est touché — ce fichier-ci ne l'est pas.

## Boundaries (never cross)

- Talks to the backend **only** via `/api/*`. **Never contacts the engine directly** —
  generation goes through `POST /api/schedules/{id}/generate` and the backend calls the
  engine. There is deliberately **no `/engine` proxy** in `vite.config.ts` (FRT-17).
- Sends **no `X-Club-Id` header**: the tenant is resolved server-side from the JWT
  membership (see `../backend/docs/TENANT.md`). A spoofed header would 403 anyway.
- Sends `X-Season-Id` **only** when the manager has explicitly picked a season
  (`seasonStore`); absent = the server derives the current season. The server validates it
  either way — it is never trusted client-side.
- API URIs are **snake_case** (`/api/team_coaches`, `/api/venue_training_slots`,
  `/api/priority_tiers`, `/api/schedule_slot_templates`…).
- Always relative URLs. **Never hardcode a host** — `prefix: "/api"` uses the Vite proxy in
  dev and Nginx in prod.

---

## Layout

```
frontend/
├── src/
│   ├── main.tsx                 # Entry: Sentry init, pre-paint theme, createRoot
│   ├── index.css                # Tailwind 4 @theme tokens + --accent slots
│   ├── app/                     # Shell & routing
│   │   ├── router.tsx           # `AppRouter`: builds `createBrowserRouter(routes)` on first use
│   │   ├── routes.tsx           # The `RouteObject[]` tree + per-route `lazy` (see below) — moved
│   │   │                        # out of router.tsx (FRT-29) so it stays a non-component export
│   │   ├── RootShell.tsx        # Technical root: carries the navigation-pending net
│   │   ├── RouteErrorBoundary.tsx / ErrorBoundary.tsx
│   │   ├── AppLayout.tsx        # Header (club logo = home link) + account menu
│   │   ├── AuthGuard.tsx        # Token / membership / onboarding gates
│   │   ├── SeasonSelector.tsx · SeasonTransitionBanner.tsx · ReadonlySeasonBanner.tsx
│   │   └── providers.tsx · DevClock.tsx · seasonTransition.ts
│   ├── features/                # One folder per domain, each `{api,queries,store}.ts`
│   │   ├── admin/               # Superadmin console (/admin) — own session client
│   │   ├── auth/                # Login · register · verify-email · password · waiting
│   │   ├── club/                # /club hub: identity (logo/accent), FFBB info, requests
│   │   ├── coach-wishes/        # #10 doléances: modal, campaign, PUBLIC page, radar badge
│   │   ├── cockpit/             # / home: season-plan banner, month calendar, radar,
│   │   │                        # FbiDeadlineCard (RMM-6 PR-3: matches FBI-entry reminder + login escalation)
│   │   ├── legal/               # /confidentialite
│   │   ├── matches/             # /matchs (guided loop, 5 derived rail steps) + /matchs/configuration (rare setup)
│   │   │                        # + /matchs/reconciliation (RMM-4, FBI écarts per-field — delivered, two channels: xlsx deposit + FFBB API)
│   │   ├── planning/            # /planning work loop: WeekGrid, toolbar, exports
│   │   ├── profile/             # /profile
│   │   ├── season-transition/   # Season pivot banner + re-dating dialog
│   │   └── wizard/              # 6-step data entry (see `lib/steps.ts`)
│   ├── shared/
│   │   ├── api/                 # client.ts (ky) · collection.ts (JSON-LD) · errors.ts
│   │   ├── components/ui/       # Primitives (shadcn-style) — see "Primitives that matter"
│   │   ├── hooks/               # useApplyTheme · useApplyClubTheme
│   │   ├── lib/                 # readState, teamTiers, color, palette, duration, …
│   │   └── stores/              # authStore · themeStore · seasonStore · toastStore · transitionUiStore
│   └── test/                    # Vitest setup + render helpers + a11y suite
├── tests/e2e/                   # Playwright (auth, journey, matches, a11y-contrast, …)
├── vite.config.ts               # Plugins, `@/` alias, dev proxies
├── vitest.config.ts             # jsdom, globals, setup, excludes tests/e2e
├── eslint.config.js             # Flat config — jsx-a11y is BLOCKING (see below)
└── Makefile                     # All tooling is Dockerized
```

Feature stores live at `features/<x>/store.ts` (`wizard`, `planning`, `matches`, `admin`);
cross-cutting ones at `shared/stores/`.

---

## Commands

**All tooling runs in Docker**; the host needs only Docker, Docker Compose and Make.

```bash
cd frontend
make install     # Build the Node tooling image
make dev         # Dockerized Vite dev server (5173)
make build       # Production image (tsc + Vite + Nginx, served on 8081)
make lint        # ESLint + TypeScript, in Docker
make test        # make lint, then Vitest
make coverage    # Coverage + ratchet (needs `make install` first, plancher `../coverage-floor.json`, ~4-5 min)
make exec        # Shell inside the tooling image
make start | stop | logs | shell | status   # Docker Compose helpers
```

⚠ **`coverageFloor.test.ts` anchors on `__dirname`, not `import.meta.url`** — under
`vitest run --coverage` the latter is not always `file:`-scheme, and `new URL(...,
import.meta.url)` throws `ERR_INVALID_URL_SCHEME`.

### ⚠ Trap: never `tsc --noEmit`

`make lint` runs `npm run lint && npx tsc -b --force`. The root `tsconfig.json` is a
**solution file** (`"files": []` + `references`), so `tsc --noEmit` sees **zero files**: it
exits 0 having checked nothing, while CI (which runs `tsc -b`) fails on the errors it
skipped. `--force` is also required — a stale `tsbuildinfo` short-circuits the check.

### ⚠ Trap: an e2e run can validate the PREVIOUS build

The `frontend` compose service **builds its own image** (`docker/frontend/Dockerfile`, Nginx
on 8081) — `dist` is **not** a bind mount, and `frontend-tooling` is a COPY image with no
mount either. So `npx vite build` inside the tooling container writes into that container
and is thrown away: the app served on 8081 does not move, and an e2e launched afterwards
**passes against the old bundle**. Before any e2e that must see your change:

```bash
docker compose build frontend && docker compose up -d --force-recreate frontend
```

Only `frontend-dev` (profile `dev`, port 5173) mounts `./frontend` — that is the hot-reload
path, not what the e2e targets. Found the hard way on P4-43: the journey spec went green
while a screenshot showed the old toolbar.

E2E Playwright **is** fully Dockerized: `make -C frontend e2e` (compose profile `tools`,
service `e2e`) — it needs the stack **and** `make -C frontend dev` running. The target also
carries the superadmin preflight (it seeds the account and exports its TOTP secret); without
it the `/admin` specs SKIP explicitly rather than fail.

---

## Routing — split by route, and the three nets that make it safe

`app/routes.tsx` declares the route tree, where **everything except `/login` and the guards is
`lazy`**; `app/router.tsx` just calls `createBrowserRouter(routes)`. Motivation: a single chunk
used to ship on every first visit — superadmin console and wizard included — even for a coach
opening nothing but a public doléances page.

Eager on purpose: `LoginPage` (entry path), `AuthGuard`, `AdminGuard` (their code must be
present to decide).

Splitting is only safe because of three nets. **Removing any of them trades the gain for a
silent outage — do not drop them when adding a route:**

| Net | Without it |
|-----|-----------|
| `errorElement` (root + nested under `AppLayout`) | A 404 chunk (deploy mid-session) replaces the **whole app** with the router's unstyled English screen, invisible to Sentry. The nested one keeps header/nav/banners alive when a single page's chunk fails. |
| `HydrateFallback` | react-router renders `null` → **blank page** on any direct open or F5 of a lazy route. |
| Pending indicator (`useNavigation`, in `AppLayout`) | A navigation click gives **no feedback at all** until the chunk lands. |

Known, accepted trade-off (documented in the file): the data router resolves the `lazy` of
**all matched routes** before rendering any, so an anonymous visitor on `/planning`
downloads the page before being redirected to `/login`. That JS is public and carries no
data; avoiding it would mean duplicating the auth decision into a per-route `loader`.

### Routes

| Route | Auth | Notes |
|-------|------|-------|
| `/login` | public | The only eager page |
| `/register` · `/verify-email/:token` · `/forgot-password` · `/reset-password/:token` · `/waiting` | public | Register is 202 + email link; verify sets the auth **cookie** (SEC-16 — no token in the body) |
| `/confidentialite` | public | Privacy policy |
| **`/doleances/:token`** | **public, NO login** | #10 — flat route, deliberately **outside `AuthGuard`**. A coach fills in availability from a personal tokenised link. |
| `/admin/login` · `/admin` | SA0 session | Superadmin console behind `AdminGuard` → `AdminShell`. Separate identity — a club JWT never crosses this firewall. |
| `/` | required | **Cockpit** (temporal home), not the planning |
| `/planning` · `/matchs` · `/wizard` · `/club` · `/profile` | required | Under `AuthGuard` → `AppLayout` |
| `*` (authed) | required | **Renders the 404 screen** (`app/NotFoundPage`) inside `AppLayout` — header and nav kept. ⚠ It used to redirect silently to `/`: a stale link teleported the manager home with no explanation, and the 404 screen had nowhere to live. Same for `/admin/*`. |

---

## Data & state

- **Server state → TanStack Query 5. Client state → Zustand 5.** Never store a query result
  in Zustand; never fetch outside Query.
- **HTTP is `ky`** (`shared/api/client.ts`) — not axios, not raw fetch. `beforeRequest`
  injects the optional `X-Season-Id` — **no `Authorization` header any more**: the JWT is an
  httpOnly cookie set by the server (SEC-16, `docs/security/jwt-cookie.md`), so the client
  only carries `credentials`. `afterResponse` clears auth on a 401 (except on `/api/login`,
  where 401 means bad credentials) and self-heals a stale season on a `403` carrying
  `X-Season-Rejected`.
  ⚠ **There is no `beforeError` hook and there must not be one**: ky 2.x consumes the error
  body itself and exposes it as `error.data` before any consumer runs. Re-reading
  `error.response` throws *"body stream already read"* — every error reader must use
  `error.data`.
- **The superadmin console has its own client** (`features/admin/api.ts`): `adminApi`, prefix
  `/api/admin`, `credentials: "same-origin"`, and it **deliberately never touches the club auth
  store**. The two identities stay separate cookies on separate paths (`/api/admin` session vs
  the club `BEARER` cookie scoped to `/api`).
- **Collections are JSON-LD with the key `member`** (API Platform 4 — *no* `hydra:` prefix).
  `collection()` unwraps it; `collectionAll()` pages via `?page=N` and dedupes by `id`.
  There is **no `useInfiniteQuery`** anywhere.

### Toute mutation VOILE l'écran — l'exemption se déclare, elle ne se devine pas

Lot C (2026-08-21). `app/ActionVeil.tsx`, monté dans `Providers`, bloque l'écran pendant qu'une
action rend la main : `inert` natif (React 19) sur le contenu + overlay qui capte les clics. Le
voile n'est **visible qu'après 250 ms** (sinon il clignote à chaque clic de 90 ms). ⚠ **Le MOMENT
du blocage dépend du contexte** — 0 ms pour `enregistrement`/`long`, 250 ms pour `changement de
page` : voir la puce dédiée.

- **Le régime est GLOBAL par défaut.** Tu n'as rien à câbler en écrivant une nouvelle mutation :
  elle voile. C'est le contraire qui se déclare — `meta: { veil: false }` — et une exemption sans
  raison écrite est une régression déguisée.
- **Trois contextes**, priorité *long > enregistrement > page* : `meta: { veil: "long" }` pour le
  rail de retouche (le verdict moteur, > 30 s mesuré sur un club dense) ; « changement de page »
  pour le premier chargement d'une requête **sans données en cache** — mais **UNIQUEMENT s'il suit
  une TRANSITION déclenchée par le gestionnaire** (changement d'étape du wizard, de vue/version du
  planning ; déclencheur commun `shared/stores/navTransitionStore`) ; tout
  le reste est « enregistrement ». ⚠ Un **refetch d'arrière-plan ne voile jamais** (le prédicat est
  `undefined === q.state.data`, la même notion que `readState`) — **et le simple montage d'un écran
  non plus** (correction 2026-08-21, GO fondateur). Pourquoi : le blocage à 0 ms sert à manger le
  2ᵉ clic d'un geste **déjà parti** ; une arrivée n'a rien lancé, il n'y a aucune double-soumission
  à empêcher — et comme le voile est invisible sous 250 ms, geler un formulaire déjà peint mange
  les frappes **sans le moindre retour visuel** (l'utilisateur croit son clavier mort). L'ancienne
  règle voilait tout premier chargement : elle gelait le formulaire de l'étape 1 du wizard à
  l'arrivée (`journey.spec.ts`, `veil-double-click.spec.ts`).
- **Le MOMENT du blocage n'est PAS le même selon le contexte** (asymétrie voulue, GO fondateur
  2026-08-21 — dérivé `blocking` dans `ActionVeil`) : `enregistrement` et `traitement long` bloquent
  **dès 0 ms** (ils protègent un geste RÉELLEMENT parti, dont le blocage immédiat mange le 2ᵉ clic) ;
  `changement de page` ne bloque qu'**à 250 ms**, quand le voile devient VISIBLE. Pourquoi : sur un
  CHARGEMENT rien n'est parti, aucune double-soumission à empêcher — rien à protéger avant que
  l'utilisateur ne VOIE pourquoi il est bloqué ; bloquer à 0 ms (voile encore invisible) mangerait
  des frappes **en silence**, à l'arrivée comme sur une transition. Effet : transition < 250 ms → la
  saisie rentre, aucun `inert` ; transition lente → l'écran se fige ET le montre. ⚠ Ne jamais fondre
  les deux régimes en un seul : le NR `ActionVeil.test` garde l'asymétrie (`enregistrement` inert dès
  0 ms, `page` pas avant 250 ms).
- ⚠ **L'armement appartient au GESTE, pas à l'action de store.** `armNavTransition()` est appelé
  dans les **handlers de clic** de la navigation (`WizardLayout` : rail d'étapes, Suivant/Précédent ;
  `PlanningPage` : bascule de vue, sélecteur de version, clic sur un diagnostic), JAMAIS dans les
  actions du store (`wizard/store`, `planning/store` sont NEUTRES). Raison : ces actions servent
  aussi au guidage AUTOMATIQUE — `WizardLayout` appelle `jumpTo` tout seul au montage (deep-link,
  repli sur le premier trou, recap), et `PlanningPage` appelle `setSelectedScheduleId`
  programmatiquement (atterrir sur la version en vigueur, onSuccess d'un solve). Armer dans le store
  gelait l'écran à l'ARRIVÉE — le bug d'origine revenu par la porte de service, invisible en local
  (course de quelques ms) et rouge en CI. Gardé par le NR `WizardPage.test` (jumpTo du store n'arme
  pas ; clic Suivant arme).
- **Les seules exemptions légitimes à ce jour** : les 4 mutations de lancement de solve (elles
  rendent 202 et passent la main à `GenerationWaiting` — les voiler ferait clignoter voile → écran
  d'attente), la query `useScheduleStatus` (son premier fetch vit sous cet écran), et
  `useMarkReleaseNotesSeen` — la première d'une **seconde famille** : la mutation d'ENTRETIEN qui
  part **toute seule**, sans geste (le filigrane des nouveautés se pose en silence pour un nouvel
  inscrit, ~1,5 s après l'arrivée sur le wizard). Le blocage à 0 ms protège un geste parti d'un
  clic en mangeant le 2ᵉ clic ; ici AUCUN clic n'est parti, et le gel avalait les frappes du
  gestionnaire en train de taper sa première équipe — flake e2e sur QUATRE PR (#684/#687/#689/#694)
  avant que le trace Playwright la nomme (2026-08-22, NR : `WhatsNewModal.test`, voile monté en
  vrai). Le critère de la famille : si la mutation peut partir SANS interaction, elle s'exempte.
  ⚠ **`useRegenerateFromVersion` n'en fait PAS partie** et ne doit pas y entrer : `/regenerate-from`
  ne lance **aucun solve** (`RegenerateFromVersionController.php:102-104`, 200 synchrone), c'est un
  restore **destructif** — le voile est exactement la protection anti-double-clic qu'il réclame.
- **Deux régimes de sortie.** Contextes courts : au-delà de 10 s on prévient **et on relâche** (une
  panne réseau ne doit pas rendre l'app inutilisable jusqu'au F5). Contexte long : **jamais de
  relâche au chrono** — relâcher autoriserait un second déplacement par-dessus le premier ; la
  sortie est le bouton **« Abandonner ce déplacement »**, qui `abort()` la requête. L'abandon
  volontaire se distingue par `VerdictAbandonedError extends EngineVerificationInterruptedError` —
  la classe mère garde ses consommateurs justes par héritage.
- ⚠ **Une mutation en PAUSE n'est PAS un geste en vol** (2026-08-22). Hors ligne, TanStack ne
  démarre pas la mutation : elle part `isPaused: true` et attend le réseau (`networkMode` par
  défaut `"online"`). Le prédicat `saving` du voile l'**exclut** — sinon l'écran se bloquait à
  0 ms puis annonçait à 10 s que « l'action continue en arrière-plan », alors qu'elle est
  simplement garée. ⚠ Le contexte **`long` ne l'exclut pas** : un déplacement sous verdict garé
  pourrait repartir plus tard, il doit rester sous le régime bouton-Abandonner plutôt que d'être
  relâché en silence. Le compteur du bandeau hors-ligne lit ces mêmes mutations en pause, donc le
  chiffre affiché est toujours RÉEL.
- ⚠ **L'état réseau a UNE source : `shared/lib/online.ts`** (`useOnline`, adossé à l'`onlineManager`
  de TanStack — celui-là même qui décide de la pause). Ne relis jamais `navigator.onLine` en
  parallèle : le bandeau et la file de mutations pourraient se contredire. L'`onlineManager` naît
  **optimiste**, d'où le seed depuis `navigator.onLine` dans `main.tsx` avant le render.
- **Rôles ARIA : deux régimes, c'est voulu.** Sans bouton → `role="status"` + `aria-live="polite"`.
  Avec le bouton d'abandon, le voile **est** un dialogue → `role="dialog"` + `aria-modal`. Jamais
  `alertdialog` : rien d'urgent, et il volerait le focus. Seule la phrase **stable** vit dans la
  région live, la rotation est `aria-hidden` (AUD-FRT-23/24).

### Taille de texte : plancher 12 px, sauf dans les grilles

Le corps de texte descend à `text-xs` (0,75 rem = **12 px**) et pas en dessous — pas
d'échelle arbitraire en `text-[10px]`. **Exception assumée : les GRILLES**
(`WeekGrid`, `WeekendGrid`, `TypicalWeekendGrid`, `ReservationGrid`, `MonthCalendar`,
`VenueAvailabilityGrid`, **`ClubViewTable`** — ajoutée le 2026-08-19, audit A11Y-16 : née
avec P3-20, elle suivait déjà la convention sans figurer dans la liste), où la densité est la fonction : y agrandir le texte impose
des lignes plus hautes, donc du défilement dans un écran fait pour tenir en un coup
d'œil. Décision fondateur du 2026-08-08, avec son pourquoi dans
`specs/courantes/etat-des-lieux.md` §2.

⚠ Ne pas confondre avec une exigence WCAG : **aucun plancher de taille n'existe** en
2.2 (1.4.4 demande le zoom 200 % sans perte, pas une taille mini). C'est une barre de
qualité — donc un ajout en `text-[9px]` hors grille se discute, il ne se refuse pas
au nom d'une norme.

### ⚠ axe ne voit PAS un champ nommé par son seul `placeholder`

Mesuré le 2026-08-07 sur `<input placeholder="…" />` nu : axe-core rend `violations: []`
et classe `label` **et** `label-title-only` dans `passes` — HTML-AAM autorise `placeholder`
comme source de nom de dernier recours, donc axe a techniquement raison. Conséquence
pratique : `expect(await axe(container)).toHaveNoViolations()` **ne garantit pas** qu'un
champ a un nom utilisable (le placeholder disparaît à la première frappe, et l'AT n'annonce
plus que « zone de texte »). C'est ainsi qu'A11Y-10 a survécu à un test qui prétendait
couvrir l'écran.

Donc : pour un champ, assertion EXPLICITE du nom —
`screen.getByRole("textbox", { name: "…" })` — en plus de la passe axe, jamais à sa place.

### Generation status = SSE, polling as fallback (FRT-04)

`features/planning/lib/scheduleStream.ts` holds the ONE `EventSource` per session (ref-counted
singleton): auth via `GET /api/mercure/auth` (httpOnly cookie + `topicTemplate` — the front
never knows its clubId), subscription to the template itself, events invalidate the
react-query caches. `features/planning/queries.ts` and `features/wizard/queries.ts` keep
their poll but degrade it (2.5 s stream down → 15 s stream connected) — the publisher is
best-effort, so polling must never die. Details & security contract:
`docs/security/mercure.md` (root). `WaitingApprovalPage` still polls `/api/me` every 5 s.

### Wizard store = UI only

`features/wizard/store.ts` holds the current step, the furthest step reached, the mode
(`season` | `period`) and `calendarEntryId` — **nothing else**, persisted at `version: 4`.
There is **no draft blob and no `autoSave()`**: every team/venue/coach/constraint is
POST/PUT/DELETE'd immediately via TanStack mutations. "Suivant" only validates and navigates.

---

## Primitives that matter (`shared/components/ui/`)

Beyond the obvious (`button`, `input`, `select`, `card`, `menu`, `accordion`), these carry
product rules — reuse them instead of rolling your own:

- **`modal`** — its width is a **named palier** (`size`: sm/md/lg/xl), and there is deliberately
  **no `className` prop**: six callers had each patched their own `max-w-…` before P4-107's 3rd
  tranche. The scale and its ceilings live in `MODAL_WIDTH` — see `frontend/docs/frontend-spec.md`
  §6.9.
- **`fiche-page`** — the frame of a "fiche" screen (Club, Profil, Nouveautés): 832 px centred,
  with help paragraphs bounded to a readable line length. A new fiche uses it; it never rolls its
  own `mx-auto max-w-*` (same §6.9).
- **`delete-confirm`** — destructive confirmation that *announces its impacts* ("N réservations
  seront retirées"). Deleting without stating what it takes away is the bug it exists to prevent.
- **`load-error-hint`** — "the read failed, here is a retry". Pairs with `readState` below.
- **`team-select`** — every team picker in the app (constraints, coaches, matches, FBI import)
  goes through it: optgroups by rank, same order as the Teams step. Reranking a team updates
  the order **everywhere**.
- **`badge`** (`StatusPill`) — the **only** house for a coloured pastille (icon + text, border +
  tinted fill), variants `warning`/`accent`/`neutral` (P4-173, `accent` added P4-177). Both tinted
  variants keep their text `text-foreground`, never `text-warning`/`text-accent` (measured: both
  drop below AA — 4.5:1 — on their own `/10` tint), the icon alone carries the tone colour
  (graphic element, WCAG 1.4.11 ≥ 3:1) — pairs locked in `tests/e2e/a11y-contrast.spec.ts` for both
  themes. Wraps, never truncates (`whitespace-normal`); passes through `title`/`aria-label` for a
  caller whose announcement is richer than the visible text (e.g. `CreditBadge`). First consumer:
  `features/cockpit/StalenessPill.tsx` (P4-173). All five pastilles that predated it have migrated
  (P4-177): `CreditBadge`, `CompromiseList`, `MatchesPage`'s `offModelBadge`/`sameWeekendBadge`,
  and `SourceBadge` — now a single shared component (`features/matches/SourceBadge.tsx`) consumed
  by both `TravelMatrixModal` and `OpponentTravelCard`, which each used to carry their own copy.
  Seven more migrated (P4-178): `CoachesStep` ("Salarié" + preferred cap), `VenueGeocodeField`
  ("Recommandé"), `ImplicitRulesPanel`'s `TravelRuleNotice` ("Actif"), `CampaignDialog` ("✓
  répondu le …", the ✓ became a `Check` icon; its two filter buttons keep their accent
  border/tint but their active-state text moved `text-accent` → `text-foreground`),
  `RadarCoachWishAction` (the responded-count pill), `ConflictRadar` ("Nouveau" chip, size
  preserved via `className`). ⚠ Three sites remain outside `StatusPill`, for a **different**
  reason (not the sub-AA defect): `features/matches/MatchSlotRotationsEditor.tsx` and
  `features/matches/EntryDeadlinesEditor.tsx` use `text-accent-foreground` on a plain accent
  fill (not `text-accent` on a tint), and `features/planning/WeekGrid.tsx`'s emphasised-cell
  border is not a pastille. A new pastille on a tinted surface goes through `badge.tsx`.
- **`step-rail`** — the left step rail (`<nav className="shrink-0 md:w-44">`), extracted from the
  wizard (RMM-2). Presentation **pure**: `done`/`locked` arrive **calculated** in the `steps`
  array (it knows nothing of validation gates, guided mode, business locks, or the nav veil);
  `onSelect` bubbles the click so the caller owns its effects. Accessible name follows WCAG 2.5.3
  (it **contains** the visible label; a done step appends "— étape terminée"). Imports `Check`/`Lock`
  itself, and deliberately **no `className` prop** (same rationale as `modal`).
  Second consumer since RMM-1 PR3: `features/matches` (`MatchesPage` + `lib/loopSteps.ts`) —
  its `steps`/`done` are **recomputed from scratch on every render** (no persisted progression,
  no locks), unlike the wizard's precomputed array. Don't assume the rail itself tracks anything.

### `shared/lib/readState.ts` — the anti-"credible emptiness" rule

react-query's flags are transient and reading them as settled truths is a whole bug family.
`readState()` collapses them into three states on a single criterion — *do we have data?*

- `loading` — nothing to show yet (first load);
- `failed` — the read failed **and** there is nothing cached: the only case where a screen may
  give way to an error;
- `ready` — we have data, even stale, even after a failed background refetch.

Two consequences to respect: `isError` on a **background** refetch must not destroy a working
screen, and `data ?? []` during a first load fabricates a **credible emptiness** ("no slots",
"no settings") that makes a manager re-enter data (duplicates) or validate a period they
believe empty.

---

## Gotchas

1. **Tooling is Dockerized** — do not invoke host Node/npm; use the Make targets.
2. **`tsc --noEmit` is a no-op here** — see the trap above. Always `tsc -b --force`.
3. **Accessibility is blocking, not advisory.** `eslint.config.js` re-severities the whole
   `jsx-a11y` recommended set to `error` via the single `A11Y_LEVEL` knob (WCAG 2.2 AA
   guardrail). Flip it to `warn` only to temporarily unblock a large refactor. There is also
   an a11y unit suite (`src/test/a11y.test.tsx`) and a Playwright contrast spec.
4. **Migration anti-patterns are ESLint-enforced**, not just documented — e.g. a
   `no-restricted-syntax` rule bans `ReactDOM.render`. See `docs/frontend-strategy.md` §3.
5. **The theme is applied before React's first paint** (`main.tsx`, `readPersistedThemeMode`).
   Without it the tree renders light, then an effect flips `.dark` — a flash of the wrong
   theme plus a `transition-colors` animation that leaves surfaces at sub-AA colours (A11Y-06).
   The pre-paint class and `useApplyTheme` share the same predicate and storage shape so they
   can never disagree.
6. **Sentry is errors-only** (`main.tsx`): no APM, no replay, `tracesSampleRate: 0` — the free
   tier quota is deliberately preserved. No DSN = init skipped, SDK inert.
   ⚠ **Switching it on takes TWO changes, not one** (P4-65): set `VITE_SENTRY_DSN` at build
   time **and** allow the DSN's ingest host in `connect-src` (`docker/frontend/csp.conf`,
   which allows no third party). The DSN alone initialises the SDK while the browser drops
   every send **silently**. `frontend/tooling/sentryCspGuard.ts` (called from `vite.config.ts`)
   now **fails the build** on that combination; it is inert while no DSN is set. INF-01.
7. **The club accent is per-club and AA-guarded.** `useApplyClubTheme` reads
   `accentColor`/`accentColorDark`/`accentPalette` from `/api/me` and drives `--accent` /
   `--accent-foreground`; an explicit dark accent is applied as-is in dark mode, otherwise a
   legible derivation of the light one is used.
8. **Engaged teams are read-only on two fields.** `Team.isEngaged` comes **from the server**
   (`TeamResource.isEngaged`) and is never recomputed client-side; `TeamsStep` greys out both
   **deletion** and **level change** for such a team — its matches are filed with the
   federation. Server-side guard: `EngagedTeamGuardTest`. This is a structuring axis
   (`CLAUDE.md` §7.1) — touching it requires a non-regression test.
9. **The planning pointer moves by validating, and by nothing else.** There is no "set as
   main" action (ADR-0002; locked by `PlanningToolbar.test.tsx`). Validating points the plan
   at a version and deletes its sibling versions; reopening un-points it.
10. **A period owns its venue grid.** In wizard period mode the Venues step is **editable**, not
    a read-only summary: the period's slots are a copy taken at plan birth and never unioned
    with the season's own. Same gestures as the season, barre « À poser » included (P4-43).
    See `docs/frontend-wizard.md`.
11. **Accent as TEXT needs a plain background.** `text-accent` clears 4.5:1 (WCAG 1.4.3) only
    on `bg-background`/`bg-card`: over `bg-accent/10` it drops to 4.18:1 in light mode, over
    `bg-muted` to 4.37:1 — even `accent/05` fails. Same story for `text-warning` on `bg-warning/10`
    (4.30:1). Tint the surface **or** colour the text, never both. **The recipe: `StatusPill`**
    (`shared/components/ui/badge.tsx`) — text stays `text-foreground`, the tone colour lives in the
    border, the tint, and the icon (a graphic element, WCAG 1.4.11 ≥ 3:1 only). A new pastille on a
    tinted surface goes through it rather than re-deriving the trade-off. The token pairs are
    locked by `tests/e2e/a11y-contrast.spec.ts`; add any new text token to its list rather than
    eyeballing the result.

---

## Quick reference

| Task | Command |
|------|---------|
| Dev server | `make -C frontend dev` |
| Lint + typecheck | `make -C frontend lint` |
| Tests (lint + Vitest) | `make -C frontend test` |
| Build prod image | `make -C frontend build` |
| Tooling shell | `cd frontend && make exec` |

**Pointers:** `README.md` (role, boundaries, delivered features) ·
`docs/frontend-spec.md` (routes, state, API contract) ·
`docs/frontend-wizard.md` (wizard & period mode) ·
`docs/constraint-emission.md` (what the wizard emits, 3-layer alignment) ·
`../specs/courantes/superadmin-auth.md` (`/admin`) ·
`../specs/courantes/types-de-planning.md` (doléances coachs, #10).
