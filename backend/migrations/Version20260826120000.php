<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-52 (RMM-10) — `fixture.unplaced_reason` (VARCHAR(32) nullable) : la raison
 * PERSISTANTE d'un match retourné « à placer » (aujourd'hui `venue_lost` seul — le
 * gymnase n'est plus affilié au club). Distincte de la raison volatile d'auto-placement
 * qui ne vit que dans l'UI.
 *
 * Simple ajout de colonne sur une table tenant déjà existante (RLS déjà active sur
 * `fixture`) — aucune policy ni GRANT à toucher. Écrit à la main : `make migration-diff`
 * est inopérant tant que doctrine/dbal reste < 4.5 (backend.md).
 */
final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-52: fixture.unplaced_reason column (persistent venue_lost reason).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD unplaced_reason VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture DROP unplaced_reason');
    }
}
