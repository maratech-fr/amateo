<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P5-20 non-régression — le rôle PostgreSQL de LECTURE SEULE `amateo_read`
 * (Version20260930090000). Deux garanties, dans l'ordre d'importance :
 *  1. Il ne voit AUCUN secret et ne peut RIEN écrire (garantie DURE) : liste blanche
 *     de tables, colonnes secrètes exclues (app_user.password_hash, coach_wish_token.token),
 *     tables de secrets/admin jamais accordées, écriture refusée au niveau privilège.
 *  2. Sa lecture est scopée au club posé par SET app.club_id (AIDE, pas frontière —
 *     le contexte est posable par qui a la session ; voir docs/security/rls.md).
 *
 * Comme RlsIsolationTest::testAdminDoorLetsOwnerCrossClubsWhileAppUserStaysScoped,
 * on prouve le comportement par `SET LOCAL ROLE` sur la connexion admin : la RLS et
 * les privilèges sont appliqués PAR RÔLE (pg_has_role), l'enforcement est identique,
 * et amateo_read naît sans mot de passe (posé le jour J) donc on ne peut pas ouvrir
 * de connexion nominative en test.
 *
 * CLASSIFICATION OBLIGATOIRE (garde du futur) : toute table du schéma public doit être
 * soit lisible (table club_id ∪ liste blanche globale), soit dans la liste noire
 * explicite. Une nouvelle table non classée fait rougir testEveryPublicTableIsClassified
 * — sinon une future table de secrets serait accordée en silence par un GRANT ON ALL,
 * ou une future table tenant deviendrait invisible.
 */
#[Group('phase1')]
#[Group('integration')]
final class ReadOnlyRoleTest extends KernelTestCase
{
    private const string CLUB_A = '11111111-1111-4111-8111-aaaaaaaaaaaa';
    private const string CLUB_B = '22222222-2222-4222-8222-bbbbbbbbbbbb';

    /**
     * Même énumération des tables club_id que RlsIsolationTest / la migration :
     * table ordinaire 'r' + partitionnée 'p', colonne club_id vivante.
     */
    private const string CLUB_ID_TABLE_PREDICATE = <<<'SQL'
        n.nspname = 'public'
        AND c.relkind IN ('r', 'p')
        AND EXISTS (
            SELECT 1 FROM pg_attribute a
            WHERE a.attrelid = c.oid AND a.attname = 'club_id' AND NOT a.attisdropped
        )
        SQL;

    /**
     * Tables GLOBALES (sans club_id) que la migration accorde en lecture — liste BLANCHE
     * explicite, miroir de Version20260930090000. app_user y est en colonnes (sans secret).
     * Les tables club_id lisibles sont énumérées dynamiquement et s'ajoutent à celles-ci.
     */
    private const array GLOBAL_READABLE = [
        'app_user',
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

    /**
     * Tables GLOBALES que amateo_read ne doit JAMAIS pouvoir lire. Secrets (super_admin :
     * password_hash + totp_secret ; les 4 tables de tokens), journal/exploitation admin,
     * logs internes d'idempotence, infra, et le résiduel tenant sans club_id
     * (constraint_conflict : non scopable — l'exploration profonde d'un planning passe par
     * amateo_owner). Une table de secrets ajoutée demain DOIT atterrir ici, jamais en blanc.
     */
    private const array BLACKLIST = [
        'super_admin',
        'admin_audit_log',
        'admin_alert_state',
        'admin_job_run',
        'club_creation_request',
        'reset_password_request',
        'email_change_token',
        'email_verification_token',
        'period_reminder_log',
        'transition_reminder_log',
        'doctrine_migration_versions',
        'constraint_conflict',
    ];

    /**
     * Motif (insensible à la casse) des noms de colonnes qui SENTENT le secret. Large
     * volontairement — c'est un filet, pas une liste exacte : une future colonne
     * `access_token`, `otp_hash`, `api_key`… sera attrapée sans qu'on y pense.
     */
    private const string SECRET_COLUMN_REGEX = '(token|secret|hash|password|passwd|otp|totp|salt|api_?key|private)';

    /**
     * Faux positifs LÉGITIMES du motif ci-dessus, déjà revus : à tenir à la main, JAMAIS
     * une exclusion large. `schedule.snapshot_hash` = empreinte de CONTENU du planning
     * (détection de péremption), pas un secret d'authentification.
     */
    private const array SECRET_COLUMN_REGEX_EXCEPTIONS = [
        'schedule.snapshot_hash',
    ];

    private Connection $connection;

    public function testEveryClubIdTableHasExactlyOneTenantScopedReadonlyPolicy(): void
    {
        // Garde du FUTUR : une nouvelle table tenant qui oublierait sa policy
        // readonly_tenant serait INVISIBLE à l'opérateur lecture seule (FORCE RLS
        // sans policy pour son rôle = 0 ligne), ou — pire si on la posait à la main
        // en USING(true) — VISIBLE cross-club. On exige donc, sur CHAQUE table
        // club_id, exactement UNE policy amateo_read, en SELECT, scopée au GUC.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.relname AS table_name, p.policyname, p.cmd, p.qual, p.roles, p.permissive '
            . 'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'JOIN pg_policies p ON p.schemaname = \'public\' AND p.tablename = c.relname '
            . 'WHERE ' . self::CLUB_ID_TABLE_PREDICATE
            . ' ORDER BY c.relname, p.policyname',
        );
        self::assertNotEmpty($rows, 'expected policies on club_id tables');

        // Canon lu à l'exécution (team_tag.tenant_isolation), comme RlsIsolationTest :
        // la policy readonly_tenant DOIT porter ce même prédicat.
        $canon = $this->connection->fetchOne(
            'SELECT qual FROM pg_policies WHERE schemaname = \'public\' AND tablename = \'team_tag\' AND policyname = \'tenant_isolation\'',
        );
        self::assertIsString($canon, 'canon policy team_tag.tenant_isolation must exist');
        self::assertStringContainsString('current_setting', $canon, 'canon must reference the GUC');

        $clubIdTables = $this->clubIdTables();

        $readonlyByTable = [];
        foreach ($rows as $row) {
            if ('{amateo_read}' !== (string) $row['roles']) {
                continue;
            }
            $table = (string) $row['table_name'];
            $policy = (string) $row['policyname'];
            $where = \sprintf('%s.%s (%s)', $table, $policy, (string) $row['cmd']);

            // amateo_read n'a de policy QUE readonly_tenant, QUE en SELECT, JAMAIS
            // admin_all ni USING(true) — sinon il verrait au-delà de son club.
            self::assertSame('readonly_tenant', $policy, \sprintf('%s : seule policy attendue pour amateo_read = readonly_tenant.', $where));
            self::assertSame('SELECT', (string) $row['cmd'], \sprintf('%s : amateo_read ne doit avoir qu\'une policy SELECT (lecture seule).', $where));
            self::assertSame($canon, (string) $row['qual'], \sprintf('%s : la policy amateo_read doit porter le prédicat canonique tenant, pas %s.', $where, (string) $row['qual']));

            $readonlyByTable[$table] = ($readonlyByTable[$table] ?? 0) + 1;
        }

        foreach ($clubIdTables as $table) {
            self::assertSame(
                1,
                $readonlyByTable[$table] ?? 0,
                \sprintf('table %s porte club_id mais %d policy readonly_tenant pour amateo_read (attendu exactement 1) — l\'opérateur lecture seule la verrait à 0 ligne (ou cross-club si posée à la main).', $table, $readonlyByTable[$table] ?? 0),
            );
        }
    }

    public function testNoAdminAllPolicyGrantsAmateoReadCrossClubAccess(): void
    {
        // amateo_read NE DOIT PAS être membre d'une porte admin_all : ce serait une
        // lecture cross-tenant. On vérifie qu'aucune policy dont le rôle inclut
        // amateo_read n'est un admin_all / USING(true).
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT tablename, policyname, cmd, qual, roles FROM pg_policies '
            . 'WHERE schemaname = \'public\' AND roles::text LIKE \'%amateo_read%\' ORDER BY tablename, policyname',
        );
        foreach ($rows as $row) {
            self::assertNotSame('admin_all', (string) $row['policyname'], \sprintf('%s.%s : amateo_read ne doit JAMAIS porter admin_all (porte de supervision cross-club).', (string) $row['tablename'], (string) $row['policyname']));
            self::assertNotSame('true', (string) $row['qual'], \sprintf('%s.%s : policy USING(true) pour amateo_read — le rôle lecture seule doit rester scopé au club.', (string) $row['tablename'], (string) $row['policyname']));
        }
    }

    public function testBlacklistedTablesGrantNothingToReadOnlyRole(): void
    {
        // GARANTIE DURE : aucune table de la liste noire n'accorde le moindre droit à
        // amateo_read — ni table, ni colonne. Falsifiable : un GRANT sur super_admin
        // (ou toute table de la liste) fait rougir ce test.
        foreach (self::BLACKLIST as $table) {
            self::assertTrue($this->tableExists($table), \sprintf('table de la liste noire %s introuvable — entrée périmée (renommée/supprimée ?), à retirer de BLACKLIST.', $table));

            // has_table_privilege / has_column_privilege ne sont PAS filtrées par le rôle
            // courant (contrairement à information_schema.*_privileges, muettes vues depuis
            // amateo_app) : on interroge donc directement le droit d'amateo_read.
            self::assertFalse($this->hasTableSelect($table), \sprintf('table de la liste noire %s : amateo_read a un SELECT table-level — un secret pourrait fuiter.', $table));
            self::assertFalse($this->hasAnySelectableColumn($table), \sprintf('table de la liste noire %s : amateo_read peut lire au moins une colonne (attendu aucune).', $table));
        }
    }

    public function testSecretColumnsAreNeverReadable(): void
    {
        // Colonnes secrètes exclues explicitement des tables lisibles en colonnes.
        self::assertFalse($this->hasColumnSelect('app_user', 'password_hash'), 'app_user.password_hash ne doit jamais être lisible par amateo_read');
        self::assertFalse($this->hasColumnSelect('app_user', 'pending_email'), 'app_user.pending_email (secret du changement d\'e-mail) ne doit pas être lisible');
        self::assertFalse($this->hasColumnSelect('coach_wish_token', 'token'), 'coach_wish_token.token (secret de la page publique) ne doit jamais être lisible');

        // Témoins positifs : les colonnes non sensibles, elles, SONT lisibles (sinon la
        // liste blanche colonne aurait pu tout exclure et le test passerait à vide).
        self::assertTrue($this->hasColumnSelect('app_user', 'email'), 'app_user.email doit rester lisible (témoin positif)');
        self::assertTrue($this->hasColumnSelect('coach_wish_token', 'club_id'), 'coach_wish_token.club_id doit rester lisible (témoin positif)');

        // Garde du FUTUR : AUCUNE colonne lisible par amateo_read (table entière OU
        // colonne) dont le nom sent le secret — sauf les faux positifs revus. Une future
        // table gagnant une colonne `access_token`/`api_key`/… et accordée en lecture
        // rougit ici. On interroge has_column_privilege (non filtrée par le rôle courant).
        /** @var list<string> $matches */
        $matches = $this->connection->fetchFirstColumn(
            'SELECT c.relname || \'.\' || a.attname '
            . 'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'JOIN pg_attribute a ON a.attrelid = c.oid '
            . 'WHERE n.nspname = \'public\' AND c.relkind IN (\'r\', \'p\') AND a.attnum > 0 AND NOT a.attisdropped '
            . 'AND has_column_privilege(\'amateo_read\', a.attrelid, a.attnum, \'SELECT\') '
            . 'AND a.attname ~* ?',
            [self::SECRET_COLUMN_REGEX],
        );
        $leaks = array_values(array_diff($matches, self::SECRET_COLUMN_REGEX_EXCEPTIONS));
        self::assertSame(
            [],
            $leaks,
            "Colonnes au nom secret lisibles par amateo_read :\n  - " . implode("\n  - ", $leaks)
            . "\nSoit exclure la colonne du GRANT (migration), soit — si c'est un faux positif revu — l'ajouter "
            . 'à SECRET_COLUMN_REGEX_EXCEPTIONS avec justification.',
        );
    }

    public function testEveryPublicTableIsClassifiedReadableOrBlacklisted(): void
    {
        // Exhaustivité : chaque table du schéma public est SOIT lisible (club_id ∪ liste
        // blanche globale), SOIT dans la liste noire — jamais les deux, jamais aucune.
        $allTables = $this->connection->fetchFirstColumn(
            'SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE n.nspname = \'public\' AND c.relkind IN (\'r\', \'p\') ORDER BY c.relname',
        );
        self::assertNotEmpty($allTables, 'expected public tables to exist');

        $readable = array_merge($this->clubIdTables(), self::GLOBAL_READABLE);
        $overlap = array_intersect($readable, self::BLACKLIST);
        self::assertSame([], array_values($overlap), 'tables à la fois lisibles et en liste noire (classification incohérente) : ' . implode(', ', $overlap));

        $classified = array_merge($readable, self::BLACKLIST);
        $unclassified = array_diff($allTables, $classified);
        self::assertSame(
            [],
            array_values($unclassified),
            "Tables du schéma public NON classées :\n  - " . implode("\n  - ", $unclassified)
            . "\nChaque table doit être ajoutée à la liste blanche globale (lisible) ou à la liste noire (secrets/infra) "
            . 'de la migration Version20260930090000 ET de ce test — sinon un GRANT sur ALL exposerait une future table de secrets.',
        );

        // Les entrées EXPLICITES ne mentent pas : chaque table nommée existe (bidirectionnel).
        foreach (self::GLOBAL_READABLE as $table) {
            self::assertTrue($this->tableExists($table), \sprintf('table blanche %s introuvable — entrée périmée à retirer de GLOBAL_READABLE.', $table));
        }
        // Chaque table lisible est RÉELLEMENT accordée (table entière OU au moins une colonne).
        foreach ($readable as $table) {
            self::assertTrue(
                $this->hasAnySelectableColumn($table),
                \sprintf('table %s classée lisible mais amateo_read n\'a AUCUN droit dessus — la migration a-t-elle oublié son GRANT ?', $table),
            );
        }
    }

    public function testReadonlyRoleReadsOnlyItsClubAndCannotWrite(): void
    {
        $admin = $this->adminConnection();

        $admin->executeStatement('DROP ROLE IF EXISTS ro_probe');
        $admin->executeStatement('CREATE ROLE ro_probe NOSUPERUSER NOLOGIN IN ROLE amateo_read');

        $admin->beginTransaction();
        try {
            // Semis comme amateo_owner (superuser bypass en dev/test) : deux clubs,
            // un team_tag chacun. club n'a pas de club_id → pas de RLS.
            foreach ([self::CLUB_A => 'RA', self::CLUB_B => 'RB'] as $clubId => $tag) {
                $admin->executeStatement(
                    'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season) VALUES (?, 1, now(), now(), ?, ?, ?, ?, true, 0)',
                    [$clubId, 'RO Probe ' . $tag, 'ro-probe-' . strtolower($tag) . '-' . substr(md5($clubId), 0, 6), 'Europe/Paris', 'fr'],
                );
                $admin->executeStatement(
                    'INSERT INTO team_tag (id, version, created_at, updated_at, club_id, name, is_system) VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, false)',
                    [$clubId, 'Team ' . $tag],
                );
            }

            // Bascule sur le rôle lecture seule (NON-superuser) — RLS + privilèges mordent.
            $admin->executeStatement('SET LOCAL ROLE ro_probe');
            self::assertFalse(
                (bool) $admin->fetchOne('SELECT usesuper FROM pg_user WHERE usename = current_user'),
                'le probe doit être NON-superuser — sinon il bypasse RLS et ne prouve rien',
            );

            // Contexte club A → ne voit QUE A (aide de scoping).
            $admin->executeStatement('SELECT set_config(?, ?, true)', ['app.club_id', self::CLUB_A]);
            /** @var list<string> $visible */
            $visible = $admin->fetchFirstColumn('SELECT DISTINCT club_id FROM team_tag WHERE club_id IN (?, ?)', [self::CLUB_A, self::CLUB_B]);
            self::assertSame([self::CLUB_A], $visible, 'lecture seule posée sur A ne voit que A');

            // Aucun contexte (chaîne vide, comme clear()) → fail-closed, 0 ligne, pas d'erreur.
            $admin->executeStatement('SELECT set_config(?, ?, true)', ['app.club_id', '']);
            self::assertSame(
                0,
                (int) $admin->fetchOne('SELECT count(*) FROM team_tag WHERE club_id IN (?, ?)', [self::CLUB_A, self::CLUB_B]),
                'sans contexte club, la lecture seule ne voit 0 ligne (fail-closed via NULLIF)',
            );

            // Écritures REFUSÉES au niveau privilège (aucun GRANT DML), chacune isolée
            // dans un savepoint pour ne pas empoisonner la transaction externe.
            $admin->executeStatement('SELECT set_config(?, ?, true)', ['app.club_id', self::CLUB_A]);
            self::assertTrue($this->writeIsRejected($admin, 'INSERT INTO team_tag (id, version, created_at, updated_at, club_id, name, is_system) VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, false)', [self::CLUB_A, 'ro-insert']), 'INSERT doit être refusé à amateo_read (lecture seule)');
            self::assertTrue($this->writeIsRejected($admin, 'UPDATE team_tag SET name = ? WHERE club_id = ?', ['ro-update', self::CLUB_A]), 'UPDATE doit être refusé à amateo_read (lecture seule)');
            self::assertTrue($this->writeIsRejected($admin, 'DELETE FROM team_tag WHERE club_id = ?', [self::CLUB_A]), 'DELETE doit être refusé à amateo_read (lecture seule)');
        } finally {
            $admin->executeStatement('RESET ROLE');
            $admin->rollBack();
            $admin->executeStatement('DROP ROLE IF EXISTS ro_probe');
        }
    }

    public function testReadonlyRoleCarriesQueryAndLockTimeouts(): void
    {
        // Sécurité M3 (revue 2026-10-10) : une enquête opérateur ne doit pas pouvoir
        // immobiliser la prod. Le rôle amateo_read porte des bornes (ALTER ROLE … SET,
        // Version20261010150000), visibles dans pg_roles.rolconfig. On compare en INTERVAL
        // pour être robuste à la canonicalisation Postgres (60s ⇄ 1min).
        $expected = [
            'statement_timeout' => '60s',
            'lock_timeout' => '5s',
            'idle_in_transaction_session_timeout' => '60s',
        ];
        $admin = $this->adminConnection();
        foreach ($expected as $name => $want) {
            $value = $this->roleSetting($admin, $name);
            self::assertNotNull(
                $value,
                \sprintf('amateo_read doit porter %s (ALTER ROLE … SET) — sinon une requête opérateur peut tourner sans fin ou tenir un verrou (migration Version20261010150000).', $name),
            );
            self::assertTrue(
                (bool) $admin->fetchOne('SELECT CAST(? AS interval) = CAST(? AS interval)', [$value, $want]),
                \sprintf('amateo_read.%s = %s, attendu %s', $name, $value, $want),
            );
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    /**
     * La valeur d'un GUC attaché au rôle amateo_read (pg_authid.rolconfig, exposé par pg_roles),
     * ou null si absente. Lu sur la connexion admin (superuser en dev/test) — pas d'ambiguïté de
     * visibilité de rolconfig pour un rôle de login ordinaire.
     */
    private function roleSetting(Connection $admin, string $name): ?string
    {
        $value = $admin->fetchOne(
            'SELECT split_part(cfg, \'=\', 2) FROM pg_roles r, unnest(r.rolconfig) AS cfg '
            . 'WHERE r.rolname = \'amateo_read\' AND split_part(cfg, \'=\', 1) = ?',
            [$name],
        );

        return false === $value ? null : (string) $value;
    }

    /** @return list<string> */
    private function clubIdTables(): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE ' . self::CLUB_ID_TABLE_PREDICATE . ' ORDER BY c.relname',
        );
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE n.nspname = \'public\' AND c.relkind IN (\'r\', \'p\') AND c.relname = ?',
            [$table],
        );
    }

    private function hasTableSelect(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT has_table_privilege(\'amateo_read\', (\'public.\' || quote_ident(?))::regclass, \'SELECT\')',
            [$table],
        );
    }

    private function hasColumnSelect(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT has_column_privilege(\'amateo_read\', (\'public.\' || quote_ident(?))::regclass, ?, \'SELECT\')',
            [$table, $column],
        );
    }

    /**
     * amateo_read peut-il lire AU MOINS une colonne de la table ? Couvre le GRANT table
     * entière (chaque colonne est alors lisible) comme le GRANT colonne par colonne.
     * Repose sur has_column_privilege, non filtrée par le rôle courant.
     */
    private function hasAnySelectableColumn(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COALESCE(bool_or(has_column_privilege(\'amateo_read\', a.attrelid, a.attnum, \'SELECT\')), false) '
            . 'FROM pg_attribute a '
            . 'WHERE a.attrelid = (\'public.\' || quote_ident(?))::regclass AND a.attnum > 0 AND NOT a.attisdropped',
            [$table],
        );
    }

    private function adminConnection(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * Exécute une écriture dans un savepoint et rapporte si elle a été REFUSÉE —
     * l'abort Postgres est contenu pour ne pas poisonner la transaction externe.
     *
     * @param list<mixed> $params
     */
    private function writeIsRejected(Connection $admin, string $sql, array $params): bool
    {
        $admin->beginTransaction();
        try {
            $admin->executeStatement($sql, $params);
            $admin->commit();

            return false;
        } catch (DriverException) {
            $admin->rollBack();

            return true;
        }
    }
}
