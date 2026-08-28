<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SA3: persist restricted operational job execution history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_job_run (id UUID NOT NULL, job_key VARCHAR(80) NOT NULL, command_name VARCHAR(180) NOT NULL, source VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, duration_ms INT DEFAULT NULL, exit_code INT DEFAULT NULL, super_admin_id UUID DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT chk_admin_job_run_source CHECK (source IN (\'scheduled\', \'cli\', \'superadmin\')), CONSTRAINT chk_admin_job_run_status CHECK (status IN (\'running\', \'succeeded\', \'failed\', \'interrupted\'))) ');
        $this->addSql('CREATE INDEX idx_admin_job_run_job_started ON admin_job_run (job_key, started_at DESC)');
        $this->addSql('CREATE INDEX idx_admin_job_run_status ON admin_job_run (status)');
        // Rôle applicatif résolu (app_user hérité OU amateo_app) — patron Version20260703120000.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('REVOKE ALL ON admin_job_run FROM ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_job_run');
    }
}
