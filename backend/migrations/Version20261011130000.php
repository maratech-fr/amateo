<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot A-2 « une équipe ne siège jamais deux fois à la même case » — filet de base.
 *
 * `schedule_slot_template` gagne un index UNIQUE sur (schedule_id, team_id, venue_id, day_of_week,
 * start_time) : dans UNE version de plan, une équipe n'occupe une case qu'une seule fois. La
 * cohabitation à capacité restante reste permise entre équipes DIFFÉRENTES (clé = team_id compris).
 *
 * ⚠ On PURGE d'abord les doublons hérités avant de créer l'index (sinon la création échouerait) :
 * par groupe de case, on GARDE la ligne la plus verrouillée (HARD > SOFT > NONE), puis la plus
 * ancienne (`created_at`), puis le plus petit `id` ; on supprime les autres. Irréversible — `down()`
 * ne retire que l'index (on ne ressuscite pas des doublons effacés).
 *
 * Écrite À LA MAIN : `make migration-diff` reste inopérant tant que `doctrine/dbal` < 4.5
 * (backend.md). Exécutée par l'owner (amateo_owner, hors RLS) : le DELETE balaie bien toutes les
 * lignes, tous clubs confondus.
 */
final class Version20261011130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot A-2 : index UNIQUE schedule_slot_template(schedule_id, team_id, venue_id, day_of_week, start_time) — une équipe ne siège jamais deux fois à la même case ; purge préalable des doublons (garde la ligne la plus verrouillée, sinon la plus ancienne, sinon le plus petit id).';
    }

    public function up(Schema $schema): void
    {
        // Purge des doublons AVANT l'index : garder une ligne par groupe de case, supprimer le reste.
        $this->addSql(<<<'SQL'
            DELETE FROM schedule_slot_template s
            USING (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY schedule_id, team_id, venue_id, day_of_week, start_time
                    ORDER BY
                        CASE lock_level WHEN 'HARD' THEN 2 WHEN 'SOFT' THEN 1 ELSE 0 END DESC,
                        created_at ASC,
                        id ASC
                ) AS rn
                FROM schedule_slot_template
            ) ranked
            WHERE s.id = ranked.id AND ranked.rn > 1
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_slot_template_case ON schedule_slot_template (schedule_id, team_id, venue_id, day_of_week, start_time)');
    }

    public function down(Schema $schema): void
    {
        // Irréversible pour la PURGE (doublons effacés) : on ne retire que l'index.
        $this->addSql('DROP INDEX uniq_schedule_slot_template_case');
    }
}
