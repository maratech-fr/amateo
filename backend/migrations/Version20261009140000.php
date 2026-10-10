<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 4bis « créneau libre » — une réservation peut désormais cibler un créneau LIBRE nommé
 * (`label`) plutôt qu'une équipe (`team_id` devient NULLABLE). Un XOR team_id/label est gardé à
 * l'écriture (ReservationStateProcessor).
 *
 * L'index `uniq_reservation_case_team` (Version20261008100000, NULLS NOT DISTINCT) porte DÉJÀ
 * « un max par case » : deux créneaux libres (team_id NULL) de la même case/portée collisionnent
 * (NULL == NULL sous NULLS NOT DISTINCT) et la base rejette le doublon — aucun index à toucher.
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5). Aucun
 * backfill : les réservations existantes ont toutes un `team_id`, `label` reste NULL.
 */
final class Version20261009140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reservation: team_id nullable + label VARCHAR(40) nullable (créneau libre nommé, lot 4bis).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ALTER team_id DROP NOT NULL');
        $this->addSql('ALTER TABLE reservation ADD label VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP label');
        $this->addSql('ALTER TABLE reservation ALTER team_id SET NOT NULL');
    }
}
