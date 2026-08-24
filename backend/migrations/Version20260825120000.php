<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RMM-5 (P2-49) — rotation A/B : tables `match_slot_rotation` + `match_slot_rotation_team`.
 *
 * Deux tables tenant-owned (`club_id`) : FORCE ROW LEVEL SECURITY comme toute table club_id
 * (RlsIsolationTest les découvre dynamiquement et exige ENABLE+FORCE+policy). Deux policies
 * chacune : `tenant_isolation` adossée au GUC app.club_id (prédicat canon, identique à
 * team_match_habit/venue_match_window), et la porte `admin_all` TO clubscheduler (le
 * provisioning des portes admin a déjà tourné et ne couvre pas une table créée après lui).
 *
 * PAS de `schedule_plan_id` : le module matchs vit hors des plans de période (patron
 * team_match_habit/venue_match_window). `venue_id` NOT NULL — la rotation EST le créneau.
 * Unicité `(club_id, season_id, venue_id, day_of_week, kickoff_time)` : un créneau physique
 * ne porte qu'une rotation. Écrit à la main : `make migration-diff` est inopérant tant que
 * doctrine/dbal reste < 4.5 (backend.md).
 */
final class Version20260825120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'RMM-5: match_slot_rotation (+ _team) tables + FORCE RLS (tenant_isolation + admin_all).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE match_slot_rotation (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, kickoff_time TIME(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_match_slot_rotation_slot ON match_slot_rotation (club_id, season_id, venue_id, day_of_week, kickoff_time)');
        $this->addSql('CREATE INDEX idx_match_slot_rotation_club_season ON match_slot_rotation (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_match_slot_rotation_venue ON match_slot_rotation (venue_id)');

        $this->addSql('CREATE TABLE match_slot_rotation_team (id UUID NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, rotation_id UUID NOT NULL, team_id UUID NOT NULL, position INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_match_slot_rotation_team ON match_slot_rotation_team (rotation_id, team_id)');
        $this->addSql('CREATE INDEX idx_match_slot_rotation_team_rotation ON match_slot_rotation_team (rotation_id)');
        $this->addSql('CREATE INDEX idx_match_slot_rotation_team_club_season ON match_slot_rotation_team (club_id, season_id)');

        $hasAppUser = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'clubscheduler\'');

        foreach (['match_slot_rotation', 'match_slot_rotation_team'] as $table) {
            if ($hasAppUser) {
                $this->addSql(\sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO app_user', $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s ENABLE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s FORCE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf(
                    'CREATE POLICY tenant_isolation ON public.%s FOR ALL TO app_user USING (%s) WITH CHECK (%s)',
                    $table,
                    self::TENANT_PREDICATE,
                    self::TENANT_PREDICATE,
                ));
            }
            if ($hasOwner) {
                $this->addSql(\sprintf('CREATE POLICY admin_all ON public.%s FOR ALL TO clubscheduler USING (true) WITH CHECK (true)', $table));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte ses index, ses policies et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE match_slot_rotation_team');
        $this->addSql('DROP TABLE match_slot_rotation');
    }
}
