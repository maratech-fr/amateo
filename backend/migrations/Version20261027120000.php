<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PR2 « jours fériés » (Q1 option c, décision fondateur 2026-10-27) — la 8ᵉ ligne
 * « Jours fériés » de l'éditeur des accès match d'un gymnase :
 *   - `venue_match_window.day_of_week` devient NULLABLE (la ligne férié n'a pas de jour de
 *     semaine ; les lignes existantes gardent leur jour) ;
 *   - `venue_match_window` gagne `applies_on_public_holidays` BOOLEAN NOT NULL DEFAULT false :
 *     true = la fenêtre s'applique à tout jour férié officiel du territoire (table public_holiday),
 *     false = ligne de jour de semaine (toutes les lignes existantes). Exactement l'un des deux
 *     est posé (règle côté processor).
 *
 * La table est déjà tenant-owned sous FORCE RLS (migration de création) ; ajouter/assouplir une
 * colonne ne touche ni les politiques ni les GRANT.
 *
 * Écrite à la main (même idiome que les migrations récentes), `make migration-diff` n'étant pas
 * sollicité ici.
 */
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'VenueMatchWindow: day_of_week nullable + add applies_on_public_holidays boolean default false (PR2 « jours fériés »).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_match_window ALTER COLUMN day_of_week DROP NOT NULL');
        $this->addSql('ALTER TABLE venue_match_window ADD applies_on_public_holidays BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_match_window DROP applies_on_public_holidays');
        // Les lignes férié (day_of_week NULL) doivent partir avant de rétablir NOT NULL.
        $this->addSql('DELETE FROM venue_match_window WHERE day_of_week IS NULL');
        $this->addSql('ALTER TABLE venue_match_window ALTER COLUMN day_of_week SET NOT NULL');
    }
}
