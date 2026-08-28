<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1-4 PR B — la couche CAPACITÉ du module matchs, deux tables tenant + RLS.
 *
 * `venue_match_window` : fenêtres d'accès MATCH par gymnase (jour + plage) — la
 * mairie n'ouvre pas les mêmes salles le week-end qu'en semaine (cadrage §5.1).
 * Un gymnase avec ≥ 1 fenêtre EST un gymnase de match (flag dérivé, pas de
 * booléen sur venue). Recopiées à la bascule de saison.
 *
 * `venue_unavailability` : indisponibilité TOUTES circonstances (plage de dates
 * incluses + motif) — alerte les matchs placés ET l'entraînement, ne bloque
 * rien (cadrage §5.2). Non recopiée (fait daté, one-shot).
 *
 * RLS : même patron que Version20260706240000 (GRANT + ENABLE/FORCE + policy
 * `tenant_isolation` sur le GUC app.club_id), conditionné à l'existence du rôle
 * `app_user`. `RlsIsolationTest` découvre les nouvelles tables automatiquement.
 * Rétro-compat deploy : tables neuves, l'ancienne release ne les touche pas.
 */
final class Version20260803090000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'Match P1-4 PR B: venue_match_window + venue_unavailability (tenant, RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE venue_match_window (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE NOT NULL, end_time TIME(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_venue_match_window_club_season ON venue_match_window (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_venue_match_window_venue ON venue_match_window (venue_id)');

        $this->addSql('CREATE TABLE venue_unavailability (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, label VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_venue_unavailability_club_season ON venue_unavailability (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_venue_unavailability_venue ON venue_unavailability (venue_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            foreach (['venue_match_window', 'venue_unavailability'] as $table) {
                $this->addSql(\sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO ' . $appRole, $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s ENABLE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s FORCE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf(
                    'CREATE POLICY tenant_isolation ON public.%s FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                    $table,
                    self::TENANT_PREDICATE,
                    self::TENANT_PREDICATE,
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.venue_unavailability');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.venue_match_window');
        $this->addSql('DROP TABLE venue_unavailability');
        $this->addSql('DROP TABLE venue_match_window');
    }
}
