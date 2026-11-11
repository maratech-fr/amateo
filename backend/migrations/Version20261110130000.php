<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Journal de nouveautés — version du produit annoncée par une note (`release_note.product_version`).
 *
 * Facultative (NULL = note sans version) et rétro-compatible : les notes existantes restent sans
 * version, un ancien bundle ignore la clé. Normalisée par la console (« v1.3.5 » → « 1.3.5 »,
 * motif de 2 à 4 segments numériques). Le nom `version` est pris par le compteur Doctrine
 * `#[ORM\Version]` de l'entité, d'où `product_version`.
 *
 * Aucun GRANT neuf : le GRANT de table couvre déjà la colonne ajoutée. Écrite à la main :
 * `make migration-diff` est inopérant tant que `doctrine/dbal` reste < 4.5.
 */
final class Version20261110130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Release note: add product_version (VARCHAR(40) NULL) — product version announced by the note.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE release_note ADD product_version VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE release_note DROP product_version');
    }
}
