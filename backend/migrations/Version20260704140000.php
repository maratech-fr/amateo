<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cockpit palier A: school_holiday_period — national school-holiday reference.
 * GLOBAL table (no club_id) → NO RLS (public reference data, like `sport`).
 * Seeded from a versioned JSON via app:school-holidays:seed.
 */
final class Version20260704140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cockpit palier A: school_holiday_period global reference table (no RLS, no club_id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE school_holiday_period (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, zone VARCHAR(1) NOT NULL, label VARCHAR(120) NOT NULL, holiday_type VARCHAR(20) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, school_year VARCHAR(9) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_school_holiday_zone_type_year ON school_holiday_period (zone, holiday_type, school_year)');
        $this->addSql('CREATE INDEX idx_school_holiday_zone_start ON school_holiday_period (zone, start_date)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            // Public reference: readable by app_user, no RLS policy (no club_id).
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON school_holiday_period TO ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE school_holiday_period');
    }
}
