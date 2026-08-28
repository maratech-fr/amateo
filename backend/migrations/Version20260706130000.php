<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * public_holiday — national / territorial public-holiday reference (jours fériés,
 * etalab open data), fed by app:public-holidays:import. GLOBAL table (no club_id)
 * → NO RLS (public reference, like school_holiday_period). Column `holiday_date`
 * (not `date`, a reserved SQL word). Display-only — no solver input.
 */
final class Version20260706130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'public_holiday global reference table (jours fériés, no RLS, no club_id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public_holiday (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, zone VARCHAR(24) NOT NULL, holiday_date DATE NOT NULL, label VARCHAR(120) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_public_holiday_zone_date ON public_holiday (zone, holiday_date)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            // Public reference: readable/writable by app_user, no RLS policy (no club_id).
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON public_holiday TO ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public_holiday');
    }
}
