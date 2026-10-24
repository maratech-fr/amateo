<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier F PR3 — colonne `has_screenshot` sur `feedback` : pointeur LÉGER vers la capture
 * d'écran jointe à un signalement. Le blob lui-même vit HORS table (FeedbackScreenshotStorage,
 * patron LogoStorage) ; cette colonne dit seulement à la console superadmin s'il faut afficher
 * l'aperçu — jamais le binaire en base.
 *
 * `feedback` est déjà sous FORCE RLS (migration de création) ; ajouter une colonne ne touche ni
 * les politiques ni les GRANT. DEFAULT FALSE : les lignes existantes deviennent « sans capture ».
 *
 * Écrite à la main : `make migration-diff` est inopérant tant que `doctrine/dbal` reste < 4.5.
 */
final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feedback: add has_screenshot boolean flag (default false) — light pointer to the out-of-table screenshot blob.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback ADD has_screenshot BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback DROP has_screenshot');
    }
}
