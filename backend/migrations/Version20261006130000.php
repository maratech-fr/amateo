<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-312 — `coach_wish.wished_days` : jours SOUHAITÉS d'une doléance, purement INFORMATIFS.
 *
 * Symétrique de `unavailable_days` (JSON, jours ISO 1–7). Le solveur ne le lit pas ; le
 * gestionnaire arbitre. Un jour ne peut jamais être à la fois souhaité ET indisponible —
 * garde à la SAISIE (contrôleur public + processor), pas en base.
 *
 * AUCUNE policy ni GRANT à poser : `coach_wish` porte déjà `tenant_isolation FOR ALL`
 * (couvre toute colonne de la ligne) et un `GRANT SELECT` de table ENTIÈRE à `amateo_read`
 * (patron catalogue, Version20260930090000) — une colonne nouvelle en hérite sans action.
 * `wished_days` ne matche aucun motif de secret (ReadOnlyRoleTest).
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261006130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-312: coach_wish.wished_days (JSON, informative preferred days; covered by existing FOR ALL policy and table-wide read grant).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_wish ADD wished_days JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_wish DROP wished_days');
    }
}
