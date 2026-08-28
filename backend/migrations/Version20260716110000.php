<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716110000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'SA1: persist immutable solver generation metrics and club activity timestamp.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD last_activity_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_club_last_activity ON club (last_activity_at) WHERE last_activity_at IS NOT NULL');
        $this->addSql('CREATE TABLE solver_metrics (id UUID NOT NULL, schedule_id UUID NOT NULL, club_id UUID NOT NULL, status VARCHAR(30) NOT NULL, wall_time_ms INT DEFAULT NULL, nb_variables INT DEFAULT NULL, nb_constraints INT DEFAULT NULL, nb_conflicts INT DEFAULT NULL, score INT DEFAULT NULL, solver_version VARCHAR(80) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_solver_metrics_club_created ON solver_metrics (club_id, created_at)');
        $this->addSql('CREATE INDEX idx_solver_metrics_schedule ON solver_metrics (schedule_id)');
        $this->addSql('CREATE INDEX idx_solver_metrics_created_brin ON solver_metrics USING BRIN (created_at)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON solver_metrics TO ' . $appRole);
            $this->addSql('ALTER TABLE public.solver_metrics ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.solver_metrics FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf('CREATE POLICY tenant_isolation ON public.solver_metrics FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)', self::TENANT_PREDICATE, self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE solver_metrics');
        $this->addSql('DROP INDEX idx_club_last_activity');
        $this->addSql('ALTER TABLE club DROP last_activity_at');
    }
}
