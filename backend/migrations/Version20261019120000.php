<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Mise en prod programmée » — note de DÉPART (`scheduled_deployment.dispatch_note`).
 *
 * Posée par `app:deployment:tick` quand le départ est retardé (une génération/un placement est en
 * cours) ou forcé passé le plafond d'attente ; rendue en console et reportée dans les e-mails.
 *
 * Aucun GRANT neuf : le GRANT de table (`release_note`/`public_holiday` patron) couvre déjà la
 * colonne ajoutée. Écrite à la main : `make migration-diff` est inopérant tant que `doctrine/dbal`
 * reste < 4.5.
 */
final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scheduled deployment: add dispatch_note (TEXT NULL) — the tick\'s departure note (wait/cap).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_deployment ADD dispatch_note TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_deployment DROP dispatch_note');
    }
}
