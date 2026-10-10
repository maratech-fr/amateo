<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 9 « mutualiser depuis la génération » — deux colonnes NULLABLE sur des tables EXISTANTES
 * (déjà sous RLS : aucune politique à recréer, un simple ADD COLUMN) :
 *
 *  - `shared_training_block.label` VARCHAR(40) : le nom OPTIONNEL donné à un bloc de mutualisation
 *    (« Baby U7-U9 »…). Porté par le BLOC, affiché partout où il paraît (grille, fiche, PDF, Excel) ;
 *    NULL = bloc sans nom. 40 caractères = le même plafond court que les libellés de créneau.
 *
 *  - `team_period_override.source` VARCHAR(30) : l'ORIGINE d'un override de période, quand il n'a
 *    pas été posé « à la main » par le gestionnaire. Colonne GÉNÉRIQUE, pensée pour plusieurs
 *    producteurs — valeur posée par le lot 9 : `'mutualisation'` (le geste active une équipe à
 *    0 séance dans le plan pour qu'elle rejoigne le bloc) ; d'autres rails pourront y écrire leur
 *    propre marqueur. NULL = override saisi par le gestionnaire (le défaut historique).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 9: add shared_training_block.label (nullable varchar(40)) and team_period_override.source (nullable varchar(30), generic origin marker — e.g. mutualisation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_training_block ADD label VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE team_period_override ADD source VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_training_block DROP COLUMN label');
        $this->addSql('ALTER TABLE team_period_override DROP COLUMN source');
    }
}
