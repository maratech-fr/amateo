# Mercure hub — access control

> Status: hardened 2026-07-03 (audit SEC-05/SEC-06). Protocol cutover to Mercure 1.0: 2026-10-16
> (lot 4 PR C).

The Mercure hub pushes schedule-generation status to clients on the topic
`/clubs/{clubId}/schedules/{scheduleId}`. It is reachable only on `127.0.0.1:${MERCURE_PORT}`
(never published on a public interface in dev).

## What changed

Before, the hub ran with `anonymous` (any client could subscribe to any topic
without a JWT), `cors_origins *` and `publish_origins *`, and signed its JWTs
with the **same** secret as the lexik auth passphrase (`${JWT_PASSPHRASE}`).

Now (`docker-compose.yml`, service `mercure`):

- **No `anonymous`** — a subscriber must present a valid subscriber JWT signed
  with `MERCURE_JWT_SECRET`. No token → 401.
- **`cors_origins`** limited to the dev frontends (`http://localhost:5173`,
  `http://localhost:8081`).
- **No `publish_origins *`** — browser-side publishing is not allowed; the
  backend publishes server-side (no `Origin` header) with a publisher JWT, which
  is unaffected by `publish_origins`.
- **Dedicated secret** `MERCURE_JWT_SECRET`, distinct from the lexik
  `JWT_PASSPHRASE` (SEC-06 — the two were the same value and `JWT_PASSPHRASE`
  was even defined twice in `backend/.env`).

## Secrets

| Var | Where | Role |
|-----|-------|------|
| `JWT_PASSPHRASE` | root `.env` (feeds php-fpm via `env_file`) + `backend/.env` | passphrase of the lexik RSA private key — **auth only** |
| `MERCURE_JWT_SECRET` | root `.env` (hub, via compose) + `backend/.env` (publisher) | HS256 secret signing Mercure publisher/subscriber JWTs |

Both hold dev placeholders. **Prod: replace `MERCURE_JWT_SECRET` with a random
32+ byte secret** and keep it in sync between the hub (compose) and the backend
publisher (`backend/config/packages/mercure.yaml` reads `%env(MERCURE_JWT_SECRET)%`).
Where the secret is generated and stored (the `ENV_PROD` GitHub secret, pushed
to the VM at deploy): `docs/ops/deploy.md` (§ Secret `ENV_PROD`).

## Prod (`docker-compose.prod.yml`)

The prod stack tightens the same four axes rather than restating them:

- **No published port at all.** The dev hub is bound to `127.0.0.1:${MERCURE_PORT}`;
  in prod the service declares no `ports:` — browsers reach it only through the
  frontend edge (`location /.well-known/mercure` in `docker/frontend/nginx.conf` — the single conf, dev and prod alike, P4-118).
- **Image pinned** to `dunglas/mercure:v1.1.2` — a routine `docker compose pull` must never
  swap the hub version under a running production.
- **`cors_origins ${PUBLIC_BASE_URL}` — that single origin**, not the dev
  `localhost:5173 / localhost:8081` allow-list.
- **Secrets declared `${MERCURE_JWT_SECRET:?}`**: the stack refuses to start if the
  variable is missing, so a dev placeholder cannot silently ride into prod through
  an incomplete `.env.prod`.
- **`MERCURE_TRUSTED_ISSUERS` required** (`${MERCURE_TRUSTED_ISSUERS:?set MERCURE_TRUSTED_ISSUERS
  in .env.prod}`, = `PUBLIC_BASE_URL`): the hub's RFC 9068 trusted issuer — see § Protocol 1.0
  below.

## Image pinned everywhere — dev = prod (cutover, lot 4 PR C, 2026-10-16)

Dev/CI (`docker-compose.yml`) and prod (`docker-compose.prod.yml`) pin the **same exact tag**,
`dunglas/mercure:v1.1.2` — never `:latest`, never a 0.x tag (guarded by
`MercureHardeningTest`). The stack cut over **directly** from 0.x (dev `v0.24.2`, prod `v0.19`) to
1.0, with no compatibility-mode interlude: hub, backend token issuer and frontend consumer moved
together in one PR. An unpinned `:latest` or a dev/prod version drift would silently break Mercure
publication end-to-end (no SSE stream ever opens, or dev stops proving what prod runs). **Rule for
any third-party image**: pin an exact tag in dev, CI and prod alike — never `:latest` anywhere in
this repo's compose files.

## Protocol 1.0 (lot 4 PR C, 2026-10-16)

Mercure 1.0 rebuilds authorization on OAuth 2.0 / RFC 9068 and switches topic matching from URI
Templates to URL Patterns. Three changes land together:

- **Topics moved from the URN form to a web path**: `club:{clubId}:schedule:{id}` →
  `/clubs/{clubId}/schedules/:id` (the hub's URLPattern matcher treats a colon-separated string as
  an opaque URI scheme, not a pattern — a literal URN topic never matches a URLPattern selector,
  verified against the real 1.x hub). `App\Mercure\MercureTopic` is the single place that builds
  every topic (`for()`, `forTravel()`, `forPlacement()`, `selectorForClub()`) — see its docblock for
  the "six scattered `sprintf`s vs. one selector" history this class exists to prevent.
- **Subscriber/publisher JWTs are RFC 9068 access tokens**: header `typ: at+jwt`, authorizations in
  the `authorization_details` claim (RFC 9396, a `Grant` per action) instead of the 0.x bare
  `mercure.subscribe`/`mercure.publish` claims, plus mandatory `iss`/`aud`/`sub`/`client_id` claims.
  The hub (1.x) answers 401 to a 0.x-shaped token.
  - `iss` = `MERCURE_TRUSTED_ISSUERS` — the **same env var, same value**, read by the hub
    (`MERCURE_TRUSTED_ISSUERS` directive) and by every token issuer (publisher config, subscriber
    controller). A mismatch is a 401.
  - `aud` = `MERCURE_PUBLIC_URL` — the hub pins its own audience via `resource_identifier` to its
    public URL; every publisher's `aud` must equal that same value. **Every backend service that
    publishes** (php-fpm synchronous export, `messenger-worker` for generation/travel progress,
    `cron-runner` for the placement bascule) must receive `MERCURE_PUBLIC_URL` aligned on the hub's
    `resource_identifier` — a service that doesn't gets a mismatched `aud` from the
    `backend/.env` fallback and loses its publications **silently** (guarded by
    `MercureHardeningTest::testEveryBackendServiceCarriesTheHubAudienceAsMercurePublicUrl`).
- **Subscription querystring**: `topic=` is gone. The URLPattern schedule selector subscribes via
  `match_urlpattern=`; the two fixed topics (travel, placement) subscribe via `match=` (exact).

### Subscriber cookie name follows environment (decision C1-A)

A `__Secure-` cookie prefix requires the `Secure` flag (browsers silently reject it otherwise), so
the subscriber cookie can only be named `__Secure-mercure_access_token` when the cookie actually
carries `Secure` — i.e. in prod HTTPS. In dev HTTP and tests (`JWT_COOKIE_SECURE=false`) it is named
`mercure_access_token`, with no prefix. Both the backend (`MercureAuthController`, switching on the
same `JWT_COOKIE_SECURE`-driven flag as the app JWT cookie) and the hub (`cookie_name` directive in
compose) must agree per environment — they do, pinned in `docker-compose.yml` (dev:
`mercure_access_token`) and `docker-compose.prod.yml` (prod: `__Secure-mercure_access_token`). This
variation disappears once dev also runs HTTPS (P4-344, roadmap).

### Dependabot

`.github/dependabot.yml` now ignores Mercure `>=2.0.0` only — 1.x patch/minor bumps flow through
normally; a 2.0 major would again be a protocol decision, not an auto-merge.

## Public URL

`MERCURE_PUBLIC_URL` (the browser-facing hub URL, and the RFC 9068 publisher `aud` — see above) is
set by compose on **every backend service that publishes** — `php-fpm`, `messenger-worker`,
`cron-runner` — to `http://localhost:${MERCURE_PORT}/.well-known/mercure` in dev, so it always
matches the port the hub is actually published on. The static value in `backend/.env` is only a
fallback for non-Docker runs.

## Frontend consumption (delivered — FRT-04, 2026-08-07)

The frontend subscribes to the hub for generation progress; polling survives
only as a **fallback** (the publisher is best-effort — a missed event self-heals
on the next poll).

- **Subscriber JWT**: minted by `GET /api/mercure/auth`
  (`backend/src/Controller/MercureAuthController.php`) — RFC 9068 access token (`typ: at+jwt`),
  HS256, **same `MERCURE_JWT_SECRET` as the publisher**, `authorization_details` carries a
  `subscribe` grant bounded to the single URLPattern selector `/clubs/{clubId}/schedules/:id`
  where `clubId` is the **authenticated member's resolved tenant** (`_club_id` request attribute —
  never a client parameter) and `:id` is the only variable group. No wildcard, no other club.
  `iss`/`aud`/`sub`/`client_id` claims — see § Protocol 1.0. TTL 1 h, computed on the **real
  clock** (`#[Autowire(service: 'app.clock.real')]`, `MercureAuthController.php` —
  BCK-34: a demo club's simulated date must never shorten or extend this
  security TTL).
- **Delivery**: cookie, httpOnly, SameSite strict, **named per environment** (§ Protocol 1.0 —
  `__Secure-mercure_access_token` in prod HTTPS, `mercure_access_token` in dev HTTP/tests),
  `Secure` from `JWT_COOKIE_SECURE` (the same switch as the app JWT cookie —
  never `$request->isSecure()`, which answers false behind the prod nginx:
  `docs/security/jwt-cookie.md`), **path `/.well-known/mercure`** — the JS never sees the hub token (the
  app JWT in localStorage is already the weak point; no second exposed token),
  and the browser only sends it to the hub, same-origin via the vite/nginx
  proxies. Guarded by `backend/tests/Api/MercureAuthTest.php` (phase1 — the
  claim's club scope is a tenant boundary).
- **Client**: `frontend/src/features/planning/lib/scheduleStream.ts` — ONE ref-counted
  `EventSource` per session, subscribed to the **template itself as a URLPattern selector**
  (the response's `topicTemplate`, via `?match_urlpattern=`; the hub matches every exact
  `/clubs/X/schedules/<uuid>` topic against it, so all the club's generations
  arrive on one connection without knowing their ids). Events **invalidate**
  the react-query caches (the server stays the source of truth); the poll
  degrades from 2.5 s to a 15 s fallback while the stream is connected. On
  stream error the client closes and **re-authenticates on retry** — never the
  native EventSource reconnection, which would replay an expired cookie forever.

`anonymous` stays off; nothing here relaxes the hub configuration above.

## Second topic: async travel-time computation (C6, 2026-09-19)

The opponent-travel and venue-matrix computations (IGN routing, paced ~1 req/s) run in the
Messenger worker (`ComputeTravelTimesHandler`), never on the HTTP request — the paced burst would
blow the 60 s upstream ceiling. Progress is pushed on a **second, FIXED topic per club** (no `{id}`
wildcard: at most one travel computation in flight per club, guarded by `TravelComputeLock` —
`backend/docs/geo-api.md` § travel cache & async computation):

- **Topic**: `/clubs/{clubId}/travel` (`App\Mercure\MercureTopic::forTravel`), matched EXACT
  (`match=`, no URLPattern group). Same publish/subscribe
  split as the schedule topic — the backend publishes, the browser subscribes.
- **Auth**: the **same** `GET /api/mercure/auth` call mints ONE JWT whose `authorization_details`
  grant carries **both** topics — the schedule URLPattern selector
  (`/clubs/{clubId}/schedules/:id`) and the travel topic
  (`/clubs/{clubId}/travel`) — and the response body gains an additive `travelTopic` field alongside
  `topicTemplate` (`MercureAuthController.php`). No second auth round-trip, no second cookie.
- **Client**: `frontend/src/shared/lib/travelStream.ts` — deliberately in `shared/`, not
  `features/planning/`, because **two** features consume it (matchs' `OpponentsPage`/Conflicts
  radar and the wizard) — this is the one exception to the "SSE consumer
  lives in the feature that uses it" placement decided for `scheduleStream.ts` (`P4-123`,
  `specs/courantes/etat-des-lieux.md` §2). Same ref-counted `EventSource` singleton pattern; on
  message, invalidates react-query caches (opponents, conflicts, venue-travel-times) debounced
  500 ms — the GET response stays the source of truth (`travelStatus` per row), Mercure is only a
  refetch trigger. Within the wizard, **two** screens hold a reference: `TravelMatrixModal`
  (progress gauge + verdict) and, since P4-279 (2026-10-16), `VenuesStep` — the venue-editing
  screen toasts "Trajets recalculés." on a terminal `VENUE_MATRIX` event while it is mounted
  (deliberate decision: no global listener in `AppLayout`; `VenuesStep` stays silent when
  `TravelMatrixModal` is open to avoid a double signal).
- **Payload**: `{scope: "OPPONENTS"|"VENUE_MATRIX", done, total, terminal, verdict?}`, pushed every
  5 items plus a terminal event (even on failure — best-effort, so the front never waits forever).

### ⚠ The club id in that selector must be a CANONICAL uuid (security review, fixed 2026-08-07)

The `subscribe` selector was (0.x) parsed by the hub as an **RFC 6570 URI template**:
anything shaped `{name}` became a *variable*, not a literal. A club id in the
non-canonical form PostgreSQL happily accepts — `{710a290720ce40ffa67fc2674ca0dbe7}`,
braces, no hyphens — was a valid template varname, so the selector
`club:{710a29…}:schedule:{id}` carried **two** variables and matched
**every club's** topics. Measured live on the dev stack before the fix: a member
of club A received club B's generation events.

The guard stayed load-bearing across the Mercure 1.0 cutover (lot 4 PR C): `{` and `}` are ALSO
syntax in a URLPattern (a non-capturing group), so a club id carrying stray braces would be just
as dangerous interpolated into `/clubs/{710a29…}/schedules/:id` — the canonical-uuid check in
`MercureAuthController` guards the path form exactly the same way, regardless of topic syntax.

The entry point used to be `X-Club-Id` (the membership check compared in
Postgres, which normalises, so a member could pass their own club in the
degraded form and have that raw string land in `_club_id`); the header has
since been removed server-side entirely (AUD-SEC-25) — the club now comes
solely from the JWT membership, so this specific entry point is gone. The
**guard stays active regardless of the source**: `TenantFilterListener`
validates the club's **shape** exactly like the season's
(`backend/src/EventListener/TenantFilterListener.php`, `isUuid` guard before
`_club_id` is set, 403 otherwise: refused, never silently normalised) — plus a
defence-in-depth re-check in `MercureAuthController` before signing. Guarded by
`MercureAuthTest::testANonCanonicalClubIdNeverReachesTheSelector`.
**Rule for any future selector**: never interpolate a client-influenced string
into a topic selector without canonical validation.

## Third topic: async match placement (ADR-0003 amendment, 2026-10-04)

`POST /api/fixtures/place` moved from a synchronous rail to the same async pattern as generation
(controller → Messenger → `PlaceMatchesHandler`, transport `async` shared with generation). The
worker solves (the engine now splits the solve ISO-week by ISO-week, ENG-50) and publishes
**one terminal event only** (`{runId, status}`, COMPLETED or FAILED) on a **third, FIXED topic per
club** (no `{id}` wildcard: `MatchPlacementLock` already guarantees at most one placement run in
flight per club):

- **Topic**: `/clubs/{clubId}/placement` (`App\Mercure\MercureTopic::forPlacement`), matched EXACT
  (`match=`). Same publish/subscribe split as the other two topics — the backend publishes, the
  browser subscribes.
- **Auth**: the same `GET /api/mercure/auth` call mints one JWT whose `authorization_details`
  grant now carries **three** topics (schedule template, travel, placement); the response body
  gains an additive `placementTopic` field alongside `topicTemplate`/`travelTopic`
  (`MercureAuthController.php`). No third auth round-trip, no third cookie.
- **Client**: `frontend/src/features/matches/lib/placementStream.ts` — same ref-counted
  `EventSource` singleton pattern as `scheduleStream.ts`/`travelStream.ts`. On any message it
  invalidates the `fixtures`/`placement-run` react-query caches; `GET /api/fixtures/placement-run`
  stays the source of truth, re-read behind the invalidation. Mercure stays best-effort: the screen
  also re-reads that GET on tab focus / reconnect, never a permanent poll.
- **Visibility**: the run's state ("in progress" banner) is visible to **every** club member (a
  second tab, a second member); the **gesture** (the "Placer automatiquement" button) stays
  manager-only.

`anonymous` stays off; nothing here relaxes the hub configuration above.

## NR: real delivery through the real hub (`MercureEndToEndDeliveryTest`, blocking)

Every other test above checks the **shape** of a token or a config file — a static guard, cheap but
blind to whether the hub actually delivers.
`backend/tests/Integration/Mercure/MercureEndToEndDeliveryTest.php` is the one test that boots the real hub (docker-compose service)
and proves, protocol 1.0 end to end: a private update published on each of the three per-club
topics is actually delivered to an authenticated subscriber of that club (token minted by the real
`/api/mercure/auth` route, subscribed via `match`/`match_urlpattern`), and **never** to a subscriber
of a different club — the tenant boundary proven **at the hub**, not just in the shape of the
claim. Listed as a required `ci.yml` step and in `docs/testing/blocking-tests.md`: a desynchronised
1.0 cutover (hub vs. token issuer vs. frontend consumer) would otherwise break real-time delivery
in silence, invisible to every config-shape test.
