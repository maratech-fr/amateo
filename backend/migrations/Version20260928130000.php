<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-272 ① — `club_league_window` : la COPIE tenant, par club et par saison, de
 * l'enveloppe de fenêtres de match de la ligue (« écran unique des contraintes
 * de match », section Ligue). Mêmes colonnes métier que le catalogue GLOBAL
 * `league_match_window`, plus club_id/season_id + version/updated_at (patron
 * TeamMatchHabit).
 *
 * RLS : patron Version20260803150000 — GRANT + ENABLE/FORCE + policy
 * `tenant_isolation`, conditionné au rôle applicatif. RlsIsolationTest découvre
 * la table automatiquement (colonne club_id). Table neuve : l'ancienne release
 * ne la touche pas (rétro-compat deploy).
 *
 * Backfill des clubs existants : hors migration (une écriture club-scopée exige
 * le GUC tenant, et le passé n'est pas recopié — commande dédiée
 * `app:club-league-windows:backfill`, saison en cours + suivante si elle
 * existe).
 */
final class Version20260928130000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P4-272 ①: club_league_window (copie ligue par club, tenant + RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE club_league_window (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, league VARCHAR(24) NOT NULL, category VARCHAR(40) NOT NULL, level VARCHAR(20) NOT NULL, gender VARCHAR(10) DEFAULT NULL, day_of_week SMALLINT NOT NULL, kickoff_min TIME(0) WITHOUT TIME ZONE NOT NULL, kickoff_max TIME(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_club_league_window ON club_league_window (club_id, season_id, category, level, gender, day_of_week, kickoff_min)');
        $this->addSql('CREATE INDEX idx_club_league_window_club_season ON club_league_window (club_id, season_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON club_league_window TO ' . $appRole);
            $this->addSql('ALTER TABLE public.club_league_window ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.club_league_window FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.club_league_window FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin (§6) : le rôle propriétaire garde l'accès sous FORCE RLS.
        // RlsIsolationTest exige exactement 1 admin_all par table FORCE.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.club_league_window FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS admin_all ON public.club_league_window');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.club_league_window');
        $this->addSql('DROP TABLE club_league_window');
    }
}
