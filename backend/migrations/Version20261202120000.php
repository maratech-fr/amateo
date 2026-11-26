<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Charger » n'a plus qu'une définition (lot 5 conception, PR 3) : il remet l'état de travail du plan d'après la
 * copie du froid de la version (`schedule_state`). La photo de structure de l'ancien « Charger » n'est plus lue par
 * personne : sa table part, ses politiques RLS et ses index avec elle. Irréversible.
 */
final class Version20261202120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression de la table des photos de structure (schedule_structure_snapshot) : « Charger » lit la copie du froid de la version.';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS schedule_structure_snapshot');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les photos de structure ne se recréent pas en arrière : retour arrière par la sauvegarde d\'avant migration.',
        );
    }
}
