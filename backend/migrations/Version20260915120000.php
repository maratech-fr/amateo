<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\ConflictFingerprinter;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-207 — `conflict_resolution` : le statut de TRAITEMENT qu'un gestionnaire pose
 * sur un conflit du radar, PAR CLUB ET SAISON, keyé sur l'empreinte STABLE du
 * conflit ({@see ConflictFingerprinter}). Statut (dérogation demandée
 * | réglé en interne | sans solution) + note libre facultative + auteur. « À
 * traiter » ne se stocke jamais : c'est le défaut (aucune ligne). Un couple
 * (club, saison, empreinte) = une ligne (unique).
 *
 * RLS : patron opponent_travel (Version20260828120000) — GRANT + ENABLE/FORCE +
 * policy `tenant_isolation`, conditionné au rôle app + porte admin `admin_all`.
 * RlsIsolationTest découvre la table automatiquement (elle porte club_id).
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal
 * reste < 4.5 (backend.md).
 */
final class Version20260915120000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P4-207: conflict_resolution (statut de traitement d\'un conflit, tenant, RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE conflict_resolution (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, fingerprint VARCHAR(255) NOT NULL, status VARCHAR(30) NOT NULL, note VARCHAR(500) DEFAULT NULL, updated_by UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_conflict_resolution_fingerprint ON conflict_resolution (club_id, season_id, fingerprint)');
        $this->addSql('CREATE INDEX idx_conflict_resolution_club_season ON conflict_resolution (club_id, season_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON conflict_resolution TO ' . $appRole);
            $this->addSql('ALTER TABLE public.conflict_resolution ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.conflict_resolution FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.conflict_resolution FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin (§6) : le rôle propriétaire garde l'accès sous FORCE RLS, sinon un
        // PG managé sans BYPASSRLS le DENY. RlsIsolationTest exige exactement 1 admin_all.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.conflict_resolution FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.conflict_resolution');
        $this->addSql('DROP TABLE conflict_resolution');
    }
}
