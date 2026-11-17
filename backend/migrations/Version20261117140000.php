<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Gymnase extérieur » n'existe plus : l'entité ne porte plus `isExternal`, mais la colonne reste
 * jusqu'à la prochaine mise en prod de `main` pour permettre le retour au code précédent (elle tombe
 * alors avec les quatre liens de saison `parent_*_id`). Sans défaut, tout INSERT de `venue`
 * échouerait sur NOT NULL : création, copie de saison, restauration d'une photo, seeds.
 *
 * Écrite à la main, jamais par `make migration-diff` (qui proposerait de supprimer les colonnes).
 */
final class Version20261117140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'venue.is_external gets a default: the column is no longer written by the application.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue ALTER COLUMN is_external SET DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue ALTER COLUMN is_external DROP DEFAULT');
    }
}
