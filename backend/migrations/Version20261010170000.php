<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot verrous V1bis — « Charger » pour les PÉRIODES.
 *
 * `schedule_plan` gagne `lock_source_schedule_id` : le POINTEUR vers la version dont les
 * verrous HARD sont la SOURCE des données « en cours » du plan (ce dont « Régénérer »
 * repart). Nullable (null = aucun pointeur posé → repli sur la dernière version COMPLETED,
 * maison unique `SchedulePlanProvisioner::lockSourceScheduleIdOfPlan`). FK vers `schedule(id)`
 * ON DELETE SET NULL : supprimer la version pointée relâche le pointeur sans casser le plan
 * (le repli reprend la main). Index pour le batch `isLockSource` du provider.
 *
 * Écrite À LA MAIN : `make migration-diff` reste inopérant tant que `doctrine/dbal` < 4.5
 * (backend.md). RLS : `schedule_plan` porte DÉJÀ sa policy tenant (ENABLE+FORCE ROW LEVEL
 * SECURITY, Version20260712150000) — on n'ajoute qu'une colonne, la policy par ligne
 * (sur `club_id`) la couvre sans changement.
 *
 * Gardé par `MigrationLockSourcePointerTest` (aller-retour DDL, FK SET NULL sur suppression).
 */
final class Version20261010170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot verrous V1bis : schedule_plan.lock_source_schedule_id (FK schedule ON DELETE SET NULL, nullable, indexée) — pointeur de la version source des verrous d\'un plan.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_plan ADD lock_source_schedule_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule_plan ADD CONSTRAINT fk_schedule_plan_lock_source FOREIGN KEY (lock_source_schedule_id) REFERENCES schedule (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_schedule_plan_lock_source ON schedule_plan (lock_source_schedule_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_plan DROP CONSTRAINT fk_schedule_plan_lock_source');
        $this->addSql('DROP INDEX idx_schedule_plan_lock_source');
        $this->addSql('ALTER TABLE schedule_plan DROP COLUMN lock_source_schedule_id');
    }
}
