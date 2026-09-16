<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `fixture.kept_venue_label` (VARCHAR(180) nullable) : le libellé de salle
 * « gardé » (normalisé) quand le gestionnaire choisit « Garder l'appli » sur
 * l'écart salle d'un domicile NON PLACÉ — pense-bête d'idempotence pour ne pas
 * reposer la même question au re-dépôt. Non exposé à l'API.
 *
 * Simple ajout de colonne sur une table tenant déjà existante (RLS déjà active sur
 * `fixture`) — aucune policy ni GRANT à toucher. Écrit à la main : `make
 * migration-diff` est inopérant tant que doctrine/dbal reste < 4.5 (backend.md).
 */
final class Version20260916120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fixture.kept_venue_label column (idempotence of keep-app on an unplaced home venue deviation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD kept_venue_label VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture DROP kept_venue_label');
    }
}
