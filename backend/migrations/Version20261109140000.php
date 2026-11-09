<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Mise en prod programmée » — borne de recherche du run (`scheduled_deployment.run_lookup_after`).
 *
 * Posée par `app:deployment:tick` au dispatch : la date de création GitHub du dernier run
 * `workflow_dispatch` connu AVANT le dispatch. Le run suivi est le plus ancien créé strictement
 * après — jamais le run précédent, et sans dépendre de l'horloge du serveur. Interne au tick : la
 * console ne la lit pas.
 *
 * Aucun GRANT neuf : le GRANT de table couvre déjà la colonne ajoutée. Écrite à la main :
 * `make migration-diff` est inopérant tant que `doctrine/dbal` reste < 4.5.
 */
final class Version20261109140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scheduled deployment: add run_lookup_after (TIMESTAMPTZ NULL) — GitHub creation time of the last run before dispatch.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_deployment ADD run_lookup_after TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_deployment DROP run_lookup_after');
    }
}
