<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5-12 — journal de nouveautés + modale « quoi de neuf ».
 *
 * `release_note` : le changelog produit. GLOBAL (no club_id/season_id) → NO RLS,
 * only a GRANT to app_user (same pattern as public_holiday / league_match_window).
 * `published_at` null = brouillon. `note_date` est ÉDITORIALE (affichée, antidatable).
 *
 * `app_user` gagne `release_notes_seen_at` (nullable) : jusqu'où l'utilisateur a lu
 * le journal — la modale ne s'ouvre que sur une note publiée après cet instant.
 */
final class Version20260813150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P5-12 release notes: release_note global table (no RLS, GRANT only) + app_user.release_notes_seen_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE release_note (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, title VARCHAR(160) NOT NULL, body TEXT NOT NULL, note_date DATE NOT NULL, published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_release_note_published_at ON release_note (published_at)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            // Public product reference: readable/writable by app_user, no RLS policy (no club_id).
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON release_note TO ' . $appRole);
        }

        $this->addSql('ALTER TABLE app_user ADD release_notes_seen_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN release_notes_seen_at');
        $this->addSql('DROP TABLE release_note');
    }
}
