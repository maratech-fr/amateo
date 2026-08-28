<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5-6 PR 1 — canal de signalement côté membre : table `feedback`.
 *
 * Tenant-owned (`club_id`) : FORCE ROW LEVEL SECURITY comme toute table club_id
 * (RlsIsolationTest la découvre dynamiquement et exige ENABLE+FORCE+policy). Deux
 * policies : `tenant_isolation` adossée au GUC app.club_id (prédicat canon,
 * identique à team_tag), et la porte `admin_all` TO amateo_owner — la migration
 * de provisioning des portes admin (Version20260813130000) a déjà tourné, elle ne
 * couvre pas une table créée après elle, donc cette table pose la sienne.
 */
final class Version20260813160000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P5-6: feedback table (member reports) + FORCE RLS (tenant_isolation + admin_all).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feedback (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, club_id UUID NOT NULL, user_id UUID NOT NULL, topic VARCHAR(40) NOT NULL, message TEXT NOT NULL, context JSON DEFAULT NULL, status VARCHAR(20) NOT NULL, treated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_feedback_club_created ON feedback (club_id, created_at)');
        $this->addSql('CREATE INDEX idx_feedback_status ON feedback (status)');

        // RLS: FORCE + policy tenant_isolation (GUC app.club_id) pour app_user, et la
        // porte admin_all pour le rôle propriétaire (supervision sous provider managé).
        $hasAppUser = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');
        if ($hasAppUser) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON feedback TO app_user');
            $this->addSql('ALTER TABLE public.feedback ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.feedback FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.feedback FOR ALL TO app_user USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.feedback FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte ses index, ses policies et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE feedback');
    }
}
