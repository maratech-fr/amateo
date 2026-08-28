<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1-4 PR C — la couche PRÉFÉRENCES du module matchs, deux tables tenant + RLS.
 *
 * `team_match_habit` : la fenêtre habituelle d'une équipe (« SF3 = dimanche
 * 17h30 ») — un instant, pas une plage ; gymnase optionnel ; UNE habitude par
 * jour et par équipe (unique). Recopiée à la bascule de saison.
 *
 * `team_link` : la passerelle déclarée entre deux équipes (joueurs partagés →
 * NOT_SIMULTANEOUS ; enchaînement souhaité → BACK_TO_BACK). Symétrique par
 * normalisation applicative (team_a_id < team_b_id), un couple = un lien
 * (unique). Nom neutre : cross-module par conception.
 *
 * RLS : patron Version20260706240000/Version20260803090000 — GRANT +
 * ENABLE/FORCE + policy `tenant_isolation`, conditionné au rôle `app_user`.
 * RlsIsolationTest découvre les tables automatiquement. Rétro-compat deploy :
 * tables neuves, l'ancienne release ne les touche pas.
 */
final class Version20260803150000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'Match P1-4 PR C: team_match_habit + team_link (tenant, RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE team_match_habit (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, team_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, kickoff_time TIME(0) WITHOUT TIME ZONE NOT NULL, venue_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_team_match_habit_day ON team_match_habit (club_id, season_id, team_id, day_of_week)');
        $this->addSql('CREATE INDEX idx_team_match_habit_club_season ON team_match_habit (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_team_match_habit_team ON team_match_habit (team_id)');

        $this->addSql('CREATE TABLE team_link (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, team_a_id UUID NOT NULL, team_b_id UUID NOT NULL, link_type VARCHAR(20) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_team_link_couple ON team_link (club_id, season_id, team_a_id, team_b_id)');
        $this->addSql('CREATE INDEX idx_team_link_club_season ON team_link (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_team_link_team_a ON team_link (team_a_id)');
        $this->addSql('CREATE INDEX idx_team_link_team_b ON team_link (team_b_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            foreach (['team_match_habit', 'team_link'] as $table) {
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
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.team_link');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.team_match_habit');
        $this->addSql('DROP TABLE team_link');
        $this->addSql('DROP TABLE team_match_habit');
    }
}
