<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-187a — un domicile importé retrouve son gymnase depuis le libellé FBI/FFBB.
 *
 * `venue.external_labels` (JSON, défaut `[]`) porte les libellés confirmés qui
 * désignent le gymnase — normalisés et dédupliqués. Un domicile importé qui porte
 * l'un d'eux se voit poser son `venueId` automatiquement (la rencontre reste
 * UNPLACED : on la rend seulement visible de la collision et de la fermeture).
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que
 * doctrine/dbal < 4.5). RLS sans objet : les migrations tournent sous
 * `amateo_owner` (BYPASSRLS).
 */
final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-187a: venue.external_labels (confirmed FBI/FFBB salle aliases).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue ADD external_labels JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue DROP external_labels');
    }
}
