<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sécurité prod-reader (revue sécurité 2026-10-10, finding M3) — borne les requêtes
 * du rôle de LECTURE SEULE `amateo_read` (créé par Version20260930090000).
 *
 * Une enquête opérateur ne doit jamais pouvoir immobiliser la prod : une requête qui
 * part en séquentiel sur une grosse table, un `\d` qui attend un verrou pris par une
 * migration, ou une transaction laissée ouverte par un client graphique. On attache donc
 * au RÔLE (via `ALTER ROLE … SET`, cluster-level, appliqué à chaque connexion de
 * `amateo_read`) :
 *   - `statement_timeout = 60s`                      — toute requête est coupée à 60 s ;
 *   - `lock_timeout = 5s`                            — on n'attend pas un verrou plus de 5 s ;
 *   - `idle_in_transaction_session_timeout = 60s`    — une transaction oisive est tuée.
 * Le script `scripts/prod-read.sh` double `statement_timeout`/`lock_timeout` côté session
 * (PGOPTIONS) — défense en profondeur ; ce réglage-ci vaut même pour un client graphique.
 *
 * IDEMPOTENT et fail-safe comme la migration d'origine : on ne touche rien si le rôle
 * n'existe pas encore (cluster vierge où cette migration jouerait avant que l'opérateur
 * n'ait de rôle — en pratique Version20260930090000 l'a créé, mais on ne présume rien).
 * Gardé par `ReadOnlyRoleTest::testReadonlyRoleCarriesQueryAndLockTimeouts`.
 *
 * Runs on the ADMIN connection (amateo_owner). `ALTER ROLE … SET` ne porte aucun secret.
 */
final class Version20261010150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bound amateo_read queries: ALTER ROLE SET statement_timeout=60s, lock_timeout=5s, idle_in_transaction_session_timeout=60s (idempotent, role-scoped). Security review M3.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'amateo_read') THEN
                    ALTER ROLE amateo_read SET statement_timeout = '60s';
                    ALTER ROLE amateo_read SET lock_timeout = '5s';
                    ALTER ROLE amateo_read SET idle_in_transaction_session_timeout = '60s';
                END IF;
            END
            $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'amateo_read') THEN
                    ALTER ROLE amateo_read RESET statement_timeout;
                    ALTER ROLE amateo_read RESET lock_timeout;
                    ALTER ROLE amateo_read RESET idle_in_transaction_session_timeout;
                END IF;
            END
            $$;
            SQL);
    }
}
