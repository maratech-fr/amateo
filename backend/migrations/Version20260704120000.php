<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cockpit temporel — palier A fondations:
 *  1. calendar_entry (tenant table) with FORCE RLS (tenant_isolation policy),
 *     mirroring Version20260703120000 so RlsIsolationTest stays green.
 *  2. "constraint".calendar_entry_id — dated constraints attached to a
 *     CalendarEntry period, excluded from base-plan generation.
 *  3. season.socle_validated_at — sticky cockpit-unlock milestone.
 *
 * Runs on the ADMIN connection (clubscheduler). Explicit grants on the new
 * table (default privileges already cover it, kept explicit for safety).
 */
final class Version20260704120000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'Cockpit palier A: calendar_entry (+RLS), constraint.calendar_entry_id, season.socle_validated_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_entry (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, kind VARCHAR(20) NOT NULL, title VARCHAR(180) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, is_disruptive BOOLEAN DEFAULT false NOT NULL, period_type VARCHAR(20) DEFAULT NULL, school_holiday_id UUID DEFAULT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, overlay_schedule_id UUID DEFAULT NULL, created_by VARCHAR(80) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_calendar_entry_club_season ON calendar_entry (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_calendar_entry_window ON calendar_entry (club_id, start_date, end_date)');

        // RLS: FORCE + tenant_isolation policy keyed on the app.club_id GUC.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON calendar_entry TO ' . $appRole);
            $this->addSql('ALTER TABLE public.calendar_entry ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.calendar_entry FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.calendar_entry FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        $this->addSql('ALTER TABLE "constraint" ADD calendar_entry_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_constraint_calendar_entry ON "constraint" (calendar_entry_id)');

        $this->addSql('ALTER TABLE season ADD socle_validated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season DROP socle_validated_at');

        $this->addSql('DROP INDEX idx_constraint_calendar_entry');
        $this->addSql('ALTER TABLE "constraint" DROP calendar_entry_id');

        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.calendar_entry');
        $this->addSql('DROP TABLE calendar_entry');
    }
}
