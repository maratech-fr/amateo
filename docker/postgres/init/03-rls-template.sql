-- ClubScheduler Row-Level Security (RLS) Policy Template
-- =============================================================================
-- WARNING: This script is a TEMPLATE. It is stored in initdb so it is version-
-- controlled, but it is NOT executed automatically on tables that do not yet
-- exist.  Run the relevant blocks manually (or via a migration) AFTER the
-- business tables have been created by Doctrine/Symfony migrations.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- A. ENABLE RLS ON A SPECIFIC TABLE (run once per table after creation)
-- ---------------------------------------------------------------------------
-- Replace <table_name> with the actual table name (e.g. event, member, booking).
-- Tables that do NOT contain a club_id column MUST NOT have RLS enabled.

-- ALTER TABLE public.<table_name> ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE public.<table_name> FORCE ROW LEVEL SECURITY;

-- ---------------------------------------------------------------------------
-- B. CREATE TENANT-ISOLATION POLICY (run once per table after RLS is enabled)
-- ---------------------------------------------------------------------------
-- The policy below restricts every row operation to rows whose club_id matches
-- the value previously set via app_security.set_club_id(uuid).

-- CREATE POLICY tenant_isolation ON public.<table_name>
--     FOR ALL
--     USING (club_id = current_setting('app.club_id')::UUID)
--     WITH CHECK (club_id = current_setting('app.club_id')::UUID);

-- Under FORCE RLS the owner (amateo_owner) is ALSO subject to policies. On a
-- managed provider the owner is not a superuser, so without an explicit policy
-- every FORCE table default-DENIES the admin supervision connection. Always add
-- the admin door alongside tenant_isolation:

-- CREATE POLICY admin_all ON public.<table_name>
--     FOR ALL TO amateo_owner
--     USING (true) WITH CHECK (true);

-- ---------------------------------------------------------------------------
-- C. BATCH-ENABLE RLS ON ALL EXISTING TABLES THAT HAVE club_id
-- ---------------------------------------------------------------------------
-- This uses the helper function created in 01-rls.sql.
-- Safe to run repeatedly; it skips tables that are already RLS-enabled.

-- SELECT app_security.enable_rls_for_existing_amateo_tables();

-- ---------------------------------------------------------------------------
-- D. EXAMPLE: COMPLETE SETUP FOR A NEW TABLE (copy-paste template)
-- ---------------------------------------------------------------------------
-- Uncomment and adapt when a new table is created:

-- ALTER TABLE public.event ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE public.event FORCE ROW LEVEL SECURITY;
-- CREATE POLICY tenant_isolation ON public.event
--     FOR ALL
--     USING (club_id = current_setting('app.club_id')::UUID)
--     WITH CHECK (club_id = current_setting('app.club_id')::UUID);
-- CREATE POLICY admin_all ON public.event
--     FOR ALL TO amateo_owner
--     USING (true) WITH CHECK (true);

-- ---------------------------------------------------------------------------
-- E. TABLES THAT MUST NEVER HAVE RLS ENABLED
-- ---------------------------------------------------------------------------
-- The following system / cross-tenant tables typically do NOT contain club_id
-- and must remain unrestricted:
--   - doctrine_migration_versions
--   - messenger_messages
--   - messenger_delivered_messages
--   - sessions (if used)
--   - Any future table without a club_id foreign key

-- ---------------------------------------------------------------------------
-- F. VERIFY CURRENT STATE
-- ---------------------------------------------------------------------------
-- List tables with RLS status and attached policies:

-- SELECT
--     schemaname,
--     tablename,
--     rowsecurity AS rls_enabled
-- FROM pg_tables
-- JOIN pg_class ON pg_class.relname = pg_tables.tablename
-- WHERE schemaname = 'public';

-- SELECT * FROM pg_policies WHERE schemaname = 'public';
