<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Period-editable structure (reprise / plan de vacances P1):
 * - venue_training_slot.calendar_entry_id — additive period slots (a gym lent for
 *   a period), null = permanent seasonal slot.
 * - team_period_override — sparse per-(period, team) override (activation +
 *   sessions/week). RLS FORCE like every club_id table.
 */
final class Version20260712090000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'period-editable structure: venue_training_slot.calendar_entry_id + team_period_override (+RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_training_slot ADD calendar_entry_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_vts_calendar_entry ON venue_training_slot (calendar_entry_id)');

        $this->addSql('CREATE TABLE team_period_override (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, calendar_entry_id UUID NOT NULL, team_id UUID NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, sessions_per_week INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_team_period_override ON team_period_override (calendar_entry_id, team_id)');
        $this->addSql('CREATE INDEX idx_team_period_override_entry ON team_period_override (calendar_entry_id)');

        // RLS: FORCE + tenant_isolation policy keyed on the app.club_id GUC (every
        // club_id table inherits this pattern — RlsIsolationTest enforces it).
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON team_period_override TO ' . $appRole);
            $this->addSql('ALTER TABLE public.team_period_override ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.team_period_override FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.team_period_override FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE team_period_override');
        $this->addSql('DROP INDEX idx_vts_calendar_entry');
        $this->addSql('ALTER TABLE venue_training_slot DROP calendar_entry_id');
    }
}
