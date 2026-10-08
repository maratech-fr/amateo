<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-266 (2/2) — la péremption d'un planning ne vit plus dans deux drapeaux posés par des
 * listeners : elle se DÉRIVE de l'empreinte de structure servie par plan (ADR-0002,
 * `SchedulePlanProvisioner::structureHashOfPlan` ⇄ `snapshotHash` de la version). On retire donc
 * les colonnes `schedule.constraints_changed_since_generation` et
 * `schedule.resources_changed_since_generation` (le marqueur « retouché à la main »
 * `manually_edited_since_generation`, lui, RESTE — c'est un autre signal).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5). Le `down`
 * recrée les deux colonnes (BOOLEAN NOT NULL DEFAULT false) : la valeur historique n'est pas
 * reconstituable (perte d'info assumée — une re-génération les remettait à faux de toute façon).
 */
final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-266: drop schedule.constraints_changed_since_generation & resources_changed_since_generation (staleness now derived from the structure hash).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP COLUMN constraints_changed_since_generation');
        $this->addSql('ALTER TABLE schedule DROP COLUMN resources_changed_since_generation');
    }

    public function down(Schema $schema): void
    {
        // Recréation du schéma seul : la valeur d'avant le drop est perdue (perte d'info assumée).
        $this->addSql('ALTER TABLE schedule ADD constraints_changed_since_generation BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE schedule ADD resources_changed_since_generation BOOLEAN DEFAULT FALSE NOT NULL');
    }
}
