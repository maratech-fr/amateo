<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5-20 — rôle PostgreSQL de LECTURE SEULE (`amateo_read`) pour l'exploration
 * opérateur courante depuis un poste, sans jamais pouvoir écrire ni tout voir.
 *
 * Runs on the ADMIN connection (amateo_owner). Trois parties :
 *  1. Rôle `amateo_read` créé de façon IDEMPOTENTE (DO-block sur pg_roles) :
 *     LOGIN mais SANS mot de passe — il ne peut donc pas se connecter tant que
 *     l'opérateur n'en pose pas un LE JOUR J (`ALTER ROLE amateo_read WITH
 *     PASSWORD '…'`, jamais dans .env* ni dans une migration commitée : un mot de
 *     passe dans git serait public à jamais). NOSUPERUSER NOCREATEDB NOCREATEROLE.
 *     Contrairement à amateo_app / amateo_owner (provisionnés hors git, initdb
 *     02-users.sh, PARCE QU'ils portent un secret), amateo_read n'a pas de secret :
 *     il peut donc naître dans une migration.
 *  2. Droits en LECTURE seule : USAGE sur le schéma + SELECT sur toutes les tables
 *     existantes + ALTER DEFAULT PRIVILEGES (pour le rôle propriétaire des
 *     migrations, amateo_owner) SELECT sur les tables FUTURES. Aucun INSERT /
 *     UPDATE / DELETE : une écriture est refusée au niveau PRIVILÈGE, pas seulement
 *     par RLS — c'est la garantie primaire du rôle.
 *  3. Sous FORCE ROW LEVEL SECURITY, un GRANT SELECT ne suffit PAS : sans policy
 *     pour amateo_read, chaque table tenant rend 0 ligne (default-deny). On pose
 *     donc, sur CHAQUE table portant une colonne club_id (énumérée dynamiquement
 *     depuis le catalogue — patron Version20260813130000, robuste aux tables
 *     ajoutées après), une policy `readonly_tenant FOR SELECT TO amateo_read`
 *     scopée sur le MÊME prédicat canonique que tenant_isolation (RlsIsolationTest
 *     compare par égalité stricte au canon runtime : réécrire le prédicat le ferait
 *     rougir, et sans NULLIF la chaîne vide posée par clear() partirait en
 *     ''::uuid → 22P02). AUCUNE policy admin_all pour amateo_read : il reste scopé
 *     comme amateo_app, jamais de USING(true) qui lui ouvrirait le cross-club.
 *     Les tables GLOBALES (sans club_id : opponent_*, league_match_window,
 *     school_holiday_period…) ne sont pas sous RLS → le GRANT SELECT suffit seul.
 *
 * ⚠ Prod : CREATE ROLE exige que le rôle qui exécute les migrations porte
 * CREATEROLE (ou soit superuser). En dev/test amateo_owner EST superuser. Sur un
 * hébergeur où l'owner serait non-superuser, sonder AVANT le déploiement
 * (`docs/ops/deploy.md` § rôle de lecture seule) : sans CREATEROLE la migration
 * échoue franchement (fail-loud), elle ne crée pas un rôle à moitié.
 *
 * Migration écrite à la main (`make migration-diff` inopérant tant que
 * doctrine/dbal < 4.5). Architecture effective : docs/security/rls.md.
 */
final class Version20260930090000 extends AbstractMigration
{
    /** Doit rester BYTE-identique au canon tenant_isolation (Version20260703120000). */
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    /**
     * Énumération dynamique des tables portant une colonne club_id — même prédicat
     * que RlsIsolationTest::CLUB_ID_TABLE_PREDICATE (une seule source de vérité :
     * table ordinaire 'r' + partitionnée parente 'p', colonne club_id vivante).
     */
    private const string CLUB_ID_TABLES_SQL = <<<'SQL'
        SELECT c.relname
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND c.relkind IN ('r', 'p')
          AND EXISTS (
              SELECT 1 FROM pg_attribute a
              WHERE a.attrelid = c.oid AND a.attname = 'club_id' AND NOT a.attisdropped
          )
        ORDER BY c.relname
        SQL;

    public function getDescription(): string
    {
        return 'Read-only Postgres role amateo_read: SELECT-only grants + a tenant-scoped readonly_tenant SELECT policy on every club_id table (no admin_all — stays club-scoped like amateo_app). Login role without password (set on day one).';
    }

    public function up(Schema $schema): void
    {
        // 1. Rôle de lecture seule, idempotent. LOGIN sans mot de passe : inutilisable
        //    tant que l'opérateur n'en pose pas un le jour J (hors git).
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'amateo_read') THEN
                    CREATE ROLE amateo_read WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
                END IF;
            END
            $$;
            SQL);

        // 2. Droits en lecture seule (aucun DML — l'écriture est refusée au niveau privilège).
        $this->addSql('GRANT USAGE ON SCHEMA public TO amateo_read');
        $this->addSql('GRANT SELECT ON ALL TABLES IN SCHEMA public TO amateo_read');
        // FOR ROLE amateo_owner : ce rôle possède les tables créées par les migrations
        // futures — elles hériteront donc du SELECT automatiquement.
        $this->addSql('ALTER DEFAULT PRIVILEGES FOR ROLE amateo_owner IN SCHEMA public GRANT SELECT ON TABLES TO amateo_read');

        // 3. Policy SELECT tenant-scopée sur chaque table club_id (FORCE RLS ⇒ sans
        //    policy = 0 ligne). Prédicat canonique, jamais réécrit.
        $predicate = self::TENANT_PREDICATE;
        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn(self::CLUB_ID_TABLES_SQL);
        $this->abortIf([] === $tables, 'Aucune table club_id trouvée — la RLS de base (Version20260703120000) a-t-elle joué avant celle-ci ?');
        foreach ($tables as $table) {
            // Double-quote systématique : certaines tables tenant sont des mots
            // réservés ("constraint").
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public."%s" FOR SELECT TO amateo_read USING (%s)',
                $table,
                $predicate,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn(
            'SELECT tablename FROM pg_policies WHERE schemaname = \'public\' AND policyname = \'readonly_tenant\' ORDER BY tablename',
        );
        foreach ($tables as $table) {
            $this->addSql(\sprintf('DROP POLICY IF EXISTS readonly_tenant ON public."%s"', $table));
        }

        // Retire les droits avant de tenter de supprimer le rôle (une dépendance
        // résiduelle — grant ou default privilege — bloquerait le DROP ROLE).
        $this->addSql('ALTER DEFAULT PRIVILEGES FOR ROLE amateo_owner IN SCHEMA public REVOKE SELECT ON TABLES FROM amateo_read');
        $this->addSql('REVOKE SELECT ON ALL TABLES IN SCHEMA public FROM amateo_read');
        $this->addSql('REVOKE USAGE ON SCHEMA public FROM amateo_read');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'amateo_read') THEN
                    DROP OWNED BY amateo_read;
                    DROP ROLE amateo_read;
                END IF;
            END
            $$;
            SQL);
    }
}
