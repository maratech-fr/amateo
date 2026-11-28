<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 5, PR 6 : `schedule_plan.cold_changed_at`, l'instant de la dernière validation du planning de saison qui a
 * changé du froid. Une doléance reçue avant porte la mention « réponse donnée avant la modification du planning de
 * saison ». Nulle à la naissance : aucune validation passée n'est datée.
 *
 * Part dans la mise en production unique du lot conception, site fermé.
 */
final class Version20261206120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'schedule_plan.cold_changed_at : la dernière validation du socle qui a changé du froid.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_plan ADD cold_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_plan DROP COLUMN cold_changed_at');
    }
}
