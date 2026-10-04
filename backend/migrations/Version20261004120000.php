<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `match_placement_run` — le statut persistant du placement automatique des matchs,
 * passé sur le rail ASYNCHRONE (patron de la génération). Une ligne par demande :
 * PENDING → RUNNING → COMPLETED/FAILED, avec le résultat (placés/non placés/diagnostics
 * /métriques) ou le message d'erreur en JSON.
 *
 * Tenant / RLS : patron club_travel_cache/opponent_travel — GRANT + ENABLE/FORCE +
 * policy `tenant_isolation` (rôle app, prédicat sur `app.club_id`) + porte admin
 * `admin_all` (amateo_owner). RlsIsolationTest découvre la table automatiquement
 * (elle porte club_id).
 *
 * Écrite à la main : `make migration-diff` est inopérant tant que doctrine/dbal
 * reste < 4.5 (backend.md).
 */
final class Version20261004120000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'match_placement_run (rail de placement asynchrone : statut + résultat du placement des matchs, tenant, RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE match_placement_run (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, club_id UUID NOT NULL, season_id UUID DEFAULT NULL, requested_by_user_id UUID NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, result_data JSON DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_match_placement_run_club_season ON match_placement_run (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_match_placement_run_created ON match_placement_run (club_id, created_at)');

        // ── RLS (patron) ──────────────────────────────────────────────────────────
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON match_placement_run TO ' . $appRole);
            $this->addSql('ALTER TABLE public.match_placement_run ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.match_placement_run FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.match_placement_run FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin : le rôle propriétaire garde l'accès sous FORCE RLS (RlsIsolationTest
        // exige exactement 1 admin_all par table FORCE).
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.match_placement_run FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
        // P5-20 — rôle de LECTURE SEULE `amateo_read` (créé par Version20260930090000) : toute
        // table club_id créée APRÈS le balayage doit se poser elle-même son GRANT SELECT + sa
        // policy `readonly_tenant` (sous FORCE RLS, un GRANT sans policy rend 0 ligne). Gardé par
        // ReadOnlyRoleTest.
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.match_placement_run TO amateo_read');
            $this->addSql(\sprintf('CREATE POLICY readonly_tenant ON public.match_placement_run FOR SELECT TO amateo_read USING (%s)', self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS readonly_tenant ON public.match_placement_run');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.match_placement_run');
        $this->addSql('DROP POLICY IF EXISTS admin_all ON public.match_placement_run');
        $this->addSql('DROP TABLE match_placement_run');
    }
}
