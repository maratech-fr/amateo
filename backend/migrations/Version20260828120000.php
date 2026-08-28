<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-54 RMM-9 PR-3 — le radar de conflits devient SPATIAL :
 *   - `fixture.opponent_organisme_code` (VARCHAR(64) nullable) : la CLÉ DE JOINTURE
 *     d'une fixture AWAY vers l'annuaire adverse global ET le trajet tenant —
 *     stampée best-effort par OpponentLocationResolver quand il résout déjà ce code
 *     (aucune écriture au global, ce n'est qu'une clé) ;
 *   - `opponent_travel` : le temps de trajet du siège du club vers le lieu d'un
 *     adversaire, PAR CLUB ET SAISON (le trajet dépend du siège — donnée tenant,
 *     JAMAIS dans opponent_directory public), keyé sur le code organisme. Aller
 *     simple voiture nullable + source (AUTO|MANUAL, le MANUAL jamais écrasé) +
 *     surcharge de gymnase choisi à la main (ref/label/lat/long). STRUCTURE de
 *     club+saison (patron venue_travel_time), un couple (club, saison, code) = une
 *     ligne (unique).
 *
 * RLS : patron venue_travel_time (Version20260826130000) — GRANT + ENABLE/FORCE +
 * policy `tenant_isolation`, conditionné au rôle `app_user`. RlsIsolationTest
 * découvre la table automatiquement (elle porte club_id).
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal
 * reste < 4.5 (backend.md).
 */
final class Version20260828120000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P2-54 RMM-9 PR-3: fixture.opponent_organisme_code + opponent_travel (tenant, RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD opponent_organisme_code VARCHAR(64) DEFAULT NULL');

        $this->addSql('CREATE TABLE opponent_travel (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, opponent_organisme_code VARCHAR(64) NOT NULL, travel_minutes SMALLINT DEFAULT NULL, source VARCHAR(10) NOT NULL, override_venue_external_ref VARCHAR(64) DEFAULT NULL, override_venue_label VARCHAR(180) DEFAULT NULL, override_latitude DOUBLE PRECISION DEFAULT NULL, override_longitude DOUBLE PRECISION DEFAULT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_opponent_travel_code ON opponent_travel (club_id, season_id, opponent_organisme_code)');
        $this->addSql('CREATE INDEX idx_opponent_travel_club_season ON opponent_travel (club_id, season_id)');

        $hasAppUser = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if ($hasAppUser) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON opponent_travel TO app_user');
            $this->addSql('ALTER TABLE public.opponent_travel ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.opponent_travel FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.opponent_travel FOR ALL TO app_user USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin (§6) : le rôle propriétaire garde l'accès sous FORCE RLS, sinon un
        // PG managé sans BYPASSRLS le DENY. RlsIsolationTest exige exactement 1 admin_all.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.opponent_travel FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.opponent_travel');
        $this->addSql('DROP TABLE opponent_travel');
        $this->addSql('ALTER TABLE fixture DROP COLUMN IF EXISTS opponent_organisme_code');
    }
}
