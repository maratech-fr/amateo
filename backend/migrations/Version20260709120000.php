<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persistent reservations (B2): pin a team onto a precise availability slot,
 * layered base/overlay via calendar_entry_id — decoupled from the ephemeral
 * generated schedule (unlike schedule_slot_template, which also stores results).
 */
final class Version20260709120000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'Reservations (B2): persistent team→slot HARD pins, base/overlay via calendar_entry_id (+RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reservation (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, calendar_entry_id UUID DEFAULT NULL, team_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE NOT NULL, duration_minutes SMALLINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_reservation_club_season ON reservation (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_reservation_calendar_entry ON reservation (calendar_entry_id)');
        $this->addSql('CREATE INDEX idx_reservation_team ON reservation (team_id)');

        // RLS: FORCE + tenant_isolation policy keyed on the app.club_id GUC (every
        // club_id table inherits this pattern — RlsIsolationTest enforces it).
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON reservation TO ' . $appRole);
            $this->addSql('ALTER TABLE public.reservation ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.reservation FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.reservation FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reservation');
    }
}
