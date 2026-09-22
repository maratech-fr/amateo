<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « La génération relancée ne refait pas » — persistance de la GREFFE de convergence.
 *
 * On ajoute une colonne `payload_graft` (json, NULLABLE) sur `schedule`. Elle stocke le bloc
 * greffé APRÈS le hash de snapshot sur l'entrée du moteur (`previousAssignments` en régénération,
 * `socleReferenceAssignments` en comblement) — des clés DISJOINTES du snapshot qui n'entrent
 * JAMAIS dans `snapshotHash` (sinon il divergerait de `currentStructureHash`). L'entrée réelle du
 * solve = snapshot + greffe se relit ainsi telle quelle ({@see App\Entity\Schedule::engineInput()}),
 * sans la recalculer — impossible après coup, la version terminée devenant la dernière COMPLETED
 * de son plan.
 *
 * FORME retenue : une colonne scalaire json NULLABLE sur la MÊME ligne que `snapshot_data`, pas de
 * table ni de FK. NULL = aucune greffe (première génération : chemin byte-identique à l'historique).
 * Aucun index (jamais un critère de requête). La ligne est déjà purgée par les suppressions de
 * planning existantes — la colonne part avec elle, sans échappatoire.
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal reste < 4.5
 * (backend.md).
 */
final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'schedule.payload_graft (json, nullable) : fige la greffe de convergence (previousAssignments / socleReferenceAssignments) émise au moteur après le hash de snapshot, pour relire l\'entrée réelle du solve sans la recalculer.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule ADD payload_graft JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP payload_graft');
    }
}
