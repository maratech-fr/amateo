<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier F PR4 (RGPD rétention) — colonne `purged_at` sur `feedback` : marqueur posé par
 * `app:feedback:purge` quand le MESSAGE et le CONTEXTE d'un signalement traité sont détruits
 * (30 jours après « traité »). La LIGNE survit (stats QoS intactes) ; ce marqueur dit à la
 * console « contenu purgé » et rend la purge idempotente (une ligne déjà purgée — `purged_at`
 * non nul — n'est jamais ré-estampée).
 *
 * `feedback` est déjà sous FORCE RLS (migration de création) ; ajouter une colonne ne touche ni
 * les politiques ni les GRANT. Nullable DEFAULT NULL : les lignes existantes sont « non purgées ».
 *
 * Écrite à la main : `make migration-diff` est inopérant tant que `doctrine/dbal` reste < 4.5.
 */
final class Version20261024130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feedback: add purged_at timestamptz (nullable) — retention marker set when the message/context is purged.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback ADD purged_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback DROP purged_at');
    }
}
