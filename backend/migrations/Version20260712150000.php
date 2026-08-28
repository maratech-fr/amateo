<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0002 Lot A — foundations of the SchedulePlan model (ADDITIVE).
 *
 * - Renames the billing catalogue table `plan` → `subscription_plan` (the name
 *   `Plan`/`plan` is freed for the domain; the entity is now SubscriptionPlan).
 * - Creates `schedule_plan` (the named container of a season/period's versions),
 *   RLS FORCE like every club_id table.
 * - Adds `schedule.schedule_plan_id` + `schedule.version_number` (nullable during
 *   the transition; made NOT NULL in Lot D).
 * - Backfills: one SEASON plan per season (from planning_name + baseline pointer),
 *   one CLOSURE/HOLIDAY plan per period entry that owns schedules (from its
 *   overlay pointer), then links every schedule and numbers its versions.
 *
 * Nothing legacy is touched: baseline_schedule_id, overlay_schedule_id and the
 * VALIDATED/ARCHIVED statuses keep making the decisions until Lot B.
 */
final class Version20260712150000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'ADR-0002 Lot A: schedule_plan (+RLS) + schedule.schedule_plan_id/version_number + backfill; billing plan → subscription_plan.';
    }

    public function up(Schema $schema): void
    {
        // Billing catalogue: free the `plan` name for the domain concept.
        $this->addSql('ALTER TABLE plan RENAME TO subscription_plan');

        // The SchedulePlan container.
        $this->addSql('CREATE TABLE schedule_plan (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, type VARCHAR(20) NOT NULL, name VARCHAR(180) NOT NULL, start_date TIMESTAMP(0) WITH TIME ZONE NOT NULL, end_date TIMESTAMP(0) WITH TIME ZONE NOT NULL, calendar_entry_id UUID DEFAULT NULL, chosen_schedule_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_schedule_plan_club_season ON schedule_plan (club_id, season_id)');
        // At most one SEASON plan per season, and at most one plan per period entry
        // (a period has one plan with many versions). Both partial so nulls don't clash.
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_plan_season_base ON schedule_plan (season_id) WHERE (type = \'SEASON\')');
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_plan_calendar_entry ON schedule_plan (calendar_entry_id) WHERE (calendar_entry_id IS NOT NULL)');

        // Schedule gains its plan link + stored version number.
        $this->addSql('ALTER TABLE schedule ADD schedule_plan_id UUID DEFAULT NULL, ADD version_number INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_plan_version ON schedule (schedule_plan_id, version_number) WHERE (schedule_plan_id IS NOT NULL AND version_number IS NOT NULL)');

        // Backfill — one SEASON plan per existing season.
        $this->addSql(
            'INSERT INTO schedule_plan (id, version, created_at, updated_at, club_id, season_id, type, name, start_date, end_date, calendar_entry_id, chosen_schedule_id) '
            . 'SELECT gen_random_uuid(), 1, now(), now(), s.club_id, s.id, \'SEASON\', '
            . 'COALESCE(NULLIF(s.planning_name, \'\'), \'Planning de la saison \' || s.name), '
            . 's.start_date, s.end_date, NULL, s.baseline_schedule_id '
            . 'FROM season s',
        );

        // One CLOSURE/HOLIDAY plan per period entry that owns at least one schedule.
        $this->addSql(
            'INSERT INTO schedule_plan (id, version, created_at, updated_at, club_id, season_id, type, name, start_date, end_date, calendar_entry_id, chosen_schedule_id) '
            . 'SELECT gen_random_uuid(), 1, now(), now(), ce.club_id, ce.season_id, '
            . 'CASE ce.period_type WHEN \'holiday\' THEN \'HOLIDAY\' ELSE \'CLOSURE\' END, '
            . 'ce.title, ce.start_date, ce.end_date, ce.id, ce.overlay_schedule_id '
            . 'FROM calendar_entry ce '
            . 'WHERE ce.period_type IN (\'closure\', \'holiday\') '
            . 'AND EXISTS (SELECT 1 FROM schedule sc WHERE sc.calendar_entry_id = ce.id)',
        );

        // Link season-plan schedules (no calendar entry) to their SEASON plan.
        $this->addSql(
            'UPDATE schedule sc SET schedule_plan_id = sp.id '
            . 'FROM schedule_plan sp '
            . 'WHERE sc.calendar_entry_id IS NULL AND sp.type = \'SEASON\' AND sp.season_id = sc.season_id',
        );
        // Link overlay schedules to their period plan.
        $this->addSql(
            'UPDATE schedule sc SET schedule_plan_id = sp.id '
            . 'FROM schedule_plan sp '
            . 'WHERE sc.calendar_entry_id IS NOT NULL AND sp.calendar_entry_id = sc.calendar_entry_id',
        );
        // Number each plan's versions in creation order (V1, V2…).
        $this->addSql(
            'UPDATE schedule sc SET version_number = ranked.rn '
            . 'FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY schedule_plan_id ORDER BY created_at, id) AS rn '
            . 'FROM schedule WHERE schedule_plan_id IS NOT NULL) ranked '
            . 'WHERE sc.id = ranked.id',
        );

        // RLS: FORCE + tenant_isolation policy keyed on the app.club_id GUC (every
        // club_id table inherits this pattern — RlsIsolationTest enforces it).
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON schedule_plan TO ' . $appRole);
            $this->addSql('ALTER TABLE public.schedule_plan ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.schedule_plan FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.schedule_plan FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_schedule_plan_version');
        $this->addSql('ALTER TABLE schedule DROP schedule_plan_id, DROP version_number');
        $this->addSql('DROP TABLE schedule_plan');
        $this->addSql('ALTER TABLE subscription_plan RENAME TO plan');
    }
}
