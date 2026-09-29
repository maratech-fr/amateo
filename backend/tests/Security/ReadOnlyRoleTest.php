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
 * (Version20260930090000) : l'opérateur qui explore la base depuis son poste peut
 * LIRE le club dont il pose le contexte, et RIEN d'autre — ni écrire, ni voir un
 * autre club, ni traverser le tenant.
 *
 * Comme RlsIsolationTest::testAdminDoorLetsOwnerCrossClubsWhileAppUserStaysScoped,
 * on prouve le rôle par `SET LOCAL ROLE amateo_read` sur la connexion admin plutôt
 * que par une vraie connexion : la RLS et les privilèges sont appliqués PAR RÔLE
 * (pg_has_role), l'enforcement est identique, et amateo_read naît sans mot de passe
 * (posé le jour J) donc on ne peut pas ouvrir de connexion nominative en test. En
 * dev/test amateo_owner est superuser (bypass total) : on bascule donc sur
 * amateo_read (NON-superuser) pour que FORCE RLS et les GRANT mordent réellement —
 * c'est exactement la situation d'un opérateur en prod.
 *
 * Tout le semis vit sur la connexion admin, dans SA transaction (rollback en
 * finally) : la connexion admin n'est pas enveloppée par DAMA.
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

        /** @var list<string> $clubIdTables */
        $clubIdTables = $this->connection->fetchFirstColumn(
            'SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE ' . self::CLUB_ID_TABLE_PREDICATE . ' ORDER BY c.relname',
        );

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
        // lecture (voire écriture) cross-tenant. On vérifie qu'aucune policy dont le
        // rôle inclut amateo_read n'est un admin_all / USING(true).
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

            // Contexte club A → ne voit QUE A.
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

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
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
