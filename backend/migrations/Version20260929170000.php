<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-272 ③ — `match_constraint` : les RÈGLES DE MATCH propres au club (« écran
 * unique des contraintes de match », section Club). Patron de `constraint` SANS
 * sa table : scope (CLUB|TEAM|COACH — seul CLUB saisi ici), scope_target_id
 * nullable, rule_type (HARD|PREFERRED), days_of_week (JSON, ISO, plusieurs jours
 * par règle), kickoff_min/kickoff_max nullables (fourchette de coup d'envoi),
 * venue_id nullable (réservé ④). Table tenant + saison (patron TeamMatchHabit).
 *
 * ⚠ AUCUNE unicité : plusieurs lignes par cible/jour sont légitimes (⑤ en aura
 * besoin), deux règles peuvent se recouvrir sans être un doublon.
 *
 * RLS : patron Version20260928130000 — GRANT + ENABLE/FORCE + policy
 * `tenant_isolation` (rôle applicatif) + `admin_all` (rôle propriétaire, exigé
 * exactement une fois par table FORCE par RlsIsolationTest). RlsIsolationTest
 * découvre la table automatiquement (colonne club_id). Table neuve : l'ancienne
 * release ne la touche pas (rétro-compat deploy). `make migration-diff` inopérant
 * tant que doctrine/dbal < 4.5 → migration écrite à la main.
 */
final class Version20260929170000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P4-272 ③: match_constraint (règles de match par club, tenant + RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE match_constraint (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, scope VARCHAR(20) NOT NULL, scope_target_id UUID DEFAULT NULL, rule_type VARCHAR(20) NOT NULL, days_of_week JSON NOT NULL, kickoff_min TIME(0) WITHOUT TIME ZONE DEFAULT NULL, kickoff_max TIME(0) WITHOUT TIME ZONE DEFAULT NULL, venue_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_match_constraint_club_season ON match_constraint (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_match_constraint_scope ON match_constraint (scope, scope_target_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON match_constraint TO ' . $appRole);
            $this->addSql('ALTER TABLE public.match_constraint ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.match_constraint FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.match_constraint FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        // Porte admin (§6) : le rôle propriétaire garde l'accès sous FORCE RLS.
        // RlsIsolationTest exige exactement 1 admin_all par table FORCE.
        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.match_constraint FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS admin_all ON public.match_constraint');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation ON public.match_constraint');
        $this->addSql('DROP TABLE match_constraint');
    }
}
