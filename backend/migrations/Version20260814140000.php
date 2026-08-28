<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-28 PR 2 — règles implicites « bien-être » réglables : table `implicit_rule_setting`.
 *
 * Tenant-owned (`club_id`) : FORCE ROW LEVEL SECURITY comme toute table club_id
 * (RlsIsolationTest la découvre dynamiquement et exige ENABLE+FORCE+policy). Deux policies :
 * `tenant_isolation` adossée au GUC app.club_id (prédicat canon, identique à team_tag), et la
 * porte `admin_all` TO amateo_owner — le provisioning des portes admin a déjà tourné et ne
 * couvre pas une table créée après lui, donc cette table pose la sienne.
 *
 * ABSENCE DE LIGNE = DÉFAUT (HARD, seuils historiques) : aucun seed, unicité (club, saison, règle).
 *
 * Additif au diagnostic : `schedule_diagnostic.rule_key` (nullable) porte la règle du diagnostic
 * moteur `implicit_rule_not_honored` (patron exact de `day_of_week`/`start_time`, P4-95).
 */
final class Version20260814140000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P2-28: implicit_rule_setting table + FORCE RLS (tenant_isolation + admin_all); schedule_diagnostic +rule_key.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE implicit_rule_setting (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, rule_key VARCHAR(40) NOT NULL, intensity VARCHAR(20) NOT NULL, params JSON DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_implicit_rule_club_season_key ON implicit_rule_setting (club_id, season_id, rule_key)');
        $this->addSql('CREATE INDEX idx_implicit_rule_club_season ON implicit_rule_setting (club_id, season_id)');

        // RLS: FORCE + policy tenant_isolation (GUC app.club_id) pour app_user, et la porte
        // admin_all pour le rôle propriétaire (supervision sous provider managé).
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON implicit_rule_setting TO ' . $appRole);
            $this->addSql('ALTER TABLE public.implicit_rule_setting ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.implicit_rule_setting FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.implicit_rule_setting FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.implicit_rule_setting FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Diagnostic moteur `implicit_rule_not_honored` : la règle concernée (ruleKey du contrat).
        // NULL pour tout autre type de diagnostic — additif pur, aucune donnée existante touchée.
        $this->addSql('ALTER TABLE schedule_diagnostic ADD rule_key VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte ses index, ses policies et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE implicit_rule_setting');
        $this->addSql('ALTER TABLE schedule_diagnostic DROP COLUMN rule_key');
    }
}
