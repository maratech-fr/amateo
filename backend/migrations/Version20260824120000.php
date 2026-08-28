<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RMM-3 « le gardien à l'ouverture » — table `match_module_visit` : l'instantané
 * de « ta dernière visite » du module matchs, PAR UTILISATEUR (club_id + season_id
 * + user_id, unique). Écrite à la main : doctrine/dbal 4.4.4 ne génère plus le diff
 * (backend.md), et le patron RLS d'une table tenant standard suit team_tag.
 *
 * Tenant-owned (`club_id`) : FORCE ROW LEVEL SECURITY comme toute table club_id
 * (RlsIsolationTest la découvre dynamiquement et exige ENABLE+FORCE+policy). Deux
 * policies : `tenant_isolation` adossée au GUC app.club_id (prédicat CANON, à
 * l'identique de team_tag/feedback), et la porte `admin_all` TO amateo_owner — les
 * migrations de provisioning des portes admin ont déjà tourné et ne couvrent pas
 * une table créée après elles, donc cette table pose la sienne. Table STANDARD :
 * PAS le prédicat SELECT hybride de club_user/coach_wish_token (qui n'a de sens
 * qu'en amont de la pose du GUC).
 */
final class Version20260824120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'RMM-3: match_module_visit table (per-user match-module visit snapshot) + FORCE RLS (tenant_isolation + admin_all).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE match_module_visit (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, user_id UUID NOT NULL, reference_snapshot JSON NOT NULL, reference_taken_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_opened_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_match_module_visit_scope ON match_module_visit (club_id, season_id, user_id)');

        // RLS: FORCE + policy tenant_isolation (GUC app.club_id) pour app_user, et la
        // porte admin_all pour le rôle propriétaire (supervision sous provider managé).
        $hasAppUser = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');
        if ($hasAppUser) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON match_module_visit TO app_user');
            $this->addSql('ALTER TABLE public.match_module_visit ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.match_module_visit FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.match_module_visit FOR ALL TO app_user USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.match_module_visit FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte son index, ses policies et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE match_module_visit');
    }
}
