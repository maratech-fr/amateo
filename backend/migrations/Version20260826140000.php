<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-53 RMM-8 PR-4 — le levier d'intensité de la règle « Trajet entre gymnases ».
 *
 * `venue_travel_rule_setting` : UN réglage par club+saison (singleton, unique), l'intensité
 * PREFERRED|MANDATORY de la règle de trajet. Vocabulaire des passerelles (TeamLinkIntensity),
 * PAS bien-être — d'où un store dédié plutôt qu'une 6ᵉ clé dans `implicit_rule_setting`.
 * Absence de ligne = défaut PREFERRED (résolu applicativement) : aucun seeding.
 *
 * RLS : patron Version20260803150000 (team_link) — GRANT + ENABLE/FORCE + policy
 * `tenant_isolation`, conditionné au rôle `app_user`. RlsIsolationTest découvre la table
 * automatiquement. Rétro-compat deploy : table neuve, l'ancienne release ne la touche pas.
 */
final class Version20260826140000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P2-53 RMM-8 PR-4: venue_travel_rule_setting (levier PREFERRED/MANDATORY, tenant, RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE venue_travel_rule_setting (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, intensity VARCHAR(20) DEFAULT \'PREFERRED\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_venue_travel_rule_club_season ON venue_travel_rule_setting (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_venue_travel_rule_club_season ON venue_travel_rule_setting (club_id, season_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON venue_travel_rule_setting TO ' . $appRole);
            $this->addSql('ALTER TABLE public.venue_travel_rule_setting ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.venue_travel_rule_setting FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.venue_travel_rule_setting FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin (§6) : le rôle propriétaire garde l'accès sous FORCE RLS, sinon un PG managé
        // sans BYPASSRLS le DENY. RlsIsolationTest exige exactement 1 admin_all.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.venue_travel_rule_setting FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.venue_travel_rule_setting');
        $this->addSql('DROP TABLE venue_travel_rule_setting');
    }
}
