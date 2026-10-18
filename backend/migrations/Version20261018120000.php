<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Mise en prod programmée » — table `scheduled_deployment`.
 *
 * GLOBALE (no club_id/season_id) → NO RLS, seulement un GRANT à `amateo_app` (même patron que
 * `release_note` / `public_holiday`). La console superadmin l'écrit (connexion owner), le rail
 * runtime la lit (bandeau d'annonce) et `app:deployment:tick` la fait avancer — toutes deux via
 * `amateo_app`, d'où le GRANT complet.
 *
 * Un seul déploiement ACTIF (`scheduled`|`dispatched`) à la fois : index unique PARTIEL — pour
 * les lignes actives, l'expression indexée vaut toujours TRUE, donc au plus une.
 *
 * Écrite à la main : `make migration-diff` est inopérant tant que `doctrine/dbal` reste < 4.5.
 */
final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scheduled deployment: scheduled_deployment global table (no RLS, GRANT only) + partial unique index on the active status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE scheduled_deployment (
                id UUID NOT NULL,
                version VARCHAR(64) NOT NULL,
                scheduled_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expected_return_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_by UUID NOT NULL,
                dispatched_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                github_run_id BIGINT DEFAULT NULL,
                failure_reason TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_scheduled_deployment_status CHECK (status IN ('scheduled', 'dispatched', 'succeeded', 'failed', 'cancelled'))
            )
            SQL);

        // Un seul déploiement actif à la fois. L'expression `status IN (...)` vaut TRUE pour
        // toutes les lignes actives → unique sur TRUE = au plus une ligne active.
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_scheduled_deployment_active'
            . ' ON scheduled_deployment ((status IN (\'scheduled\', \'dispatched\')))'
            . ' WHERE status IN (\'scheduled\', \'dispatched\')',
        );

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            // Référence GLOBALE (pas de club_id) : lisible/écrivable par le rôle runtime, aucune
            // politique RLS. La console (owner) programme ; le runtime lit le bandeau et le tick
            // met à jour l'état.
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON scheduled_deployment TO ' . $appRole);
        }

        // Table globale NON sensible (ni secret, ni PII) : lisible par le rôle d'exploration
        // lecture seule, comme `release_note`/`public_holiday`. Le GRANT vit ICI (pas dans la
        // migration du rôle, qui précède la table) et reste gardé par ReadOnlyRoleTest (liste
        // blanche globale). Guardé : `amateo_read` n'existe pas dans tous les environnements.
        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'')) {
            $this->addSql('GRANT SELECT ON scheduled_deployment TO amateo_read');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduled_deployment');
    }
}
