<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Alerting superadmin — état anti-spam des alertes (`admin_alert_state`) : une ligne
 * par check, transitions ok↔firing. Table d'exploitation sur la connexion admin
 * (pattern admin_job_run) : le rôle runtime app_user n'y a AUCUN privilège.
 */
final class Version20260718200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alerting: admin_alert_state (anti-spam des alertes superadmin, connexion admin).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_alert_state (check_key VARCHAR(80) NOT NULL, status VARCHAR(10) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_alerted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (check_key), CONSTRAINT chk_admin_alert_state_status CHECK (status IN (\'ok\', \'firing\')))');
        // Rôle applicatif résolu (app_user hérité OU amateo_app) — patron Version20260703120000.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('REVOKE ALL ON admin_alert_state FROM ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_alert_state');
    }
}
