<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier H — la TOLÉRANCE d'arrivée tardive du coach principal change de porteur :
 *   - `reservation` ET `schedule_slot_template` gagnent `coach_late_tolerance_minutes` SMALLINT
 *     NOT NULL DEFAULT 0 : la tolérance est désormais portée par la SÉANCE (0 = aucune, comportement
 *     historique pour toutes les lignes existantes) ;
 *   - `coach` PERD `acceptable_late_minutes` : ce levier était MORT (aucune UI, jamais lu par le
 *     moteur), remplacé par la tolérance de séance ci-dessus.
 *
 * Les trois tables sont déjà tenant-owned sous FORCE RLS (migrations de création) ; ajouter/retirer
 * une colonne ne touche ni les politiques ni les GRANT.
 *
 * Écrite à la main : `make migration-diff` est inopérant tant que `doctrine/dbal` reste < 4.5.
 */
final class Version20261025130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reservation + ScheduleSlotTemplate: add coach_late_tolerance_minutes smallint (default 0); Coach: drop dead acceptable_late_minutes (chantier H).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD coach_late_tolerance_minutes SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE schedule_slot_template ADD coach_late_tolerance_minutes SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE coach DROP acceptable_late_minutes');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach ADD acceptable_late_minutes SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule_slot_template DROP coach_late_tolerance_minutes');
        $this->addSql('ALTER TABLE reservation DROP coach_late_tolerance_minutes');
    }
}
