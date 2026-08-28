<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Match management palier A — PR-1 (tenant tables):
 *  - competition (team's championship/cup/brassage phase)
 *  - fixture (a dated match; « match » is a PHP keyword → fixture)
 * Both season-scoped tenant tables with FORCE RLS (tenant_isolation policy),
 * mirroring calendar_entry so RlsIsolationTest stays green.
 * Plus club.league (derived from ffbbClubCode, the LeagueResolver output).
 *
 * Runs on the ADMIN connection.
 */
final class Version20260706240000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'Match palier A: competition + fixture (tenant, RLS) + club.league.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE competition (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, team_id UUID NOT NULL, name VARCHAR(180) NOT NULL, competition_type VARCHAR(20) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_competition_club_season ON competition (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_competition_team ON competition (team_id)');

        $this->addSql('CREATE TABLE fixture (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, team_id UUID NOT NULL, competition_id UUID DEFAULT NULL, match_date DATE NOT NULL, home_away VARCHAR(10) NOT NULL, opponent_label VARCHAR(180) NOT NULL, status VARCHAR(20) DEFAULT \'UNPLACED\' NOT NULL, venue_id UUID DEFAULT NULL, kickoff_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_fixture_club_season ON fixture (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_fixture_team ON fixture (team_id)');
        $this->addSql('CREATE INDEX idx_fixture_date ON fixture (match_date)');

        $this->addSql('ALTER TABLE club ADD league VARCHAR(24) DEFAULT NULL');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            foreach (['competition', 'fixture'] as $table) {
                $this->addSql(\sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO ' . $appRole, $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s ENABLE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s FORCE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf(
                    'CREATE POLICY tenant_isolation ON public.%s FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                    $table,
                    self::TENANT_PREDICATE,
                    self::TENANT_PREDICATE,
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.fixture');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.competition');
        $this->addSql('ALTER TABLE club DROP league');
        $this->addSql('DROP TABLE fixture');
        $this->addSql('DROP TABLE competition');
    }
}
