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
 *  - `schedule_slot_template.shared_training_block_id` UUID + FK `ON DELETE CASCADE` : le LIEN
 *    d'une séance à SON bloc de mutualisation (option B). Le geste « mutualiser » le pose sur la
 *    séance source verrouillée, sur chaque séance remplacée déplacée et sur la séance neuve d'une
 *    équipe activée — ce sont LES séances de groupe du bloc. NULL = séance ordinaire (le défaut).
 *    `ON DELETE CASCADE` fait de « une séance de groupe ne survit jamais à son bloc » (Q4) une
 *    garantie de la base : supprimer le bloc — par N'IMPORTE quel chemin (DELETE API, purge à la
 *    suppression d'une équipe, purge du plan de période) — emporte ses séances liées, sans qu'aucun
 *    chemin ait à le refaire (un FK non-CASCADE ferait au contraire ÉCHOUER les `DELETE
 *    shared_training_block` en masse qui existent déjà). Une séance HARD NON liée (verrouillée
 *    ailleurs à la main) n'est jamais touchée ; un bloc socle n'a aucune séance liée, donc son
 *    DELETE est inchangé. Index sur la colonne pour la jointure inverse (les séances d'un bloc).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 9: add shared_training_block.label (nullable varchar(40)), team_period_override.source (nullable varchar(30), generic origin marker), and schedule_slot_template.shared_training_block_id (nullable UUID, FK ON DELETE CASCADE) linking a session to its mutualisation block.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_training_block ADD label VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE team_period_override ADD source VARCHAR(30) DEFAULT NULL');

        $this->addSql('ALTER TABLE schedule_slot_template ADD shared_training_block_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule_slot_template ADD CONSTRAINT fk_schedule_slot_template_shared_block FOREIGN KEY (shared_training_block_id) REFERENCES shared_training_block (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_schedule_slot_template_shared_block ON schedule_slot_template (shared_training_block_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_slot_template DROP CONSTRAINT fk_schedule_slot_template_shared_block');
        $this->addSql('DROP INDEX idx_schedule_slot_template_shared_block');
        $this->addSql('ALTER TABLE schedule_slot_template DROP COLUMN shared_training_block_id');

        $this->addSql('ALTER TABLE shared_training_block DROP COLUMN label');
        $this->addSql('ALTER TABLE team_period_override DROP COLUMN source');
    }
}
