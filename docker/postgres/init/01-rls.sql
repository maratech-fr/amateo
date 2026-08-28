-- ClubScheduler RLS preparatory template.
-- Safe on a fresh database: no policies are created here.
-- Activate concrete policies only after the business tables exist.

CREATE SCHEMA IF NOT EXISTS app_security;

CREATE OR REPLACE FUNCTION app_security.enable_rls_for_existing_amateo_tables()
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    target_table regclass;
BEGIN
    FOR target_table IN
        SELECT c.oid::regclass
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND c.relkind = 'r'
          AND EXISTS (
              SELECT 1
              FROM pg_attribute a
              WHERE a.attrelid = c.oid
                AND a.attname = 'club_id'
                AND NOT a.attisdropped
          )
    LOOP
        EXECUTE format('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', target_table);
        EXECUTE format('ALTER TABLE %s FORCE ROW LEVEL SECURITY', target_table);
    END LOOP;
END;
$$;

COMMENT ON FUNCTION app_security.enable_rls_for_existing_amateo_tables() IS
    'Template helper: run after migrations create tables, then add concrete tenant policies per table.';

-- Example policy template (intentionally inactive here):
-- CREATE POLICY tenant_isolation_select ON public.some_table
--     FOR SELECT
--     USING (club_id = current_setting(''app.club_id'')::uuid);
