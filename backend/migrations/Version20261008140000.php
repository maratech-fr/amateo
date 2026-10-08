<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D1 (gabarit e-mail commun) — nom COURT facultatif du club : le libellé affiché dans les
 * e-mails envoyés aux coachs, plus lisible que la raison sociale fédérale. `club.short_name`
 * VARCHAR(20) nullable (null = repli sur le nom long, {@see App\Entity\Club::emailLabel}).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261008140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D1: add club.short_name (nullable varchar(20)) — email display label, falls back to the long name.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD short_name VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP COLUMN short_name');
    }
}
