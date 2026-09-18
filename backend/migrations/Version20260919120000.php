<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `fbi_correction` — le registre « à corriger dans FBI » : sur un (club, saison,
 * rencontre, champ), l'appli fait foi et FBI est en retard (le gestionnaire a
 * gardé l'appli). Une entrée OUVERTE (`closed_at IS NULL`) dit ce qu'il faut taper
 * dans FBI ; elle se ferme par un dépôt (`closed_by = deposit`) ou à la main
 * (`manual`). Unicité PARTIELLE sur les ouvertes seulement — un champ peut se
 * rouvrir plus tard.
 *
 * RLS : patron conflict_resolution (Version20260915120000) — GRANT + ENABLE/FORCE +
 * policy `tenant_isolation` (rôle app) + porte admin `admin_all`. RlsIsolationTest
 * découvre la table automatiquement (elle porte club_id).
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal
 * reste < 4.5 (backend.md).
 */
final class Version20260919120000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'fbi_correction (registre « à corriger dans FBI », tenant, RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fbi_correction (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, fixture_id UUID NOT NULL, field VARCHAR(20) NOT NULL, app_value VARCHAR(180) DEFAULT NULL, fbi_value VARCHAR(180) DEFAULT NULL, venue_fbi_label VARCHAR(180) DEFAULT NULL, decided_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, decided_by UUID NOT NULL, last_seen_in_fbi_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, closed_by VARCHAR(10) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_fbi_correction_open ON fbi_correction (club_id, season_id, fixture_id, field) WHERE (closed_at IS NULL)');
        $this->addSql('CREATE INDEX idx_fbi_correction_club_season ON fbi_correction (club_id, season_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON fbi_correction TO ' . $appRole);
            $this->addSql('ALTER TABLE public.fbi_correction ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.fbi_correction FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.fbi_correction FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin (§6) : le rôle propriétaire garde l'accès sous FORCE RLS, sinon un
        // PG managé sans BYPASSRLS le DENY. RlsIsolationTest exige exactement 1 admin_all.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.fbi_correction FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.fbi_correction');
        $this->addSql('DROP TABLE fbi_correction');
    }
}
