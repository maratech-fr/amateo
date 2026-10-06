<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-311 — genre du coach (`coach.gender`) pour accorder les libellés qui désignent la
 * personne (joueur·euse, salarié·e…).
 *
 * Colonne NON NULLE à défaut `UNSPECIFIED` : aucun backfill explicite, les lignes
 * existantes prennent le défaut (« non précisé » = double forme). Donnée de PRÉSENTATION,
 * jamais envoyée au moteur (CONTRACT_VERSION inchangé).
 *
 * Aucune RLS/GRANT à poser : `coach` porte déjà la policy tenant + un GRANT SELECT
 * TABLE à amateo_read (Version20260930090000), qui couvre automatiquement une nouvelle
 * colonne (pas de grant colonne par colonne sur cette table).
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5,
 * `.claude/rules/backend.md`).
 */
final class Version20261006140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-311: coach.gender (VARCHAR(12) NOT NULL DEFAULT UNSPECIFIED) for inclusive label agreement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach ADD gender VARCHAR(12) DEFAULT \'UNSPECIFIED\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach DROP gender');
    }
}
