<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier D (CTC) PR1 — deux colonnes sur `team` pour l'entente (CTC) :
 *   - `is_ctc` BOOLEAN NOT NULL DEFAULT false : l'équipe est-elle déclarée en entente ? Les lignes
 *     existantes deviennent « pas une CTC » (défaut false) ;
 *   - `ctc_partner` VARCHAR(120) nullable : le/les partenaire(s) en TEXTE LIBRE opaque, null hors
 *     entente.
 *
 * `team` est déjà sous FORCE RLS (migration de création, tenant-owned) ; ajouter des colonnes ne
 * touche ni les politiques ni les GRANT. Repère INTERNE au club, aucun effet moteur/créneaux.
 *
 * Écrite à la main : `make migration-diff` est inopérant tant que `doctrine/dbal` reste < 4.5.
 */
final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Team: add is_ctc boolean (default false) + ctc_partner varchar(120) nullable — entente (CTC) marker, no engine effect.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD is_ctc BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE team ADD ctc_partner VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team DROP ctc_partner');
        $this->addSql('ALTER TABLE team DROP is_ctc');
    }
}
