<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RMM-4 « réconciliation FBI » — table `fbi_ingestion` : chaque dépôt du xlsx FBI
 * est une ingestion datée (fraîcheur + trace de réconciliation). Écrite à la main :
 * doctrine/dbal 4.4.4 ne génère plus le diff (backend.md), et le patron RLS d'une
 * table tenant standard suit `match_module_visit` (RMM-3) / team_tag.
 *
 * Tenant-owned (`club_id`) : FORCE ROW LEVEL SECURITY comme toute table club_id
 * (RlsIsolationTest la découvre dynamiquement et exige ENABLE+FORCE+policy). Deux
 * policies : `tenant_isolation` adossée au GUC app.club_id (prédicat CANON, à
 * l'identique de match_module_visit/team_tag), et la porte `admin_all` TO
 * amateo_owner — les migrations de provisioning des portes admin ont déjà tourné
 * et ne couvrent pas une table créée après elles, donc cette table pose la sienne.
 * Table STANDARD : PAS le prédicat SELECT hybride de club_user/coach_wish_token.
 *
 * PAS de user_id : c'est le dépôt du CLUB, pas la visite d'un membre (contraste
 * assumé avec match_module_visit).
 */
final class Version20260824130000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'RMM-4: fbi_ingestion table (dated FBI xlsx deposit + reconciliation trace) + FORCE RLS (tenant_isolation + admin_all).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fbi_ingestion (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, deposited_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, source VARCHAR(16) NOT NULL, created INT DEFAULT 0 NOT NULL, updated INT DEFAULT 0 NOT NULL, unchanged INT DEFAULT 0 NOT NULL, deviations_count INT DEFAULT 0 NOT NULL, pending_deviations JSON DEFAULT \'[]\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_fbi_ingestion_club_season ON fbi_ingestion (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_fbi_ingestion_deposited ON fbi_ingestion (club_id, season_id, deposited_at)');

        // RLS: FORCE + policy tenant_isolation (GUC app.club_id) pour app_user, et la
        // porte admin_all pour le rôle propriétaire (supervision sous provider managé).
        $hasAppUser = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');
        if ($hasAppUser) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON fbi_ingestion TO app_user');
            $this->addSql('ALTER TABLE public.fbi_ingestion ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.fbi_ingestion FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.fbi_ingestion FOR ALL TO app_user USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.fbi_ingestion FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte ses index, ses policies et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE fbi_ingestion');
    }
}
