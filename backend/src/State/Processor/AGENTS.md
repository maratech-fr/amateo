# State Processors — Agent Context

> 14+ API Platform state processors. Inheritance pattern with `AbstractStateProcessor`.

## Structure

| File | Role |
|------|------|
| `AbstractStateProcessor.php` | Base class with POST/PUT/DELETE logic |
| `VenueStateProcessor.php` | Venue CRUD |
| `TeamStateProcessor.php` | Team CRUD |
| `CoachStateProcessor.php` | Coach CRUD |
| `ScheduleStateProcessor.php` | Schedule + generation trigger |
| `...` | 10+ other entity processors |

## Pattern

All processors extend `AbstractStateProcessor` which handles:
- **Tenant injection** : auto-sets `clubId` from the `_club_id` request attribute (set upstream by `TenantFilterListener` from the JWT membership — there is no `X-Club-Id` header anymore, AUD-SEC-25)
- **Season resolution** : auto-resolves `seasonId` from active club season if `_season_id` is absent
- **Access control** : checks `clubId` matches on PUT/DELETE
- **Method dispatch** : `POST` → `processPost`, `PUT/PATCH` → `processPut`, `DELETE` → `processDelete`

## Critical Gotchas

1. **Season auto-resolution** — `resolveSeasonId()` finds the active season for the club. If no active season exists, the entity will fail with a DB NOT NULL constraint.
2. **No `seasonId` header by default** — the frontend sends `X-Season-Id` only when a season is explicitly selected; absent, the backend resolves the current season automatically.
3. **Tenant guard is type-based (BCK-03)** — club scoping keys on `$entity instanceof App\Entity\TenantOwnedInterface` (POST stamps `setClubId`, PUT/DELETE deny on `getClubId` mismatch), NOT `method_exists`. `setSeasonId` is still probed via `method_exists()` (no marker interface for season). A new tenant entity MUST `implement TenantOwnedInterface` — enforced by `TenantOwnedInterfaceCompletenessTest`.
4. **UUID strings** — All IDs are string UUIDs, never integers.
5. **DTO mapping** — `createEntityFromInput()` maps DTO → Entity. `updateEntityFromInput()` maps DTO → existing Entity. `mapEntityToOutput()` maps Entity → ApiResource.

## Anti-Patterns

- **Never** hardcode `seasonId` in a processor — always use `resolveSeasonId()`
- **Never** rely on `method_exists('getClubId')` for tenant scoping — use `instanceof TenantOwnedInterface` (BCK-03); a club_id entity that forgets the marker is caught by `TenantOwnedInterfaceCompletenessTest`
- **Never** call `$entityManager->flush()` outside `AbstractStateProcessor` — the base class handles it

## Quick Reference

| Task | Location |
|------|----------|
| Add new entity processor | Extend `AbstractStateProcessor` + implement 4 abstract methods |
| Fix 500 on POST | Check `season_id` NOT NULL + `resolveSeasonId()` working |
| Add tenant field to entity | `implements TenantOwnedInterface` (+ `getClubId`/`setClubId`) + `setSeasonId`/`getSeasonId` |
| Test auto-save | Check `_club_id` request attribute present (authenticated request through the tenant listener) |
