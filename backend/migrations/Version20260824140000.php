<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RMM-4 PR-3 « canal API FFBB » — `fixture.ffbb_rencontre_id` : l'id NATIONAL d'une
 * rencontre FFBB, posé sur les matchs créés depuis le canal API (les amicaux
 * absents du xlsx). Clé d'idempotence : re-vérifier via l'API ne re-propose jamais
 * un match déjà créé, et une création concurrente collisionne proprement (409).
 *
 * Écrite à la main (doctrine/dbal 4.4.4 ne génère plus le diff, backend.md).
 * Index unique PARTIEL sur le patron `uniq_fixture_external_ref` : team-scoped
 * (un amical intra-club peut exister une fois par équipe), NULL exclus (les
 * fixtures xlsx / saisis à la main n'ont pas d'id de rencontre). Pas de RLS ni de
 * GRANT à toucher : la table `fixture` les porte déjà.
 */
final class Version20260824140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RMM-4 PR-3: fixture.ffbb_rencontre_id (national FFBB rencontre id) + partial unique index (FFBB-API channel idempotence).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD ffbb_rencontre_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_fixture_ffbb_rencontre ON fixture (club_id, season_id, team_id, ffbb_rencontre_id) WHERE ffbb_rencontre_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Le DROP COLUMN emporte l'index partiel avec lui.
        $this->addSql('ALTER TABLE fixture DROP ffbb_rencontre_id');
    }
}
