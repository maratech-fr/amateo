<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RGPD PR-4 — journal d'audit APPEND-ONLY.
 *
 * RLS FORCE avec, pour app_user :
 * - SELECT club-scoped (un tenant ne voit que SES événements ; les événements
 *   globaux club_id IS NULL sont invisibles du runtime — console admin only) ;
 * - INSERT : club courant OU club_id NULL (événements globaux : register,
 *   login raté — émis sans GUC) ;
 * - AUCUNE policy UPDATE ni DELETE : l'append-only est tenu PAR LA DB — le
 *   rôle runtime ne peut pas réécrire l'histoire. La purge (12 mois) passe
 *   par la connexion admin (app:audit:purge).
 */
final class Version20260711200000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'RGPD: table audit_log append-only (RLS: SELECT club-scoped, INSERT club|NULL, ni UPDATE ni DELETE).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (
            id UUID NOT NULL,
            occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            actor_user_id UUID DEFAULT NULL,
            club_id UUID DEFAULT NULL,
            action VARCHAR(40) NOT NULL,
            entity_type VARCHAR(60) DEFAULT NULL,
            entity_id VARCHAR(36) DEFAULT NULL,
            details JSONB NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_audit_log_occurred_at ON audit_log (occurred_at)');
        $this->addSql('CREATE INDEX idx_audit_log_club_id ON audit_log (club_id)');

        // Rôle applicatif résolu (app_user hérité OU amateo_app après le rename) —
        // même patron que Version20260703120000.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('ALTER TABLE public.audit_log ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.audit_log FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf('CREATE POLICY audit_log_select ON public.audit_log FOR SELECT TO ' . $appRole . ' USING (%s)', self::TENANT_PREDICATE));
            $this->addSql(\sprintf('CREATE POLICY audit_log_insert ON public.audit_log FOR INSERT TO ' . $appRole . ' WITH CHECK (club_id IS NULL OR %s)', self::TENANT_PREDICATE));
        }
        // Volontairement AUCUNE policy UPDATE/DELETE → append-only au niveau DB.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_log');
    }
}
