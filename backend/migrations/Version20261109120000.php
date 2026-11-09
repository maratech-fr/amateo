<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * holiday_reminder_log — idempotence ledger of the school-holiday reminders of
 * app:periods:remind (one row per season × school holiday × threshold actually sent).
 * Keyed on the club-owned season_id → no club_id, no RLS (same pattern as
 * transition_reminder_log). GRANT to the application role only.
 */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'School-holiday reminders: holiday_reminder_log (reminder idempotence ledger, no RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE holiday_reminder_log (id UUID NOT NULL, season_id UUID NOT NULL, school_holiday_id UUID NOT NULL, threshold SMALLINT NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_holiday_reminder ON holiday_reminder_log (season_id, school_holiday_id, threshold)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON holiday_reminder_log TO ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE holiday_reminder_log');
    }
}
