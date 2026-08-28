#!/bin/bash
# ClubScheduler database users — runs after 01-rls.sql (app_security exists).
#
# Shell wrapper (was 02-users.sql) so the passwords come from the environment.
# FAIL-CLOSED: no fallback defaults here — a cluster initialised without these
# vars would permanently carry the publicly-committed dev passwords (initdb.d
# never re-runs). The DEV compose passes the historical dev values explicitly;
# prod compose passes them from .env.prod. Any other path (bare `docker run`,
# a compose refactor that drops the mappings) aborts the init instead.
set -euo pipefail

: "${APP_USER_PASSWORD:?02-users.sh: APP_USER_PASSWORD must be set (dev compose provides the dev default; prod .env.prod provides the real one)}"

psql -v ON_ERROR_STOP=1 \
     -v app_user_password="$APP_USER_PASSWORD" \
     --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'EOSQL'
-- =============================================================================
-- 1. APPLICATION USER (amateo_app)
-- =============================================================================
-- Used by the Symfony backend at runtime.
-- NO DDL privileges, NO SUPERUSER.
-- Can only read/write data in the public schema.
--
-- Named amateo_app directly (no transitional legacy name). The frozen RLS
-- migrations resolve the application role by probing BOTH names — app_user OR
-- amateo_app — and target whichever exists, so they replay cleanly on this fresh
-- cluster (amateo_app) AND on a pre-existing cluster still carrying the historical
-- app_user (which a final migration renames in place — its policies follow the
-- role OID). The password VARIABLE keeps its APP_USER_* name (dev secret,
-- unchanged); only the ROLE is renamed.

CREATE USER amateo_app WITH PASSWORD :'app_user_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;

-- Grant usage on the public schema
GRANT USAGE ON SCHEMA public TO amateo_app;

-- Grant DML on all existing tables in public
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO amateo_app;

-- Grant DML on all future tables in public (via default privileges)
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO amateo_app;

-- Grant usage on all sequences (needed for auto-increment IDs)
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO amateo_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE ON SEQUENCES TO amateo_app;

-- =============================================================================
-- 2. (retiré) MIGRATION USER
-- =============================================================================
-- `migration_user` a été SUPPRIMÉ le 2026-07-31 (migration Version20260731090000).
-- Il était créé ici avec GRANT ALL sur le schéma, ses tables et ses séquences (plus des
-- ALTER DEFAULT PRIVILEGES qui étendaient ces droits aux tables futures) — et n'était
-- utilisé par AUCUNE connexion : les migrations passent par `amateo_owner`
-- (DATABASE_ADMIN_URL). Un compte de service dormant à droits larges, sans contrepartie.
-- Il ne pouvait pas non plus être câblé pour de vrai : NOSUPERUSER sans BYPASSRLS, il est
-- default-deny sous FORCE ROW LEVEL SECURITY — migrations et fixtures casseraient.
-- Détail : backend/docs/RLS.md.

-- =============================================================================
-- 3. RLS CONTEXT SETTER HELPER (executed by amateo_app)
-- =============================================================================
-- This function safely sets the tenant context variable used by RLS policies.
-- It is owned by the bootstrap superuser but executable by amateo_app.

CREATE OR REPLACE FUNCTION app_security.set_club_id(club_id uuid)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    PERFORM set_config('app.club_id', club_id::text, true);
END;
$$;

COMMENT ON FUNCTION app_security.set_club_id(uuid) IS
    'Sets the RLS tenant context variable. Must be called before each transaction that relies on tenant_isolation policies.';

GRANT EXECUTE ON FUNCTION app_security.set_club_id(uuid) TO amateo_app;
EOSQL
