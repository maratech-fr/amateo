<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-51 PR-1 — bloc de mutualisation : tables `shared_training_block` + `shared_training_block_team`.
 *
 * Deux tables tenant-owned (`club_id`) : ENABLE + FORCE ROW LEVEL SECURITY comme toute table
 * club_id (RlsIsolationTest les découvre dynamiquement et exige ENABLE+FORCE+policy). Deux
 * policies chacune : `tenant_isolation` adossée au GUC app.club_id (prédicat canon, identique à
 * shared_training_group/team_tag), et la porte `admin_all` TO amateo_owner — EXACTEMENT une par
 * table (l'invariant que RlsIsolationTest fait respecter).
 *
 * `schedule_plan_id` NULLABLE = socle saison (NULL) vs plan de période (UUID) — patron
 * `shared_training_group`. Écrit à la main : `make migration-diff` est inopérant tant que
 * doctrine/dbal reste < 4.5.
 */
final class Version20260831120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P2-51: shared_training_block (+ _team) tables + FORCE RLS (tenant_isolation + admin_all).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shared_training_block (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID DEFAULT NULL, common_sessions INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_shared_training_block_club_season ON shared_training_block (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_shared_training_block_plan ON shared_training_block (schedule_plan_id)');

        $this->addSql('CREATE TABLE shared_training_block_team (id UUID NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID DEFAULT NULL, block_id UUID NOT NULL, team_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_shared_training_block_team ON shared_training_block_team (block_id, team_id)');
        $this->addSql('CREATE INDEX idx_shared_training_block_team_block ON shared_training_block_team (block_id)');
        $this->addSql('CREATE INDEX idx_shared_training_block_team_club_season ON shared_training_block_team (club_id, season_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');

        foreach (['shared_training_block', 'shared_training_block_team'] as $table) {
            if (\is_string($appRole)) {
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
            if ($hasOwner) {
                $this->addSql(\sprintf('CREATE POLICY admin_all ON public.%s FOR ALL TO amateo_owner USING (true) WITH CHECK (true)', $table));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte ses index, ses policies et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE shared_training_block_team');
        $this->addSql('DROP TABLE shared_training_block');
    }
}
