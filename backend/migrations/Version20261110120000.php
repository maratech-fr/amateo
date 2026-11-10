<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Envoi des conflits aux coachs — `conflict_mail_receipt` : la mémoire PAR CONFLIT. Une ligne par
 * (club, saison, empreinte de conflit, coach) : l'empreinte du CONTENU affiché par la tuile du mail
 * (`content_digest`, sha1) et l'instant de l'envoi (`sent_at`). Sert à dire « nouveau / modifié /
 * déjà envoyé » avant un nouvel envoi. Aucune donnée personnelle (identifiants, empreintes, dates).
 *
 * FK `coach_id → coach (id) ON DELETE CASCADE` (patron `fk_coach_wish_token_coach`,
 * Version20260726100000) : un coach supprimé emporte ses reçus, cascade exécutée en owner, hors
 * RLS. Purgée avec la saison par SeasonDataPurger.
 * RLS : patron Version20261103140000 — GRANT + ENABLE/FORCE + `tenant_isolation`, porte
 * `admin_all`, policy `readonly_tenant` du rôle lecture seule, posés à la création.
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261110120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'conflict_mail_receipt: per-conflict memory of the conflicts mailing to coaches (tenant, RLS + admin door + read-only policy, FK cascade on coach).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE conflict_mail_receipt (id UUID NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, fingerprint VARCHAR(255) NOT NULL, coach_id UUID NOT NULL, content_digest VARCHAR(40) NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_conflict_mail_receipt ON conflict_mail_receipt (club_id, season_id, fingerprint, coach_id)');
        $this->addSql('CREATE INDEX idx_conflict_mail_receipt_coach ON conflict_mail_receipt (club_id, season_id, coach_id)');
        $this->addSql('ALTER TABLE conflict_mail_receipt ADD CONSTRAINT fk_conflict_mail_receipt_coach FOREIGN KEY (coach_id) REFERENCES coach (id) ON DELETE CASCADE');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON conflict_mail_receipt TO ' . $appRole);
            $this->addSql('ALTER TABLE public.conflict_mail_receipt ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.conflict_mail_receipt FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.conflict_mail_receipt FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.conflict_mail_receipt FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : aucun secret (identifiants, empreintes, horodatage).
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.conflict_mail_receipt TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.conflict_mail_receipt FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE conflict_mail_receipt');
    }
}
