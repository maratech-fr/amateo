# Amateo — Frontend Agent Context

> React 19 · Vite 8 · TypeScript ~6.0 · Tailwind 4. The web UI of the club-scheduling
> platform. Rebuilt from scratch and **active** — every path below exists in `src/`.
>
> Canonical detail lives in `README.md` (role & boundaries) and
> `docs/frontend-spec.md` (routes, state, API contract). This file is the
> agent cheat-sheet: what breaks, what is a trap, what is non-negotiable.

---

> ⚑ The traps that make a test pass **falsely** (tooling image, cooked `dist`, `tsc --noEmit`,
> jsdom without a layout engine) have a single home:
> [`.claude/rules/frontend.md`](../.claude/rules/frontend.md), auto-loaded whenever a
> `frontend/` file is touched.

## Boundaries (never cross)

Full list: [`README.md`](README.md) § Frontières + [`../CLAUDE.md`](../CLAUDE.md) §2 (backend-only
via `/api/*`, never the engine directly, no `X-Club-Id` header, `snake_case` URIs). Two additions
that live only here: there is deliberately **no `/engine` proxy** in `vite.config.ts` (FRT-17 —
the old one exposed the solver unauthenticated) ; always **relative URLs** — never hardcode a
host (`prefix: "/api"` uses the Vite proxy in dev, Nginx in prod).

---

## Layout

Full tree, entry points, key mechanisms: [`frontend-spec.md`](docs/frontend-spec.md) §10. Two
facts absent from that spec, kept here: `app/routes.tsx` holds the `RouteObject[]` tree as a
**non-component export**, moved out of `router.tsx` on purpose (FRT-29) ; `features/matches/api`
is the **one barrel** in the codebase — `api/index.ts` re-exporting 8 per-domain modules
(opponents/fixtures/conflicts/venues/teams/competitions/fbi/ffbb, FRT-33) — its public import
path (`./api`) is unchanged.

---

## Commands

Command recap: [`README.md`](README.md) § Commandes principales + [`../CLAUDE.md`](../CLAUDE.md)
§3. All tooling runs in Docker — the host needs only Docker, Docker Compose and Make.

---

## Routing

Route tree, the three safety nets (`errorElement`/`HydrateFallback`/pending indicator) and the
full routes table: [`frontend-spec.md`](docs/frontend-spec.md) §2. ⚠ Dropping any of the three
nets when adding a route trades the code-split gain for a silent outage — read what breaks
without each one at the pointer above before touching `routes.tsx`.

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

Full mechanics (topic, auth via `GET /api/mercure/auth`, cache invalidation, polling fallback):
[`frontend-spec.md`](docs/frontend-spec.md) §5.

**Second stream, `shared/lib/travelStream.ts` (C6, 2026-09-19) — the ONE exception to "an SSE
consumer lives in the feature that uses it" (`P4-123`, `specs/courantes/etat-des-lieux.md` §2
closed decision).** Async travel-time computation (opponents + venue matrix) is consumed by
**two** features — matchs' `OpponentsPage` and the wizard's `TravelMatrixModal` — so the stream
sits in `shared/` instead of `features/planning/lib/` where `scheduleStream.ts` was deliberately
pulled DOWN to. Same ref-counted `EventSource` singleton pattern, same `GET /api/mercure/auth`
call (now returns an additive `travelTopic` alongside `topicTemplate`), subscribed to the fixed
topic `club:{clubId}:travel`. Debounced (500 ms) invalidation of opponents/conflicts/venue-travel
caches on message — the GET response (`travelStatus` per row) stays the truth.

### Wizard store = UI only

`features/wizard/store.ts` holds the current step, the furthest step reached, the mode
(`season` | `period`) and `calendarEntryId` — **nothing else**, persisted at `version: 4`.
There is **no draft blob and no `autoSave()`**: every team/venue/coach/constraint is
POST/PUT/DELETE'd immediately via TanStack mutations. "Suivant" only validates and navigates.

---

## Primitives that matter

Full catalogue — one entry per primitive, verified against `shared/components/ui/`:
[`frontend-components.md`](docs/frontend-components.md) §3, **the single home** for shared UI
primitives (Button, Listbox, TeamSelect/VenueSelect, Menu, FilterToggle, EmptyState family,
Modal, FichePage, WarningPanel, ConfirmDialog, DeleteConfirm, LoadErrorHint, StatusPill/
SourceBadge, StepRail, Table, AccordionSection, AddressGeocodeField, OpponentLogo, Onglets,
Palette console, BrandIcon, BrandMark).

A few pure prohibitions worth keeping in agent context (detail at the pointer above):
- Never a `className` prop on `Modal` or `StepRail` — several callers had each patched their
  own width/layout before the primitives closed that door; a new need changes the primitive,
  never a local override.
- Never drop `Modal`'s scrollable-content gutter (`p-1 -m-1`) — an `overflow-y-auto` ancestor
  clips the `focus-visible:ring-2` of a field at the very bottom without it (`e60fbb1f`).
- A new rich single-select picker (colour/icon/count/sub/disabled-with-reason) goes through
  `Listbox` — never a hand-rolled dropdown or a native `<select>` pressed past its limits.

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

**UXS-08 (2026-09-18) — the gating GRAIN depends on the screen's shape.** A monolithic screen
(one view, no independent sections — `TypicalWeekPage`, six reads) gates the **whole page** on
`readFailed`/`undefined === data` across all its queries: `FullPageSpinner` while loading,
`LoadErrorHint` with a grouped retry on failure, never a per-query patchwork. A sectioned screen
(`ConfigurationPage`, five independent `AccordionSection`s) instead gates the **page** only on
its *founding* reads (the ones several sections share — here teams + venues) and lets **each
section's own body** (`SectionBody`, a small `readFailed`/`undefined` wrapper around
`LoadErrorHint`/`EmptyHint`) gate on its own query: one section's failed read must not blank the
others. Either way, `?? []` during a first load is banned — it is exactly the credible-emptiness
bug above, one screen removed. **A single WIDGET can be the "section"** too, not just an
accordion: `PlacementPanel` (D2, `features/matches`) derives its own three-query `guards` state
(`usePlacementGuards`, `features/matches/lib/`, called from `CalendarPage` — FRT-33 lot 7 PR C)
and suspends only its own placement gesture (`LoadErrorHint` + retry on `failed`, `Spinner` on
`loading`) — the rest of the Calendrier screen (filters, other gestures, the radar) stays live
regardless.

### A "done" gesture, undoable for the life of the modal — no toast

Pattern born with the "FBI — à faire" screen (`features/matches/FbiEntryList.tsx`, todo-FBI lot,
2026-09-19). Ticking "Corrigé dans FBI" or "saisi" fires the mutation immediately (server call,
same as any other action) **and** greys the row + offers "Annuler" for as long as the modal stays
open — a plain `Set`/`Map` of ids kept in **local component state**, reset on unmount. It is
presentation only (which rows read as done, which show the undo button): the server call already
happened, there is no queued/delayed mutation and no toast-with-action. Closing the modal *is* the
confirmation, not a timer. Reach for this instead of a toast-undo when the "session" the undo must
survive is naturally bounded by a modal's lifetime.

---

## Gotchas

1. **Tooling is Dockerized** — do not invoke host Node/npm; use the Make targets.
2. **`tsc --noEmit` is a no-op here** — the trap and the fix live in
   [`.claude/rules/frontend.md`](../.claude/rules/frontend.md). Always `tsc -b --force`.
3. **Accessibility is blocking, not advisory.** `eslint.config.js` re-severities the whole
   `jsx-a11y` recommended set to `error` via the single `A11Y_LEVEL` knob (WCAG 2.2 AA
   guardrail). Flip it to `warn` only to temporarily unblock a large refactor. There is also
   an a11y unit suite (`src/test/a11y.test.tsx`) and a Playwright contrast spec.
4. **Migration anti-patterns are ESLint-enforced, not just documented** — table + detection
   mechanism: `docs/frontend-strategy.md` §3.
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
7. **The club accent is per-club and AA-guarded IN BOTH MODES, by contrast, not by a fixed
   formula.** `useApplyClubTheme` (`shared/hooks/useApplyClubTheme.ts`) picks a per-mode base
   (dark mode prefers `accentColorDark`, light mode `accentColor`, each falling back to the
   other, and a club with **neither** falling back to `PRODUCT_ACCENT` — `shared/lib/product.ts`,
   the teal signature, DA "base chaude + accent produit", 2026-09-25; see
   `specs/courantes/identite-visuelle-produit.md`) and **always** runs it through
   `accentForMode(hex, mode)` (`shared/lib/color.ts`,
   A11Y-22 decision 5) — never a raw bypass. `accentForMode` mixes the colour toward black
   (light) / white (dark) by ~4% steps until it clears **4.5:1 on BOTH `--background` AND
   `--card`** of that mode (not just the darker one) — a colour already conforming is returned
   unchanged. A bright hue like `#F58231` becomes `#b15e23` in light mode, `#FFD21E` becomes
   `#8a720f`. Swatches/logos keep the raw club colour — only text/accent CSS vars go through
   the derivation. There is deliberately **no `--accent-fill` token** for a club that finds its
   derived accent too dark on filled buttons (closed decision — a bright yellow as TEXT on white
   has no AA-legible form; see `specs/courantes/etat-des-lieux.md` §2). The same hook also sets
   **`--accent-hover`** next to `--accent`/`--accent-foreground`, via `accentHoverForMode(accent,
   mode)` (`shared/lib/color.ts`) — a filled `bg-accent` button changes SHADE on hover (darkened in
   light mode, lightened in dark, at least one step, until AA with the SAME resting foreground),
   never `hover:opacity-90` (which composited the opaque accent toward the surface and dropped
   white-on-accent from 4.85 to 4.26 in light mode — the pressed "Amical" chip on `/matchs`,
   2026-09-18). Consumed by `button.tsx`'s `default` variant, `system-screen.tsx`,
   `RouteErrorBoundary.tsx`. `destructive` (`bg-destructive`) and `ClubPage`'s avatar (`bg-muted`)
   still hover via `opacity-90`, deliberately out of scope — ex-P4-244, closed without a fix at
   the 2026-09-25 roadmap triage (never measured under AA in practice).
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
11. **Accent as TEXT needs a plain background — and never an OPACITY either.** `text-accent`
    clears 4.5:1 (WCAG 1.4.3) only on `bg-background`/`bg-card`: over `bg-accent/10` it drops to
    4.18:1 in light mode, over `bg-muted` to 4.37:1 — even `accent/05` fails. Same story for
    `text-warning` on `bg-warning/10` (4.30:1) and, on a `color-mix(…, var(--card))`-tinted cell,
    for `text-accent`/`text-muted-foreground` (`ReservationGrid`, P4-180, 3.19–4.10:1). Tint the
    surface **or** colour the text, never both. **The recipe: `StatusPill`**
    (`shared/components/ui/badge.tsx`) — text stays `text-foreground`, the tone colour lives in the
    border, the tint, and the icon (a graphic element, WCAG 1.4.11 ≥ 3:1 only); `ReservationGrid`
    now follows the same recipe for its counter/label text without going through the component
    itself (same surface, not a pastille). A new pastille or tinted-cell text goes through this
    trade-off rather than re-deriving it. **Corollary: opacity on TEXT is never a legitimate way to
    dim it** — `text-muted-foreground/50` on the calendar's out-of-month days fell to 2.1:1/2.5:1
    (`MonthCalendar.tsx`, P4-179); the fix uses the plain token and carries the distinction on
    `border-transparent` instead. The shared `--destructive-foreground` token (mirror of
    `--accent-foreground`, `frontend/src/index.css`) exists for the same reason on the `destructive`
    button variant: a literal `text-white` cleared AA in light but not in dark (4.02:1) — a token
    that flips per theme is the fix, never a hard-coded colour utility on a shared primitive. The
    token pairs are locked by `tests/e2e/a11y-contrast.spec.ts`; add any new text token to its list
    rather than eyeballing the result. **The one sanctioned exception is a token dark enough for
    its own tints**: `text-destructive` as TEXT on `bg-destructive/10|15` (constraint badges, the
    coach-wish day picker, the calendar's "F" holiday badge, the reservation alert) stays red — P4-181
    darkened `--destructive` (light L 0.50, dark L 0.72) until every tint clears 4.5:1 on both
    background and card, two themes, and the six pairs are hard gates in the spec. Reach for that
    only when the tone has no icon to carry it (the "F" badge); otherwise `StatusPill`.
    **A11Y-22 (2026-09-18) generalised the corollary from "accent as text" to ALL text**: no
    `text-<token>/NN` opacity class and no `opacity-30|40|50|60` on a text-bearing element is a
    legitimate de-emphasis — hierarchy is carried by weight/size/border/fill, a dimmed cell by
    `grayscale` (not `opacity`). Every prior opacity-as-dimming site moved to a plain token
    (`text-muted-foreground` full, never `/50`) or to `grayscale`. Decision 6 (closed): **there is
    no third `--muted-foreground-soft` token** — a soft grey between `text-foreground` and
    `text-muted-foreground` lands at 4.3:1 on `bg-muted`, indistinguishable from the existing pair
    besides; a third hierarchy level is size/weight, not a new grey. Guarded two ways: the static
    `frontend/src/test/textOpacityGuard.test.ts` scans every `.tsx` under `src/` for the two
    patterns and fails on any hit that is not either auto-exempt (a `disabled` /
    `cursor-not-allowed` / `pointer-events-none` / `aria-disabled` marker on the same line — WCAG
    1.4.3 does allow de-emphasing an INACTIVE control) or in its **nominative, ≤5-entry**
    exemption list (decorative icon opacity, an inert exterior block, a transient dim/lens
    highlight, an inactive segment label) — a new occurrence outside those two escape hatches is a
    regression, not a config change; and `tests/e2e/a11y-contrast.spec.ts` locks the concrete
    token pairs measured this pass (`text-foreground` on `bg-warning/10` on a card, on the bright
    `#FFD21E22` venue tint, `text-muted-foreground` on `bg-muted` and on `bg-muted/40` over a
    card), in both themes, against the real rendered app.
    **The same recipe applies on the other side too**: `features/planning/WeekGrid.tsx`'s coach
    sub-label (`cell.secondaryLabel`, printed on a *venue-tinted* session card via `tint()`) used
    `text-muted-foreground` — a plain token, no opacity — which still failed AA on a bright venue
    tint in dark mode (4.24–4.33:1). Fixed to `text-foreground`, de-emphasised by SIZE alone
    (`text-[10px]`) — the exact recipe `WeekendGrid`'s cell already used (2026-09-18).
12. **`coverageFloor.test.ts` anchors on `__dirname`, not `import.meta.url`** — under
    `vitest run --coverage` the latter is not always `file:`-scheme, and `new URL(...,
    import.meta.url)` throws `ERR_INVALID_URL_SCHEME`.
13. **`make -C frontend e2e` carries the superadmin preflight** (compose profile `tools`, service
    `e2e` — it needs the stack **and** `make -C frontend dev` running): it seeds the account and
    exports its TOTP secret. Without it the `/admin` specs **SKIP** explicitly rather than fail.

---

**Pointers:** `README.md` (role, boundaries, delivered features) ·
`docs/frontend-spec.md` (routes, state, API contract) ·
`docs/frontend-wizard.md` (wizard & period mode) ·
`docs/frontend-components.md` (shared UI primitives, §3) ·
`docs/constraint-emission.md` (what the wizard emits, 3-layer alignment) ·
`../specs/courantes/superadmin-auth.md` (`/admin`) ·
`../specs/courantes/types-de-planning.md` (doléances coachs, #10).
