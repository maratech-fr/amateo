<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Doléances : `coach_wish.staff_unavailability`, les jours d'absence PAR PERSONNE du staff.
 * `unavailable_days` garde son nom et devient « les jours où l'équipe ne s'entraîne pas ».
 *
 * Déplacement de données : un jour saisi par un coach était SON absence, il part sur sa ligne ; sans
 * coach, il reste une fermeture d'équipe. Chaque déplacement est un `addSql` commençant par `UPDATE`
 * (contrat de CoachWishStaffUnavailabilityMigrationTest).
 *
 * ⚠ `down` NON SYMÉTRIQUE, assumé : seule une ligne à UNE entrée, celle du coach de la doléance,
 * retrouve ses jours. Une doléance à plusieurs personnes perd ses absences : l'ancien modèle n'a
 * pas de place pour elles, et les rabattre en fermeture d'équipe inventerait une règle.
 *
 * Écrite à la main (`make migration-diff` inopérant, .claude/rules/backend.md). RLS sans objet : la
 * table l'a déjà, les migrations tournent sous `amateo_owner` (BYPASSRLS).
 */
final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coach_wish.staff_unavailability: per-person absences; a coach\'s unavailable days move to his own line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_wish ADD staff_unavailability JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('UPDATE coach_wish SET staff_unavailability = json_build_array(json_build_object(\'coachId\', coach_id::text, \'days\', unavailable_days)), unavailable_days = \'[]\' WHERE coach_id IS NOT NULL AND unavailable_days::text <> \'[]\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE coach_wish SET unavailable_days = staff_unavailability->0->\'days\' WHERE json_array_length(staff_unavailability) = 1 AND staff_unavailability->0->>\'coachId\' = coach_id::text');
        $this->addSql('ALTER TABLE coach_wish DROP staff_unavailability');
    }
}
