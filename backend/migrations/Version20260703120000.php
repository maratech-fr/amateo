<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-03 — activate PostgreSQL Row-Level Security (audit 2026-07-03).
 *
 * Runs on the ADMIN connection (clubscheduler). Three parts:
 *  1. Idempotent app_user role + grants — required for databases that never
 *     saw the docker initdb scripts (clubscheduler_test, pre-existing volumes).
 *  2. ENABLE + FORCE RLS and a tenant_isolation policy on every table owning a
 *     club_id column, restricted to the runtime application role and keyed on
 *     the app.club_id GUC.
 *     Fail-closed without erroring: NULLIF(current_setting(..., true), '')
 *     yields NULL when the GUC is absent → zero rows, no 500.
 *  3. club_user exception: SELECT is open (the tenant is BOOTSTRAPPED from the
 *     membership table — listener/register//api/me read it before any club is
 *     known), writes stay tenant-scoped. Application code always filters
 *     memberships by user_id.
 *
 * The clubscheduler role (database owner/superuser) bypasses all policies —
 * that is the deliberate superadmin supervision door (ops/psql, future
 * super-admin dashboard). Never point the runtime DATABASE_URL at it.
 */
final class Version20260703120000 extends AbstractMigration
{
    /** Tables owning a club_id column, except club_user (special-cased). */
    private const TENANT_TABLES = [
        'coach',
        'coach_player_membership',
        '"constraint"',
        'schedule',
        'schedule_diagnostic',
        'schedule_slot_template',
        'season',
        'sport_category',
        'team',
        'team_coach',
        'team_tag',
        'venue',
        'venue_training_slot',
    ];

    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'Activate RLS: tenant_isolation policies (FORCE) on all club_id tables for app_user; club_user readable for membership bootstrap; idempotent app_user grants.';
    }

    public function up(Schema $schema): void
    {
        // 1. Grants (idempotent). The application role itself is NOT created here:
        // a migration is committed to git, so any password it contained would be
        // public forever (audit review F3). Role provisioning is an environment
        // concern — docker initdb (02-users.sh) in dev/test/CI, manual secure
        // provisioning in prod. Fail loudly if it is missing.
        //
        // Role rename app_user → amateo_app: only the GUARD is amended here (and in
        // every sibling RLS migration) — probe BOTH names and target whichever
        // exists. This keeps the frozen DDL replaying on a fresh cluster (initdb
        // 02-users.sh now creates amateo_app) AND on the founder's existing cluster
        // (still app_user until the FINAL rename migration runs). The DDL body is
        // otherwise unchanged and the schema produced is identical — the policies
        // still bind the runtime role, now under its resolved name; a fresh cluster
        // and its sibling databases (dev + test on one server) all migrate while a
        // single resolvable name exists. Editing a frozen migration is normally
        // forbidden (a deployed cluster would diverge on replay); that reason does
        // not apply — NO cluster is deployed (prod was never provisioned) and every
        // local/CI cluster is recreated from initdb. Unlike the owner role, the
        // application role IS renamable via a final `ALTER ROLE … RENAME`: it is not
        // the session user (migrations run as amateo_owner), so Postgres renames it
        // and the policies follow the role OID automatically.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $this->abortIf(
            !\is_string($appRole),
            'Neither app_user nor amateo_app exists. Create the runtime application role first, with a per-environment password (see docker/postgres/init/02-users.sh): CREATE ROLE amateo_app WITH LOGIN PASSWORD \'<secret>\' NOSUPERUSER NOCREATEDB NOCREATEROLE;',
        );
        \assert(\is_string($appRole));
        $this->addSql('GRANT USAGE ON SCHEMA public TO ' . $appRole);
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ' . $appRole);
        $this->addSql('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ' . $appRole);
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ' . $appRole);
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO ' . $appRole);

        // 2. Tenant tables: FORCE RLS + one FOR ALL policy keyed on the GUC.
        $predicate = self::TENANT_PREDICATE;
        foreach (self::TENANT_TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE public.%s ENABLE ROW LEVEL SECURITY', $table));
            $this->addSql(\sprintf('ALTER TABLE public.%s FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.%s FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                $table,
                $predicate,
                $predicate,
            ));
        }

        // 3. club_user: open SELECT (membership bootstrap), tenant-scoped writes.
        $this->addSql('ALTER TABLE public.club_user ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE public.club_user FORCE ROW LEVEL SECURITY');
        $this->addSql('CREATE POLICY club_user_read ON public.club_user FOR SELECT TO ' . $appRole . ' USING (true)');
        $this->addSql(\sprintf('CREATE POLICY tenant_isolation_insert ON public.club_user FOR INSERT TO ' . $appRole . ' WITH CHECK (%s)', $predicate));
        $this->addSql(\sprintf('CREATE POLICY tenant_isolation_update ON public.club_user FOR UPDATE TO ' . $appRole . ' USING (%s) WITH CHECK (%s)', $predicate, $predicate));
        $this->addSql(\sprintf('CREATE POLICY tenant_isolation_delete ON public.club_user FOR DELETE TO ' . $appRole . ' USING (%s)', $predicate));
    }

    public function down(Schema $schema): void
    {
        foreach (self::TENANT_TABLES as $table) {
            $this->addSql(\sprintf('DROP POLICY IF EXISTS tenant_isolation ON public.%s', $table));
            $this->addSql(\sprintf('ALTER TABLE public.%s DISABLE ROW LEVEL SECURITY', $table));
            $this->addSql(\sprintf('ALTER TABLE public.%s NO FORCE ROW LEVEL SECURITY', $table));
        }

        $this->addSql('DROP POLICY IF EXISTS club_user_read ON public.club_user');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_insert ON public.club_user');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_update ON public.club_user');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_delete ON public.club_user');
        $this->addSql('ALTER TABLE public.club_user DISABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE public.club_user NO FORCE ROW LEVEL SECURITY');
        // Grants are intentionally left in place.
    }
}
