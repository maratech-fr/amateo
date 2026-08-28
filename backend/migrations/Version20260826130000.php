<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-53 RMM-8 PR-1 — la géo + le modèle de la matrice de trajet :
 *   - `venue.address` (VARCHAR nullable) : l'adresse saisie qu'on géocode (BAN) en
 *     lat/long déjà portées par le gymnase ;
 *   - `coach.is_vehicled` (BOOL NOT NULL DEFAULT false, prudent) : véhiculé → barème
 *     voiture, sinon barème à pied (consommé en PR-2) ;
 *   - `venue_travel_time` : deux minutes par couple de gymnases (voiture/à pied) avec
 *     leur source (AUTO|MANUAL), STRUCTURE de club+saison, symétrique (venue_a_id <
 *     venue_b_id normalisé côté app), un couple = une ligne (unique).
 *
 * RLS : patron team_link (Version20260803150000) — GRANT + ENABLE/FORCE + policy
 * `tenant_isolation`, conditionné au rôle `app_user`. RlsIsolationTest découvre la
 * table automatiquement (elle porte club_id).
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal reste
 * < 4.5 (backend.md).
 */
final class Version20260826130000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P2-53 RMM-8 PR-1: venue.address + coach.is_vehicled + venue_travel_time (tenant, RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue ADD address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE coach ADD is_vehicled BOOLEAN DEFAULT false NOT NULL');

        $this->addSql('CREATE TABLE venue_travel_time (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_a_id UUID NOT NULL, venue_b_id UUID NOT NULL, driving_minutes SMALLINT DEFAULT NULL, walking_minutes SMALLINT DEFAULT NULL, driving_source VARCHAR(10) DEFAULT NULL, walking_source VARCHAR(10) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_venue_travel_time_couple ON venue_travel_time (club_id, season_id, venue_a_id, venue_b_id)');
        $this->addSql('CREATE INDEX idx_venue_travel_time_club_season ON venue_travel_time (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_venue_travel_time_venue_a ON venue_travel_time (venue_a_id)');
        $this->addSql('CREATE INDEX idx_venue_travel_time_venue_b ON venue_travel_time (venue_b_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON venue_travel_time TO ' . $appRole);
            $this->addSql('ALTER TABLE public.venue_travel_time ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.venue_travel_time FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.venue_travel_time FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin (§6) : le rôle propriétaire garde l'accès sous FORCE RLS, sinon un
        // PG managé sans BYPASSRLS le DENY. RlsIsolationTest exige exactement 1 admin_all.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.venue_travel_time FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.venue_travel_time');
        $this->addSql('DROP TABLE venue_travel_time');
        $this->addSql('ALTER TABLE coach DROP COLUMN IF EXISTS is_vehicled');
        $this->addSql('ALTER TABLE venue DROP COLUMN IF EXISTS address');
    }
}
