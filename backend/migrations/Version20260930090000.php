<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5-20 — rôle PostgreSQL de LECTURE SEULE (`amateo_read`) pour l'exploration
 * opérateur courante depuis un poste. LISTE BLANCHE stricte : jamais un secret.
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
 *  2. Droits en LECTURE seule, en LISTE BLANCHE — surtout PAS `GRANT SELECT ON ALL
 *     TABLES` (revue sécurité) : cela exposerait super_admin (password_hash +
 *     totp_secret), les tables de tokens (club_creation_request, reset_password_request,
 *     email_change_token, email_verification_token), app_user.password_hash, le journal
 *     admin. On énumère donc explicitement :
 *      - toutes les tables club_id (patron catalogue, robuste aux tables futures) —
 *        table entière SAUF coach_wish_token, dont on exclut la colonne `token` (secret
 *        de la page publique) par un GRANT colonne par colonne ;
 *      - une liste EXPLICITE de tables globales non sensibles (référentiels, club, et
 *        app_user en colonnes SANS password_hash ni pending_email) ;
 *      - AUCUN `ALTER DEFAULT PRIVILEGES` : une table FUTURE n'est PAS lisible tant
 *        qu'une migration ne l'a pas ajoutée à la liste blanche — défaut fermé, et
 *        ReadOnlyRoleTest (garde de classification) force ce choix conscient.
 *     Aucun INSERT/UPDATE/DELETE : l'écriture est refusée au niveau PRIVILÈGE.
 *  3. Sous FORCE ROW LEVEL SECURITY, un GRANT SELECT ne suffit PAS : sans policy
 *     pour amateo_read, chaque table tenant rend 0 ligne (default-deny). On pose donc,
 *     sur CHAQUE table club_id, une policy `readonly_tenant FOR SELECT TO amateo_read`
 *     scopée sur le MÊME prédicat canonique que tenant_isolation (RlsIsolationTest
 *     compare par égalité stricte au canon runtime : réécrire le prédicat le ferait
 *     rougir, et sans NULLIF la chaîne vide posée par clear() partirait en ''::uuid →
 *     22P02). AUCUNE policy admin_all pour amateo_read : il reste scopé comme
 *     amateo_app. ⚠ Ce scoping par club est une AIDE (éviter de mélanger les clubs),
 *     PAS une frontière : `SET app.club_id` est posable par qui a la session — la
 *     vraie garantie est que le rôle ne voit AUCUN secret et ne peut RIEN écrire.
 *
 * ⚠ Prod : CREATE ROLE exige que le rôle qui exécute les migrations porte CREATEROLE
 * (ou soit superuser). En dev/test amateo_owner EST superuser. Sur un hébergeur où
 * l'owner serait non-superuser, sonder AVANT le déploiement (`docs/ops/deploy.md`
 * § rôle de lecture seule) : sans CREATEROLE la migration échoue franchement.
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

    /**
     * Table club_id dont le GRANT est colonne par colonne (exclut le secret `token`).
     * La policy readonly_tenant s'applique quand même (scoping des lignes).
     */
    private const string COACH_WISH_TOKEN = 'coach_wish_token';

    /** Colonnes lisibles de coach_wish_token — tout SAUF `token`. */
    private const array COACH_WISH_TOKEN_COLUMNS = [
        'id', 'campaign_id', 'coach_id', 'club_id', 'responded_at', 'created_at', 'sent_at',
    ];

    /**
     * Tables GLOBALES (sans club_id, hors RLS) non sensibles, lisibles table entière :
     * référentiels communautaires/fédéraux + le club lui-même (données de club, opérateur
     * de confiance ; aucune colonne secrète vérifiée). app_user est traité à part (colonnes).
     * Liste BLANCHE explicite — jamais un secret n'y entre.
     */
    private const array GLOBAL_READABLE_TABLES = [
        'club',
        'ffbb_committee',
        'ffbb_league',
        'league_match_window',
        'opponent_directory',
        'opponent_venue_suggestion',
        'priority_tier',
        'public_holiday',
        'release_note',
        'school_holiday_period',
        'shared_competition_deadline',
        'sport',
        'subscription_plan',
    ];

    /** Colonnes lisibles d'app_user — SANS password_hash ni pending_email (secret du changement d'e-mail). */
    private const array APP_USER_COLUMNS = [
        'id', 'version', 'created_at', 'updated_at', 'email', 'first_name', 'last_name',
        'email_verified_at', 'anonymized_at', 'last_login_at', 'inactivity_warned_at',
        'terms_accepted_at', 'terms_version', 'release_notes_seen_at',
    ];

    public function getDescription(): string
    {
        return 'Read-only Postgres role amateo_read: WHITELIST SELECT grants (never a secret) + a tenant-scoped readonly_tenant SELECT policy on every club_id table (no admin_all). Column-level on app_user (no password_hash) and coach_wish_token (no token). Login role without password (set on day one).';
    }

    public function up(Schema $schema): void
    {
        // 1. Rôle de lecture seule, idempotent. LOGIN sans mot de passe.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'amateo_read') THEN
                    CREATE ROLE amateo_read WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
                END IF;
            END
            $$;
            SQL);

        // Durcissement (revue sécurité) : re-poser explicitement les attributs étroits —
        // un rôle amateo_read PRÉ-EXISTANT aux droits plus larges (créé à la main, ou
        // hérité) serait sinon conservé tel quel par le CREATE idempotent ci-dessus.
        // Requiert que le rôle des migrations soit superuser (le cas partout aujourd'hui :
        // dev/test/CI + prod Scaleway Instances, cf. docs/ops/prod-stack.md ; un futur
        // Postgres managé rouvrirait cette section).
        $this->addSql('ALTER ROLE amateo_read NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION');

        $this->addSql('GRANT USAGE ON SCHEMA public TO amateo_read');

        // 2. Tables club_id : SELECT table entière + policy readonly_tenant.
        //    coach_wish_token = colonnes seulement (exclut `token`), policy quand même.
        $predicate = self::TENANT_PREDICATE;
        /** @var list<string> $clubIdTables */
        $clubIdTables = $this->connection->fetchFirstColumn(self::CLUB_ID_TABLES_SQL);
        $this->abortIf([] === $clubIdTables, 'Aucune table club_id trouvée — la RLS de base (Version20260703120000) a-t-elle joué avant celle-ci ?');
        foreach ($clubIdTables as $table) {
            if (self::COACH_WISH_TOKEN === $table) {
                $this->addSql(\sprintf(
                    'GRANT SELECT (%s) ON public.%s TO amateo_read',
                    implode(', ', self::COACH_WISH_TOKEN_COLUMNS),
                    self::COACH_WISH_TOKEN,
                ));
            } else {
                // Double-quote systématique : certaines tables tenant sont des mots
                // réservés ("constraint").
                $this->addSql(\sprintf('GRANT SELECT ON public."%s" TO amateo_read', $table));
            }
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public."%s" FOR SELECT TO amateo_read USING (%s)',
                $table,
                $predicate,
            ));
        }

        // 3. Tables globales non sensibles (liste blanche) + app_user en colonnes.
        foreach (self::GLOBAL_READABLE_TABLES as $table) {
            $this->addSql(\sprintf('GRANT SELECT ON public."%s" TO amateo_read', $table));
        }
        $this->addSql(\sprintf(
            'GRANT SELECT (%s) ON public.app_user TO amateo_read',
            implode(', ', self::APP_USER_COLUMNS),
        ));
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

        // DROP OWNED BY révoque TOUS les droits (table ET colonne) accordés au rôle
        // dans cette base — plus fiable qu'un REVEKE ciblé après des GRANT colonnes.
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
