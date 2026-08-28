<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename the runtime application role app_user → amateo_app (the product owns its
 * name down to the Postgres role — second BDD lot, sibling of the clubscheduler →
 * amateo_owner owner rename).
 *
 * WHY a rename and not just a new name in initdb:
 *   - A FRESH cluster already gets the right name: docker/postgres/init/02-users.sh
 *     now creates amateo_app directly, and every frozen RLS migration resolves the
 *     application role by probing BOTH names (app_user OR amateo_app) and targets
 *     whichever exists. So on a fresh cluster this migration finds no app_user and
 *     is a NO-OP.
 *   - The founder's EXISTING cluster still carries the historical app_user with all
 *     its GRANTs and ~90 tenant_isolation policies bound to it. This migration
 *     renames it IN PLACE. A role rename is cluster-global and the policies follow
 *     the role OID automatically (proven on a throwaway cluster: {app_user} →
 *     {amateo_app} with no policy rewrite), so his data is preserved untouched — no
 *     drop, no re-provision.
 *
 * WHY the rename is legal here (unlike the owner role, which could NOT be renamed):
 *   the migrations run AS amateo_owner, NOT as app_user, so app_user is not the
 *   session user and Postgres renames it (the owner rename hit
 *   `ERROR: session user cannot be renamed`, hence its guard-literal amendment).
 *
 * Runs on the ADMIN connection (amateo_owner) — doctrine_migrations pins it, so the
 * rename is issued by a superuser/owner, insensitive to the (now broken) runtime DSN.
 * Ordering: this carries the LATEST timestamp so it runs AFTER every policy-creating
 * migration on a given database.
 *
 * IDEMPOTENT / order-safe: rename only when the legacy role exists AND the target
 * does not. A re-run (migrations table reset), or a second database migrated on the
 * same already-renamed cluster, both find no app_user (or an existing amateo_app)
 * and skip.
 *
 * ⚠ After this migration runs on an existing cluster, the application containers
 *    MUST be restarted so they pick up the amateo_app DSN — an open connection made
 *    with the old app_user DSN breaks the instant the role is renamed.
 */
final class Version20260828160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the runtime application role app_user → amateo_app (idempotent; policies follow the role OID). No-op on a fresh cluster already provisioned as amateo_app.';
    }

    public function up(Schema $schema): void
    {
        $hasLegacy = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');
        $hasTarget = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_app\'');

        if ($hasLegacy && !$hasTarget) {
            $this->addSql('ALTER ROLE app_user RENAME TO amateo_app');
        }
    }

    public function down(Schema $schema): void
    {
        // Symmetric reverse rename, same guard. On a cluster provisioned fresh as
        // amateo_app (where up() was a no-op) this DOES rename it back to app_user —
        // the frozen migrations still resolve it, but the runtime DSN would then need
        // app_user. down() is a manual rollback gesture; the operator restarts with
        // the matching DSN, exactly as for up().
        $hasTarget = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_app\'');
        $hasLegacy = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');

        if ($hasTarget && !$hasLegacy) {
            $this->addSql('ALTER ROLE amateo_app RENAME TO app_user');
        }
    }
}
