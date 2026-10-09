<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Demandes de mutualisation des coachs (feature #10, lot D2).
 *
 * `coach_wish_mutualization` — une demande par (période mère de vacances, équipe) : équipes
 * partenaires pressenties + nombre de séances à mutualiser, coche « traité ». Jamais une
 * contrainte (aucun effet solveur). Ancrée à l'entrée MÈRE (`calendar_entry_id`) — parité
 * CoachWish : elle survit à la suppression de la campagne.
 *
 * TENANT, patron des tables club_id POSTÉRIEURES aux retrofits (Version20260813130000 admin_all,
 * Version20260930090000 readonly_tenant — qui ne repasseront pas sur une table née après eux) :
 *  - FORCE ROW LEVEL SECURITY + policy `tenant_isolation` FOR ALL adossée au GUC app.club_id
 *    (prédicat canon, byte-identique à coach_wish / team_tag — RlsIsolationTest le découvre
 *    dynamiquement et exige qu'il soit scopé) ;
 *  - porte de supervision `admin_all` TO amateo_owner (RlsIsolationTest exige EXACTEMENT une
 *    policy admin_all par table FORCE) ;
 *  - rôle lecture seule `amateo_read` : SELECT table entière (aucune colonne secrète) + policy
 *    `readonly_tenant` scopée au même canon (ReadOnlyRoleTest exige 1 policy readonly_tenant
 *    par table club_id).
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261009120000 extends AbstractMigration
{
    /** Doit rester BYTE-identique au canon tenant_isolation (Version20260703120000). */
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'coach wish mutualizations: coach_wish_mutualization (tenant FORCE RLS: tenant_isolation + admin_all + read-only policy).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE coach_wish_mutualization (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, calendar_entry_id UUID NOT NULL, team_id UUID NOT NULL, coach_id UUID DEFAULT NULL, partner_team_ids JSON NOT NULL, shared_slots SMALLINT NOT NULL, done BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_coach_wish_mutualization ON coach_wish_mutualization (calendar_entry_id, team_id)');
        $this->addSql('CREATE INDEX idx_coach_wish_mutualization_entry ON coach_wish_mutualization (calendar_entry_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON coach_wish_mutualization TO ' . $appRole);
            $this->addSql('ALTER TABLE public.coach_wish_mutualization ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.coach_wish_mutualization FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.coach_wish_mutualization FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        // Porte de supervision : sous FORCE RLS, un propriétaire non-superuser (provider managé)
        // serait DENY sans elle. RlsIsolationTest exige EXACTEMENT une policy admin_all par table FORCE.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.coach_wish_mutualization FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : SELECT table entière (aucune colonne secrète) + policy
        // readonly_tenant scopée au MÊME canon (ReadOnlyRoleTest exige 1 policy par table club_id).
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.coach_wish_mutualization TO amateo_read');
            $this->addSql(\sprintf('CREATE POLICY readonly_tenant ON public.coach_wish_mutualization FOR SELECT TO amateo_read USING (%s)', self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte ses index, ses policies et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE coach_wish_mutualization');
    }
}
