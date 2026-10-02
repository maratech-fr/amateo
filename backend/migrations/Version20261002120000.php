<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-16 — la « boîte aux lettres » d'un club à horloge simulée : table tenant `club_mailbox_message`.
 *
 * Un club qui vit à une date simulée ne doit jamais envoyer de vrai e-mail (option A fondateur
 * 2026-10-02) — l'interception range chaque message ici. Append-only, fait daté.
 *
 * `ON DELETE CASCADE` sur `club_id` : la purge d'un prospect (club supprimé) emporte sa boîte
 * sans ménage applicatif. Même patron RLS que les autres tables club_id (Version20260803090000 +
 * la porte admin_all de Version20260929160000 + la policy readonly_tenant du rôle lecture seule
 * Version20260930090000), posé ICI à la création car aucune migration dynamique ne repassera sur
 * une table née après elles : `RlsIsolationTest` et `ReadOnlyRoleTest` l'exigent dès sa naissance.
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261002120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P4-16: club_mailbox_message (tenant, RLS + admin door + read-only policy, ON DELETE CASCADE on club).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE club_mailbox_message (id UUID NOT NULL, club_id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, simulated_date DATE NOT NULL, from_address VARCHAR(255) NOT NULL, to_address VARCHAR(1000) NOT NULL, subject VARCHAR(998) NOT NULL, body_text TEXT DEFAULT NULL, body_html TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_club_mailbox_message_club_created ON club_mailbox_message (club_id, created_at)');
        $this->addSql('ALTER TABLE club_mailbox_message ADD CONSTRAINT fk_club_mailbox_message_club FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON club_mailbox_message TO ' . $appRole);
            $this->addSql('ALTER TABLE public.club_mailbox_message ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.club_mailbox_message FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.club_mailbox_message FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        // Porte de supervision : sous FORCE RLS, un propriétaire non-superuser (provider managé)
        // serait DENY sans elle. RlsIsolationTest exige EXACTEMENT une policy admin_all par table FORCE.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.club_mailbox_message FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : SELECT table entière + policy readonly_tenant scopée au MÊME
        // canon (ReadOnlyRoleTest exige 1 policy readonly_tenant par table club_id). Aucun secret
        // dans cette table (adresses + corps d'e-mail déjà destinés au club).
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.club_mailbox_message TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.club_mailbox_message FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS readonly_tenant ON public.club_mailbox_message');
        $this->addSql('DROP POLICY IF EXISTS admin_all ON public.club_mailbox_message');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.club_mailbox_message');
        $this->addSql('DROP TABLE club_mailbox_message');
    }
}
