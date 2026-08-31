<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-51 PR-7 — retrait du modèle groupe K : `shared_training_group` (+ `_team`) disparaît, il
 * n'existe plus qu'UNE notion de mutualisation, le BLOC (`shared_training_block`).
 *
 * ⚠ CONVERSION avant suppression : chaque groupe existant devient un bloc À L'IDENTIQUE (mêmes
 * id, équipes, `commonSessions`, portée) AVANT le `DROP`. Les bases locales (`amateo_dev`,
 * `amateo_local`) gardent ainsi leurs mutualisations sans reseed — un groupe {équipes, K} est
 * exactement un bloc {équipes, commonSessions} (schémas colonne-pour-colonne identiques). L'id du
 * groupe est REPRIS comme id du bloc : aucune collision (tables distinctes), les portées et les
 * réservations « groupe-complètes » déjà posées restent cohérentes (mêmes membres → cases
 * désormais « bloc-complètes »).
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 * RLS sans objet : les migrations tournent sous `amateo_owner` (BYPASSRLS). L'ORDRE compte : les
 * INSERT posent le PARENT (bloc) avant ses MEMBRES ; les DROP retirent les MEMBRES avant le parent.
 */
final class Version20260831130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-51 PR-7: convert shared_training_group(+team) into shared_training_block(+team), then DROP the group tables.';
    }

    public function up(Schema $schema): void
    {
        // 1. CONVERSION — le groupe (parent) devient un bloc, colonne pour colonne, id repris.
        $this->addSql('INSERT INTO shared_training_block (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, common_sessions) SELECT id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, common_sessions FROM shared_training_group');
        // 2. CONVERSION — les membres (enfant) : group_id devient block_id, id repris.
        $this->addSql('INSERT INTO shared_training_block_team (id, club_id, season_id, schedule_plan_id, block_id, team_id) SELECT id, club_id, season_id, schedule_plan_id, group_id, team_id FROM shared_training_group_team');

        // 3. SUPPRESSION — enfant avant parent. DROP TABLE emporte index, policies et GRANT.
        $this->addSql('DROP TABLE shared_training_group_team');
        $this->addSql('DROP TABLE shared_training_group');
    }

    public function down(Schema $schema): void
    {
        // Reverse du SCHÉMA seulement (patron Version20260817120000/130000) : les tables K
        // renaissent VIDES. La conversion up() est one-way par nature — un bloc ne porte pas la
        // trace d'un ancien groupe, on ne peut donc pas re-scinder converti vs natif sans
        // dupliquer la mutualisation. Restaurer la donnée K n'a pas de sens après retrait.
        $this->addSql('CREATE TABLE shared_training_group (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID DEFAULT NULL, common_sessions INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_shared_training_group_club_season ON shared_training_group (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_shared_training_group_plan ON shared_training_group (schedule_plan_id)');

        $this->addSql('CREATE TABLE shared_training_group_team (id UUID NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID DEFAULT NULL, group_id UUID NOT NULL, team_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_shared_training_group_team ON shared_training_group_team (group_id, team_id)');
        $this->addSql('CREATE INDEX idx_shared_training_group_team_group ON shared_training_group_team (group_id)');
        $this->addSql('CREATE INDEX idx_shared_training_group_team_club_season ON shared_training_group_team (club_id, season_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $predicate = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

        foreach (['shared_training_group', 'shared_training_group_team'] as $table) {
            if (\is_string($appRole)) {
                $this->addSql(\sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO ' . $appRole, $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s ENABLE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s FORCE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf(
                    'CREATE POLICY tenant_isolation ON public.%s FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                    $table,
                    $predicate,
                    $predicate,
                ));
            }
            if ($hasOwner) {
                $this->addSql(\sprintf('CREATE POLICY admin_all ON public.%s FOR ALL TO amateo_owner USING (true) WITH CHECK (true)', $table));
            }
        }
    }
}
