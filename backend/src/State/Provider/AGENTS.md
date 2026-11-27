# State Providers — Agent Context

> API Platform state providers for read operations. Override default Doctrine ORM behavior.

## Structure

| File | Role |
|------|------|
| `AbstractStateProvider.php` | Base with tenant filtering |
| `VenueStateProvider.php` | Venue collection/item |
| `TeamStateProvider.php` | Team collection/item |
| `...` | 10+ other entity providers |

## Pattern

All providers extend `AbstractStateProvider`: `provide()` dispatches to `provideCollection()`
(paginated, ordered by `e.id`) or `provideItem()`; a child implements `getEntityClass()` and
`mapEntityToOutput()`, and may override `applyRequestFilters()` / `decorateCollection()`.

## Tenant scoping — the provider adds NO `clubId` clause

Collections are scoped by the Doctrine `tenant_filter` (`TenantFilterListener`) and PostgreSQL RLS
(`docs/TENANT.md`). Entities WITHOUT a `club_id` column (Club, User) escape that filter and must
bound their own access (see `ClubStateProvider`: membership check in `provideItem()`; `applyRequestFilters()` for collections).

## Critical Gotchas

1. **Collection queries** — scoped by the filter + RLS, not by the provider; a new club-less entity needs its own bound.
2. **Item lookups** — For an entity that `implements App\Entity\TenantOwnedInterface`, the provider checks `getClubId()` matches before returning it (BCK-03: `instanceof`, not `method_exists`). The DB-level `TenantFilter`/RLS already scope by the `club_id` column, so this is defence-in-depth.
3. **Custom filters** — If you add `Filter` to an ApiResource, the provider must handle it.

## Quick Reference

| Task | Location |
|------|----------|
| Add provider to new entity | Extend `AbstractStateProvider` + implement `getEntityClass()` and `mapEntityToOutput()` |
| Fix missing data | Check `TenantFilterListener` sets `_club_id` on request |
| Custom query logic | Override `applyRequestFilters()` (return true = bounded, no pagination) or `provideItem()` |
