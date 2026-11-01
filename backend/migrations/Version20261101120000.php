<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ouverture datée d'un gymnase pour les matchs : `venue_match_window` vise désormais un jour de la
 * semaine, les jours fériés OU une date précise (exactement l'un des trois, règle côté processor).
 *   - `opening_date` DATE NULL : la date d'une ouverture exceptionnelle ;
 *   - `label` VARCHAR(120) NULL : son motif, jamais envoyé au moteur.
 *
 * La table est déjà tenant-owned sous FORCE RLS (migration de création) ; ajouter une colonne ne
 * touche ni les politiques ni les GRANT.
 *
 * Écrite à la main (`.claude/rules/backend.md`, `make migration-diff` inopérant).
 */
final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'VenueMatchWindow: add opening_date and label (dated opening of a venue for matches).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_match_window ADD opening_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE venue_match_window ADD label VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Sans sa date, une ligne datée ne viserait plus rien : elle part avant la colonne.
        $this->addSql('DELETE FROM venue_match_window WHERE opening_date IS NOT NULL');
        $this->addSql('ALTER TABLE venue_match_window DROP label');
        $this->addSql('ALTER TABLE venue_match_window DROP opening_date');
    }
}
