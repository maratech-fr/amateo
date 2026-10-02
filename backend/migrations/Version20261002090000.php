<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'horloge simulée devient une capacité générique par club : la colonne
 * `club.demo_today` est renommée `club.simulated_today`.
 *
 * Renommage pur (RENAME COLUMN) : aucune donnée perdue, le type et la valeur
 * existante (y compris la démo BCCL) sont préservés. La colonne n'entre dans
 * aucune policy RLS — rien d'autre à migrer.
 */
final class Version20261002090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename club.demo_today to club.simulated_today (per-club simulated clock capability)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club RENAME COLUMN demo_today TO simulated_today');
        $this->addSql('COMMENT ON COLUMN club.simulated_today IS \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club RENAME COLUMN simulated_today TO demo_today');
        $this->addSql('COMMENT ON COLUMN club.demo_today IS \'(DC2Type:date_immutable)\'');
    }
}
