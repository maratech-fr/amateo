<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Modes gymnase par période (feature #8).
 *
 * `venue_period_override` — réglage SPARSE par (plan, gymnase) : DISABLED (le gymnase ne
 * sert pas cette période) ou BLANK (grille vierge, le gestionnaire ressaisit). Pas de
 * ligne = « hériter », le défaut : la grille de la période est la copie du modèle de
 * saison faite à la naissance du plan.
 *
 * RLS FORCE comme toute table club_id (RlsIsolationTest la découvre dynamiquement).
 */
final class Version20260724140000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'venue period modes: venue_period_override (+RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE venue_period_override (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID NOT NULL, venue_id UUID NOT NULL, mode VARCHAR(16) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_venue_period_override ON venue_period_override (schedule_plan_id, venue_id)');
        $this->addSql('CREATE INDEX idx_venue_period_override_plan ON venue_period_override (schedule_plan_id)');

        // RLS: FORCE + policy tenant_isolation adossée au GUC app.club_id.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON venue_period_override TO ' . $appRole);
            $this->addSql('ALTER TABLE public.venue_period_override ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.venue_period_override FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.venue_period_override FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte ses index, sa policy et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE venue_period_override');
    }
}
