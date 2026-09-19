<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * C7 — `opponent_directory.logo_id` : le logo FÉDÉRAL PUBLIC d'un adversaire (uuid `logo.id`
 * de son organisme), posé par `OpponentLocationResolver` depuis les hits organismes qu'il
 * tient déjà (zéro appel réseau supplémentaire). Table GLOBALE partagée (hors RLS) : donnée
 * fédérale publique seulement (revue sécurité, `OpponentDirectoryEntry` docblock) — gardée par
 * `OpponentDirectoryShareTest` (whitelist +1). Colonne nullable ; aucun DELETE, aucun RLS.
 *
 * Écrit à la main : `make migration-diff` inopérant tant que doctrine/dbal < 4.5 (backend.md).
 */
final class Version20260920130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'C7: opponent_directory.logo_id (logo fédéral public de l\'adversaire, re-hébergé paresseusement).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE opponent_directory ADD logo_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE opponent_directory DROP logo_id');
    }
}
