# ClubScheduler — PostgreSQL Row-Level Security (RLS)

Last verified @ 2026-08-29 (rotation documentation-update, sans rapport avec le sujet de la PR (ENG-39). Re-confronté au code : `TenantFilterListener.php:55` — `KernelEvents::REQUEST => ['onKernelRequest', 7]` ✓ · `docker/postgres/init/02-users.sh:32` crée bien `amateo_app` ("Named amateo_app directly (no transitional legacy name)") ✓ · `Version20260731090000` supprime toujours `migration_user` ✓ · `RlsIsolationTest.php:195-197` garde le nom/forme de la policy `admin_all` (`FOR ALL`, `USING=true`) TO `amateo_owner` ✓. Rien à corriger ce jour)

> ✅ **STATUS: ACTIVE** since migration `Version20260703120000` (SEC-03 fixed). The migration — not the initdb scripts — is the source of truth for policies and grants: **every table carrying a `club_id` column** is under `FORCE ROW LEVEL SECURITY` with a `tenant_isolation` policy `TO amateo_app` (no hard count here — new tenant tables inherit the pattern via the migration helper; the count would rot). `club_user` and `coach_wish_token` carry the hybrid SELECT bootstrap policy (open only while NO tenant GUC is set — scoped to the tenant otherwise, SEC-12 residual closed by `Version20260804120000`; deliberate cross-tenant reads go through `TenantConnectionContext::runWithoutTenant()`). Runtime connects as `amateo_app`; the GUC is set via `TenantConnectionContext` (`set_config`, session-scoped). **This file = operator how-to (env, roles, troubleshooting). The effective architecture — who sets the GUC, the exception tables, the superadmin door — is `docs/security/rls.md`, and it is CANONICAL.** ⚑ La consigne précédente disait « garder les deux en phase » : c'est précisément ce qui a produit la dérive du prédicat corrigée le 2026-08-19. Deux fichiers qu'on maintient en phase à la main divergent — le seul garde-fou est de ne PAS redire ici ce que le canon dit là-bas : on pointe. The `01/02/03-*.sql` initdb scripts remain for fresh volumes only.

## Overview

ClubScheduler is designed to use **PostgreSQL Row-Level Security (RLS)** to enforce **tenant isolation** at the database layer. Every business table that belongs to a club contains a `club_id` column. RLS policies ensure that the application user (`amateo_app`) can only see and manipulate rows whose `club_id` matches the tenant context set for the current database session.

## Database Users

| User | Purpose | DDL Rights | RLS Bypass |
|------|---------|------------|------------|
| `amateo_app` | Symfony runtime (API requests) | **None** | **No** — policies apply |
| `amateo_owner` | **migrations / ops / superadmin door** (Doctrine `admin` connection, `DATABASE_ADMIN_URL`) | all (owner; superuser **locally only**) | **Locally yes** (superuser). On managed PG (no `BYPASSRLS` ever): non-superuser owner, crosses via the `admin_all` policies (`Version20260813130000`, one per FORCE-RLS table) |

> **Security rule:** `amateo_app` is **not** a `SUPERUSER` and does **not** hold `CREATEDB` or `CREATEROLE`.

## How Tenant Isolation Works

1. **Set context** — Before executing tenant queries, the application (`TenantConnectionContext`) executes directly:
   ```sql
   SELECT set_config('app.club_id', '550e8400-e29b-41d4-a716-446655440000', false);
   ```
   This stores the current club ID in the session variable `app.club_id`. Note: the SQL helper function `app_security.set_club_id(...)` exists in the initdb scripts but is called by **no application code** — the app always issues `set_config` itself (the function remains a convenience for manual `psql` sessions).

2. **Policy enforcement** — Every RLS-protected table has a policy:
   ```sql
   CREATE POLICY tenant_isolation ON public.event
       FOR ALL
       USING (club_id = current_setting('app.club_id')::UUID)
       WITH CHECK (club_id = current_setting('app.club_id')::UUID);
   ```
   - `USING` filters rows on `SELECT`, `UPDATE`, `DELETE`.
   - `WITH CHECK` validates rows on `INSERT`, `UPDATE`.

3. **Force RLS** — `ALTER TABLE ... FORCE ROW LEVEL SECURITY` ensures the policy applies even to the table owner, preventing accidental data leakage if the connection string is misused.

## Enabling RLS on a New Table

There is **no manual post-deploy step**: the Doctrine migration that creates a new `club_id` table also creates its RLS policy — the migration is the source of truth. A migration adding a tenant table must include:

> ⚠ **Ne recopiez pas ce SQL de mémoire — et surtout pas depuis une vieille migration.** Le
> prédicat et le rôle sont **canoniques** : toute policy d'une table `club_id` est comparée par
> **égalité stricte** au canon lu à l'exécution (`RlsIsolationTest::testEveryPolicyOnClubIdTablesIsTenantScoped`,
> gate bloquant). Le geste sûr : copier une migration **récente** qui crée une table tenant — elles
> portent le prédicat dans une constante (`TENANT_PREDICATE`), pas en toutes lettres.
>
> ⚑ Ce document a lui-même dérivé sur ce point jusqu'au 2026-08-19 : il donnait
> `current_setting('app.club_id')::UUID` **sans `NULLIF(…, '')` ni le `true` de `missing_ok`**, et
> **sans `TO amateo_app`**. Les trois écarts comptent — sans `true`, `current_setting` **lève** quand
> le GUC est absent au lieu de rendre NULL (fin du fail-closed) ; sans `NULLIF`, la **chaîne vide**
> que pose `TenantConnectionContext::clear()` part en `''::uuid` et rend une **erreur 22P02** ;
> sans `TO amateo_app`, la policy s'applique à tous les rôles et brouille la porte admin. L'architecture
> effective, elle, est et reste [`../../docs/security/rls.md`](../../docs/security/rls.md).

```sql
-- 1. Enable RLS
ALTER TABLE public.<table_name> ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.<table_name> FORCE ROW LEVEL SECURITY;

-- 2. Create tenant isolation policy — predicate + role are CANONICAL, copy them exactly
CREATE POLICY tenant_isolation ON public.<table_name>
    FOR ALL TO amateo_app
    USING (club_id = NULLIF(current_setting('app.club_id', true), '')::uuid)
    WITH CHECK (club_id = NULLIF(current_setting('app.club_id', true), '')::uuid);

-- 3. Admin door (required — a FORCE-RLS table without it locks the admin
--    connection out on managed PostgreSQL, where no role has BYPASSRLS)
CREATE POLICY admin_all ON public.<table_name>
    FOR ALL TO amateo_owner
    USING (true) WITH CHECK (true);
```

`RlsIsolationTest` (blocking, `--group phase1`) guards that every `club_id` table is covered — including that every FORCE-RLS table carries **exactly one** `admin_all` (a migration that forgets it goes red naming the table).

> **Do NOT enable RLS on tables without `club_id`** (e.g. `doctrine_migration_versions`, `messenger_messages`, `sessions`).

## Batch-Enable RLS on All Existing Tables

A helper function is provided in `01-rls.sql`:

```sql
SELECT app_security.enable_rls_for_existing_amateo_tables();
```

This loops over every table in the `public` schema that has a `club_id` column and enables RLS. It **does not** create policies — you must add those separately (see `03-rls-template.sql`).

## Symfony Integration

### 1. Connection Configuration

Use `amateo_app` for the runtime `DATABASE_URL`:

```env
# .env.local (runtime)
DATABASE_URL="postgresql://amateo_app:app_user_password@postgres:5432/amateo_dev?serverVersion=16&charset=utf8"
```

Migrations and ops run on the **`admin` Doctrine connection** (`amateo_owner`, superuser — the only RLS bypass):

```env
# .env (migrations/ops — doctrine.yaml `admin` connection)
DATABASE_ADMIN_URL="postgresql://amateo_owner:...@postgres:5432/amateo_dev?serverVersion=16&charset=utf8"
```

⚠ `migration_user` **no longer exists** (dropped 2026-07-31, migration `Version20260731090000`). It was created by the init SQL with schema-wide `GRANT ALL` and used by **no** connection — a dormant service account with broad privileges. It could not be wired up either: at the time, `NOSUPERUSER` without `BYPASSRLS` meant default-deny under `FORCE`, so migrations and fixtures would break. Migrations run on the `admin` connection (`amateo_owner`). *(That wall is lifted since P5-7: a `NOSUPERUSER` role that is `amateo_owner` or a member of it passes through the `admin_all` policies — this is exactly the managed-PG regime.)*

### 2. Setting the Tenant Context

This mechanism **exists and is active**: on every request, `TenantFilterListener` (kernel listener, priority 7 — after the firewall) resolves the club from the authenticated user (or validated header) and hands it to `TenantConnectionContext`, which sets the GUC on the runtime connection:

```php
// src/Service/TenantConnectionContext.php (actual code)
$connection->executeStatement(
    "SELECT set_config('app.club_id', ?, false)",
    [$clubId]
);
```

Messenger workers do the same from the message's `clubId` before touching tenant data. No `app_security.set_club_id(...)` call is involved anywhere in the application.

### 3. Testing RLS

You can verify isolation directly in `psql`:

```sql
-- Connect as app_user
\c amateo_dev app_user

-- Without context: should return 0 rows on an RLS-protected table
SELECT * FROM public.event;

-- Set context
SELECT app_security.set_club_id('550e8400-e29b-41d4-a716-446655440000'::uuid);

-- Now only rows for this club are visible
SELECT * FROM public.event;
```

## Files Reference

| File | Purpose |
|------|---------|
| `docker/postgres/init/01-rls.sql` | Helper function to batch-enable RLS on existing tables |
| `docker/postgres/init/02-users.sh` | Creates `amateo_app` with its runtime grants (DML only, no DDL) |
| `docker/postgres/init/03-rls-template.sql` | Copy-paste templates for `ALTER TABLE ... ENABLE RLS` and `CREATE POLICY` |

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `0 rows returned` for a table that has data | `app.club_id` not set | In `psql`: run `SELECT set_config('app.club_id', '<uuid>', false)` (or the `app_security.set_club_id(...)` helper). In the app: the context is set automatically by `TenantConnectionContext` |
| `permission denied for table` | `amateo_app` lacks `GRANT` | Re-run `02-users.sh` or check `GRANT` statements |
| Policy not enforced for table owner | `FORCE ROW LEVEL SECURITY` missing | Run `ALTER TABLE ... FORCE ROW LEVEL SECURITY` |
| Migration fails with RLS error | Migration runs as `amateo_app` (no bypass) | Run migrations on the `admin` connection (`amateo_owner`, superuser) |
