<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Period-editable structure (reprise / plan de vacances P1):
 * - constraint_period_override — sparse per-(period, constraint) toggle that DISABLES
 *   a permanent constraint for one CLOSURE period. No row = the constraint applies as
 *   usual; the base plan and the Constraint's own isActive are never touched.
 *   RLS FORCE like every club_id table.
 */
final class Version20260712130000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'period-editable structure: constraint_period_override (+RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE constraint_period_override (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, calendar_entry_id UUID NOT NULL, constraint_id UUID NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_constraint_period_override ON constraint_period_override (calendar_entry_id, constraint_id)');
        $this->addSql('CREATE INDEX idx_constraint_period_override_entry ON constraint_period_override (calendar_entry_id)');

        // RLS: FORCE + tenant_isolation policy keyed on the app.club_id GUC (every
        // club_id table inherits this pattern — RlsIsolationTest enforces it).
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON constraint_period_override TO ' . $appRole);
            $this->addSql('ALTER TABLE public.constraint_period_override ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.constraint_period_override FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.constraint_period_override FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE constraint_period_override');
    }
}
