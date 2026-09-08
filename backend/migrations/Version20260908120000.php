<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PR-3a — l'espace « Importer » : chaque rencontre dit si elle est NEW / déphasée
 * (OUT_OF_SYNC) / traitée (REVIEWED). Le TRAITEMENT et sa trace d'écarts vivent
 * désormais SUR la rencontre :
 *   - `fixture.review_state`      (NEW|OUT_OF_SYNC|REVIEWED, défaut NEW)
 *   - `fixture.reviewed_at`       (horodatage du dernier traitement, nullable)
 *   - `fixture.pending_deviations`(JSON, les écarts source⇄app encore ouverts).
 *
 * Backfill (D2) : une rencontre déjà placée (`status <> 'UNPLACED'`) est réputée
 * traitée → REVIEWED ; les autres restent NEW. `reviewed_at` reste NULL au
 * backfill (on ne fabrique pas d'horodatage rétroactif).
 *
 * D7 : la trace des écarts quittait le DÉPÔT → `fbi_ingestion.pending_deviations`
 * est supprimée (la fraîcheur et les compteurs restent).
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que
 * doctrine/dbal < 4.5). RLS sans objet : les migrations tournent sous
 * `amateo_owner` (BYPASSRLS).
 */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PR-3a: fixture review workflow (review_state, reviewed_at, pending_deviations) + drop fbi_ingestion.pending_deviations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD review_state VARCHAR(20) DEFAULT \'NEW\' NOT NULL');
        $this->addSql('ALTER TABLE fixture ADD reviewed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE fixture ADD pending_deviations JSON DEFAULT \'[]\' NOT NULL');

        // Backfill D2 : une rencontre déjà placée est réputée traitée.
        $this->addSql('UPDATE fixture SET review_state = \'REVIEWED\' WHERE status <> \'UNPLACED\'');

        $this->addSql('ALTER TABLE fbi_ingestion DROP pending_deviations');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fbi_ingestion ADD pending_deviations JSON DEFAULT \'[]\' NOT NULL');

        $this->addSql('ALTER TABLE fixture DROP pending_deviations');
        $this->addSql('ALTER TABLE fixture DROP reviewed_at');
        $this->addSql('ALTER TABLE fixture DROP review_state');
    }
}
