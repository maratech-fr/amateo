<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cockpit palier C: period_reminder_log — idempotence ledger of app:periods:remind
 * (one row per period×threshold email actually sent). GLOBAL table (no club_id,
 * no RLS) — keyed on the globally-unique calendar_entry_id, like
 * school_holiday_period. Explicit grant on the new table.
 */
final class Version20260705140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cockpit palier C: period_reminder_log (reminder idempotence ledger, no RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE period_reminder_log (id UUID NOT NULL, calendar_entry_id UUID NOT NULL, threshold SMALLINT NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_period_reminder ON period_reminder_log (calendar_entry_id, threshold)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON period_reminder_log TO ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE period_reminder_log');
    }
}
