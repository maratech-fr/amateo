<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-299 — table tenant `club_invitation` : une invitation nominative à rejoindre un club.
 *
 * TENANT hybride, patron `coach_wish_token` (Version20260726100000 + Version20260804120000) :
 *  - SELECT HYBRIDE `club_invitation_read` — ouvert quand AUCUN contexte tenant n'est posé
 *    (la page publique lit le jeton AVANT de poser le GUC : c'est la ligne lue qui PORTE le
 *    club), scopé au canon dès qu'un GUC existe ;
 *  - INSERT/UPDATE/DELETE scopés au canon tenant ;
 *  - porte de supervision `admin_all` (amateo_owner) — exigée sur toute table FORCE ;
 *  - rôle lecture seule `amateo_read` : SELECT colonne par colonne SAUF `token_hash` (secret),
 *    + policy `readonly_tenant` scopée au canon (patrons Version20260930090000 et
 *    Version20261002120000 — posés ICI à la création, aucune migration ne repassera dessus).
 *
 * `ON DELETE CASCADE` sur `club_id` : la suppression d'un prospect (club supprimé) emporte ses
 * invitations ; l'effacement RGPD (qui GARDE la fiche club) les purge par clubId (ErasedClubPurger).
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261006120000 extends AbstractMigration
{
    /** Doit rester BYTE-identique au canon tenant_isolation (Version20260703120000). */
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    /** SELECT hybride : ouvert hors contexte tenant, étanche au tenant sinon (patron SEC-12). */
    private const string HYBRID_PREDICATE = 'NULLIF(current_setting(\'app.club_id\', true), \'\') IS NULL OR club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    /** Colonnes lisibles par amateo_read — tout SAUF le secret `token_hash`. */
    private const string READONLY_COLUMNS = 'id, club_id, email, role, expires_at, created_at, invited_by_user_id';

    public function getDescription(): string
    {
        return 'P4-299: club_invitation (tenant, hybrid SELECT RLS + admin door + read-only policy without token_hash, ON DELETE CASCADE on club).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE club_invitation (id UUID NOT NULL, club_id UUID NOT NULL, email VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, invited_by_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_club_invitation_club ON club_invitation (club_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_club_invitation_token ON club_invitation (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_club_invitation_club_email ON club_invitation (club_id, email)');
        $this->addSql('ALTER TABLE club_invitation ADD CONSTRAINT fk_club_invitation_club FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON club_invitation TO ' . $appRole);
            $this->addSql('ALTER TABLE public.club_invitation ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.club_invitation FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf('CREATE POLICY club_invitation_read ON public.club_invitation FOR SELECT TO ' . $appRole . ' USING (%s)', self::HYBRID_PREDICATE));
            $this->addSql(\sprintf('CREATE POLICY tenant_isolation_insert ON public.club_invitation FOR INSERT TO ' . $appRole . ' WITH CHECK (%s)', self::TENANT_PREDICATE));
            $this->addSql(\sprintf('CREATE POLICY tenant_isolation_update ON public.club_invitation FOR UPDATE TO ' . $appRole . ' USING (%s) WITH CHECK (%s)', self::TENANT_PREDICATE, self::TENANT_PREDICATE));
            $this->addSql(\sprintf('CREATE POLICY tenant_isolation_delete ON public.club_invitation FOR DELETE TO ' . $appRole . ' USING (%s)', self::TENANT_PREDICATE));
        }

        // Porte de supervision : sous FORCE RLS, un propriétaire non-superuser (provider managé)
        // serait DENY sans elle. RlsIsolationTest exige EXACTEMENT une policy admin_all par table FORCE.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.club_invitation FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : SELECT colonne par colonne (exclut le secret `token_hash`) +
        // policy readonly_tenant scopée au MÊME canon (ReadOnlyRoleTest exige 1 policy
        // readonly_tenant par table club_id, et aucune colonne secrète lisible).
        if ($hasRead) {
            $this->addSql(\sprintf('GRANT SELECT (%s) ON public.club_invitation TO amateo_read', self::READONLY_COLUMNS));
            $this->addSql(\sprintf('CREATE POLICY readonly_tenant ON public.club_invitation FOR SELECT TO amateo_read USING (%s)', self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS readonly_tenant ON public.club_invitation');
        $this->addSql('DROP POLICY IF EXISTS admin_all ON public.club_invitation');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_delete ON public.club_invitation');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_update ON public.club_invitation');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_insert ON public.club_invitation');
        $this->addSql('DROP POLICY IF EXISTS club_invitation_read ON public.club_invitation');
        $this->addSql('DROP TABLE club_invitation');
    }
}
