<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Transition de saison P2-PR2: transition_reminder_log — idempotence ledger of
 * app:seasons:remind-transition (one row per season × threshold actually sent).
 * Keyed on the globally-unique season_id → no club_id, no RLS (same pattern as
 * period_reminder_log). GRANT to app_user only.
 */
final class Version20260707120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transition P2: transition_reminder_log (reminder idempotence ledger, no RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE transition_reminder_log (id UUID NOT NULL, season_id UUID NOT NULL, threshold SMALLINT NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_transition_reminder ON transition_reminder_log (season_id, threshold)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON transition_reminder_log TO ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transition_reminder_log');
    }
}
