<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * planning-versions D2: schedule_structure_snapshot — faithful photo of the
 * club structure at each season-plan generation (one row per schedule),
 * enabling the D3 restore. RLS FORCE like every club_id table.
 */
final class Version20260710170000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'planning-versions D2: schedule_structure_snapshot table (+RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE schedule_structure_snapshot (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_id UUID NOT NULL, data JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_structure_snapshot_schedule ON schedule_structure_snapshot (schedule_id)');
        $this->addSql('CREATE INDEX idx_structure_snapshot_club_season ON schedule_structure_snapshot (club_id, season_id)');

        // RLS: FORCE + tenant_isolation policy keyed on the app.club_id GUC (every
        // club_id table inherits this pattern — RlsIsolationTest enforces it).
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON schedule_structure_snapshot TO ' . $appRole);
            $this->addSql('ALTER TABLE public.schedule_structure_snapshot ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.schedule_structure_snapshot FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.schedule_structure_snapshot FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE schedule_structure_snapshot');
    }
}
