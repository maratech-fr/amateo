# Mercure hub — access control

> Status: hardened 2026-07-03 (audit SEC-05/SEC-06).

The Mercure hub pushes schedule-generation status to clients on the topic
`club:{clubId}:schedule:{scheduleId}`. It is reachable only on `127.0.0.1:${MERCURE_PORT}`
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
Where the secret is generated and stored (encrypted `.env.prod.gpg` rail,
pushed to the VM at deploy): `docs/ops/deploy.md` (§ Secrets chiffrés).

## Prod (`docker-compose.prod.yml`)

The prod stack tightens the same four axes rather than restating them:

- **No published port at all.** The dev hub is bound to `127.0.0.1:${MERCURE_PORT}`;
  in prod the service declares no `ports:` — browsers reach it only through the
  frontend edge (`location /.well-known/mercure` in `docker/frontend/nginx.conf` — the single conf, dev and prod alike since P4-118).
- **Image pinned** to `dunglas/mercure:v0.19` — a routine `docker compose pull` must never
  swap the hub version under a running production.
- **`cors_origins ${PUBLIC_BASE_URL}` — that single origin**, not the dev
  `localhost:5173 / localhost:8081` allow-list.
- **Secrets declared `${MERCURE_JWT_SECRET:?}`**: the stack refuses to start if the
  variable is missing, so a dev placeholder cannot silently ride into prod through
  an incomplete `.env.prod`.

## Image pinned everywhere, not just prod (2026-09-17)

Dev and CI (`docker-compose.yml`) also pin an exact tag — `dunglas/mercure:v0.24.2` — instead
of `:latest`. Mercure **1.0.0** was published 2026-09-16 (15:38 UTC, the `latest` tag moved) with
breaking changes: authorization rebuilt on OAuth 2.0 (0.x publisher/subscriber JWTs are refused,
`HTTP/1.1 401 Unauthorized` on `POST /.well-known/mercure`) and topic matching switched from URI
Templates to URL Patterns (`match=` / `match_urlpattern=`) — a compatibility mode exists
(https://mercure.rocks/docs/1.0/UPGRADE#compatibility-mode) but is opt-in. A routine
`docker compose pull` on dev/CI moved to 1.0 overnight and broke `ReconcileStuckSchedulesTest`,
Mercure publication, Backend Coverage and the `journey.spec` e2e (no SSE stream ever opens) — the
same failure mode pinning already prevented in prod, now closed in every zone. **Rule for any
third-party image**: pin an exact tag in dev, CI and prod alike — never `:latest` anywhere in
this repo's compose files.

## Public URL

`MERCURE_PUBLIC_URL` (the browser-facing hub URL) is set by compose on the
`php-fpm` service to `http://localhost:${MERCURE_PORT}/.well-known/mercure`, so
it always matches the port the hub is actually published on. The static value in
`backend/.env` is only a fallback for non-Docker runs.

## Frontend consumption (delivered — FRT-04, 2026-08-07)

The frontend subscribes to the hub for generation progress; polling survives
only as a **fallback** (the publisher is best-effort — a missed event self-heals
on the next poll).

- **Subscriber JWT**: minted by `GET /api/mercure/auth`
  (`backend/src/Controller/MercureAuthController.php`) — HS256, **same
  `MERCURE_JWT_SECRET` as the publisher**, `subscribe` claim = the single URI
  template `club:{clubId}:schedule:{id}` where `clubId` is the **authenticated
  member's resolved tenant** (`_club_id` request attribute — never a client
  parameter). No wildcard, no other club. TTL 1 h.
- **Delivery**: `mercureAuthorization` **cookie, httpOnly, SameSite strict,
  `Secure` from `JWT_COOKIE_SECURE`** (the same switch as the app JWT cookie —
  never `$request->isSecure()`, which answers false behind the prod nginx:
  `docs/security/jwt-cookie.md`), **path `/.well-known/mercure`** — the JS never sees the hub token (the
  app JWT in localStorage is already the weak point; no second exposed token),
  and the browser only sends it to the hub, same-origin via the vite/nginx
  proxies. Guarded by `backend/tests/Api/MercureAuthTest.php` (phase1 — the
  claim's club scope is a tenant boundary).
- **Client**: `frontend/src/features/planning/lib/scheduleStream.ts` — ONE ref-counted
  `EventSource` per session, subscribed to the **template itself as topic**
  (the response's `topicTemplate`; the hub matches every exact
  `club:X:schedule:<uuid>` topic against it, so all the club's generations
  arrive on one connection without knowing their ids). Events **invalidate**
  the react-query caches (the server stays the source of truth); the poll
  degrades from 2.5 s to a 15 s fallback while the stream is connected. On
  stream error the client closes and **re-authenticates on retry** — never the
  native EventSource reconnection, which would replay an expired cookie forever.

`anonymous` stays off; nothing here relaxes the hub configuration above.

### ⚠ The club id in that selector must be a CANONICAL uuid (security review, fixed 2026-08-07)

The `subscribe` selector is parsed by the hub as an **RFC 6570 URI template**:
anything shaped `{name}` becomes a *variable*, not a literal. A club id in the
non-canonical form PostgreSQL happily accepts — `{710a290720ce40ffa67fc2674ca0dbe7}`,
braces, no hyphens — is a valid template varname, so the selector
`club:{710a29…}:schedule:{id}` carries **two** variables and matches
**every club's** topics. Measured live on the dev stack before the fix: a member
of club A received club B's generation events.

The entry point was `X-Club-Id`: the membership check compares in Postgres,
which normalises, so a member could pass their own club in the degraded form and
have that raw string land in `_club_id`. Fixed at the source —
`TenantFilterListener` now validates the club's **shape** exactly like the
season's (`backend/src/EventListener/TenantFilterListener.php`, `isUuid` guard
before `_club_id` is set, 403 otherwise: refused, never silently normalised) —
plus a defence-in-depth re-check in `MercureAuthController` before signing.
Guarded by `TenantIsolationTest::testANonCanonicalClubHeaderIsRejectedEvenForOwnClub`
(phase1) and `MercureAuthTest::testANonCanonicalClubIdNeverReachesTheSelector`.
**Rule for any future selector**: never interpolate a client-influenced string
into a topic selector without canonical validation.
