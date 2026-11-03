<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Envoi des conflits aux coachs — `conflict_mailing` : la mémoire du DERNIER envoi d'un club pour
 * une saison (une ligne par (club, saison), unique). `sent_at` / `sent_by_user_id` (guid sans FK,
 * résolu à la lecture : un auteur disparu laisse une ligne « sans par ») / `recipient_coach_ids`
 * (json : les coachs qui détiennent encore une liste, à prévenir quand leur conflit disparaît) /
 * `skipped_coach_ids` (json : sans adresse à cet envoi) / `mailed_count` (mails enfilés, « plus
 * aucun conflit » compris). Écrite par upsert `ON CONFLICT (club_id, season_id) DO UPDATE`.
 *
 * Aucune FK (patron conflict_resolution) : purgée avec la saison par SeasonDataPurger.
 * RLS : patron Version20261002120000 — GRANT + ENABLE/FORCE + `tenant_isolation`, porte
 * `admin_all`, policy `readonly_tenant` du rôle lecture seule, posés à la création.
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 */
final class Version20261103140000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'conflict_mailing: memory of the last conflicts mailing to coaches (tenant, RLS + admin door + read-only policy).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE conflict_mailing (id UUID NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, sent_by_user_id UUID DEFAULT NULL, recipient_coach_ids JSON NOT NULL, skipped_coach_ids JSON NOT NULL, mailed_count INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_conflict_mailing_club_season ON conflict_mailing (club_id, season_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON conflict_mailing TO ' . $appRole);
            $this->addSql('ALTER TABLE public.conflict_mailing ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.conflict_mailing FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.conflict_mailing FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.conflict_mailing FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : aucun secret (identifiants de coachs, horodatage, compteur).
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.conflict_mailing TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.conflict_mailing FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS readonly_tenant ON public.conflict_mailing');
        $this->addSql('DROP POLICY IF EXISTS admin_all ON public.conflict_mailing');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.conflict_mailing');
        $this->addSql('DROP TABLE conflict_mailing');
    }
}
