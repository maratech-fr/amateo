<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Coach obligatoire » n'existe plus : l'entité ne porte plus `isRequired`, mais la colonne reste le
 * temps d'une release pour permettre le retour au code précédent (elle tombe à la release suivante).
 * Sans défaut, tout INSERT de `team_coach` échouerait sur NOT NULL : création, copie de saison,
 * « Charger », seeds.
 *
 * Écrite à la main (`make migration-diff` inopérant, .claude/rules/backend.md).
 */
final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'team_coach.is_required gets a default: the column is no longer written by the application.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_coach ALTER COLUMN is_required SET DEFAULT true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_coach ALTER COLUMN is_required DROP DEFAULT');
    }
}
