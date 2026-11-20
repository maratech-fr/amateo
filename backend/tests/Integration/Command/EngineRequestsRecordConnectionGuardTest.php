<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\EngineRequestDecorTrait;
use DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension as DamaExtension;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use FilesystemIterator;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Promesse : `app:engine-requests:record` refuse (sortie 3, rien d'écrit) une connexion sur laquelle la
 * sécurité par club n'est pas effective. Le placement lit ses tables sans filtre de club : sur une
 * telle connexion, la demande d'un club embarquerait les données de TOUS les clubs de la base.
 *
 * Quatre façons de contourner la RLS, chacune refusée et NOMMÉE dans la sortie (le motif nommé est ce
 * qui isole chaque contrôle : avec des données en base, la sonde les voit toutes) :
 *   - le rôle courant est superuser (« superuser ») — c'est la connexion admin de dev/test ;
 *   - le rôle courant a l'attribut BYPASSRLS (« bypassrls ») ;
 *   - le rôle courant est membre de `amateo_owner` (« amateo_owner ») — la porte admin `admin_all` ;
 *   - aucun attribut, mais une lecture d'une table de club SANS club posé rend des lignes (« sonde »)
 *     — ici une policy permissive ajoutée pour le seul rôle de la sonde, sur toutes les tables de club,
 *     puis sur UNE seule table que le placement lit et qui n'est pas parmi les premières venues
 *     (`team_link`) : la sonde lit toutes les tables à `club_id` sous RLS, pas une liste figée.
 * Témoin : le rôle `amateo_app` nu enregistre (sortie 0).
 *
 * Mise en place — les rôles vivent à l'échelle du CLUSTER PostgreSQL, celui de la base de dev :
 *   - la classe REFUSE de tourner (échec avant toute création) si la connexion propriétaire ne vise
 *     pas une base `*_test` ;
 *   - les rôles qui ne demandent aucun droit sur les tables (superuser, membre de `amateo_owner`)
 *     naissent et meurent dans le test qui s'en sert (`finally`) ;
 *   - ceux qui demandent des droits ou une policy (bypassrls, sondes) sont créés une fois pour la
 *     classe, hors de la transaction de test, par une connexion propriétaire DÉDIÉE (une DDL pendant
 *     la transaction attendrait ses verrous) — mais `amateo_app` ne les reçoit (`WITH INHERIT FALSE,
 *     SET TRUE`) que le temps du test, et les rend dans un `finally`. Entre deux tests, `amateo_app`
 *     ne peut devenir aucun rôle de sonde ;
 *   - un reste après la suite (classe tuée) fait rougir
 *     `Security/RlsIsolationTest::testNoEngineRequestProbeRoleOrPolicyIsLeftBehind`.
 * Le test bascule la connexion de l'application par `SET LOCAL ROLE` (annulé avec la transaction) :
 * c'est le `current_user` que l'outil doit juger.
 *
 * Jumeau : `EngineRequestsRecordCommandTest` (ce que l'outil écrit quand la connexion est saine).
 */
#[Group('integration')]
final class EngineRequestsRecordConnectionGuardTest extends KernelTestCase
{
    use EngineRequestDecorTrait;

    private const string ROLE_SUPER = 'engine_rec_probe_super';
    private const string ROLE_BYPASS = 'engine_rec_probe_bypass';
    private const string ROLE_OWNER = 'engine_rec_probe_owner';
    private const string ROLE_LEAK = 'engine_rec_probe_leak';
    private const string LEAK_POLICY = 'engine_rec_probe_leak';
    private const string ROLE_LEAK_ONE = 'engine_rec_probe_leak_one';
    private const string LEAK_ONE_POLICY = 'engine_rec_probe_leak_one';
    /** Une table de club que le placement lit sans filtre de club (`MatchPlacementPayloadBuilder`). */
    private const string LEAK_ONE_TABLE = 'team_link';

    /** Rôles nés et morts dans leur test : la clause de création de chacun. */
    private const array EPHEMERAL_ROLES = [
        self::ROLE_SUPER => 'NOLOGIN SUPERUSER',
        self::ROLE_OWNER => 'NOLOGIN NOSUPERUSER NOBYPASSRLS IN ROLE amateo_owner',
    ];

    /** Rôles de la classe (droits sur les tables ou policy) : jamais membres de `amateo_app` hors d'un test. */
    private const array CLASS_ROLES = [self::ROLE_BYPASS, self::ROLE_LEAK, self::ROLE_LEAK_ONE];

    private const string CLUB_ID_TABLES = <<<'SQL'
        SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND c.relrowsecurity
          AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'club_id' AND NOT a.attisdropped)
        ORDER BY c.relname
        SQL;

    /** @var list<string> */
    private array $outDirs = [];

    public static function setUpBeforeClass(): void
    {
        // DAMA n'annule la transaction d'un test qu'au début du SUIVANT : celle du dernier test de la
        // classe d'avant tient encore ses verrous, et une DDL l'attendrait jusqu'au délai d'inactivité
        // (60 s, puis la connexion meurt). Rien à préserver : le prochain test l'aurait annulée.
        // Patron de `App\Tests\ReleasesParentTransactionBeforeIsolatedTests`.
        DamaExtension::rollBack();
        $owner = self::ownerConnection();
        self::dropProbes($owner);
        $owner->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN NOSUPERUSER BYPASSRLS', self::ROLE_BYPASS));
        $owner->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN NOSUPERUSER NOBYPASSRLS', self::ROLE_LEAK));
        $owner->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN NOSUPERUSER NOBYPASSRLS', self::ROLE_LEAK_ONE));
        foreach (self::CLASS_ROLES as $role) {
            $owner->executeStatement(\sprintf('GRANT USAGE ON SCHEMA public TO %s', $role));
            $owner->executeStatement(\sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %s', $role));
            $owner->executeStatement(\sprintf('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO %s', $role));
        }
        foreach ($owner->fetchFirstColumn(self::CLUB_ID_TABLES) as $table) {
            $owner->executeStatement(\sprintf('CREATE POLICY %s ON %s FOR SELECT TO %s USING (true)', self::LEAK_POLICY, $owner->quoteIdentifier((string) $table), self::ROLE_LEAK));
        }
        $owner->executeStatement(\sprintf('CREATE POLICY %s ON %s FOR SELECT TO %s USING (true)', self::LEAK_ONE_POLICY, self::LEAK_ONE_TABLE, self::ROLE_LEAK_ONE));
        $owner->close();
    }

    public static function tearDownAfterClass(): void
    {
        // Même raison : la transaction du dernier test de cette classe tient les verrous des tables.
        DamaExtension::rollBack();
        $owner = self::ownerConnection();
        self::dropProbes($owner);
        $owner->close();
        parent::tearDownAfterClass();
    }

    /** @return iterable<string, array{string, string}> */
    public static function bypassingRoles(): iterable
    {
        yield 'superuser (la connexion admin)' => [self::ROLE_SUPER, 'superuser'];
        yield 'BYPASSRLS' => [self::ROLE_BYPASS, 'bypassrls'];
        yield 'membre de amateo_owner' => [self::ROLE_OWNER, 'amateo_owner'];
        yield 'sonde : lignes visibles sans club posé' => [self::ROLE_LEAK, 'sonde'];
    }

    /**
     * Connexion propriétaire HORS du conteneur (et donc hors de toute transaction de test). Refusée,
     * avant tout geste, si elle ne vise pas une base de test : les rôles de sonde vivent sur le cluster.
     */
    private static function ownerConnection(): Connection
    {
        $url = $_ENV['DATABASE_ADMIN_URL'] ?? $_SERVER['DATABASE_ADMIN_URL'] ?? getenv('DATABASE_ADMIN_URL');
        self::assertIsString($url, 'DATABASE_ADMIN_URL requis');

        $owner = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
        $database = (string) $owner->fetchOne('SELECT current_database()');
        if (!self::isATestDatabase($database)) {
            $owner->close();
        }
        self::assertTestDatabase($database);

        return $owner;
    }

    private static function isATestDatabase(string $database): bool
    {
        return str_ends_with($database, '_test');
    }

    /** Échec explicite si la base n'est pas une base `*_test` : aucun rôle de sonde n'est créé ailleurs. */
    private static function assertTestDatabase(string $database): void
    {
        self::assertTrue(
            self::isATestDatabase($database),
            \sprintf('Refusé : les rôles de sonde vivent sur tout le cluster PostgreSQL ; la connexion propriétaire vise la base « %s », pas une base *_test.', $database),
        );
    }

    private static function dropProbes(Connection $owner): void
    {
        foreach ($owner->fetchFirstColumn(self::CLUB_ID_TABLES) as $table) {
            foreach ([self::LEAK_POLICY, self::LEAK_ONE_POLICY] as $policy) {
                $owner->executeStatement(\sprintf('DROP POLICY IF EXISTS %s ON %s', $policy, $owner->quoteIdentifier((string) $table)));
            }
        }
        foreach ([...array_keys(self::EPHEMERAL_ROLES), ...self::CLASS_ROLES] as $role) {
            self::dropRole($owner, $role);
        }
    }

    private static function dropRole(Connection $owner, string $role): void
    {
        if (false !== $owner->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role])) {
            $owner->executeStatement(\sprintf('DROP OWNED BY %s', $role));
            $owner->executeStatement(\sprintf('DROP ROLE %s', $role));
        }
    }

    /** La classe ne tourne que sur une base `*_test` (rôles de sonde à l'échelle du cluster). */
    public function testTheProbesRefuseADatabaseThatIsNotATestDatabase(): void
    {
        foreach (['amateo_local', 'amateo', 'amateo_test_copie', 'postgres'] as $database) {
            try {
                self::assertTestDatabase($database);
            } catch (AssertionFailedError $refusal) {
                self::assertStringContainsString('Refusé', $refusal->getMessage());

                continue;
            }
            self::fail(\sprintf('la base « %s » aurait dû être refusée', $database));
        }
        self::assertTestDatabase('amateo_test');
    }

    #[DataProvider('bypassingRoles')]
    public function testAConnectionThatBypassesClubSecurityIsRefused(string $role, string $reason): void
    {
        $connection = $this->erEm()->getConnection();
        $out = $this->newOutDir();
        $tester = $this->tester();
        $code = $this->underProbeRole($role, function () use ($connection, $role, $out, $tester): int {
            // Le refus attendu a un sens : sous ce rôle, une table de club se lit sans club posé.
            $this->clearGuc();
            self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT count(*) FROM team'), $role . ' doit voir les équipes sans club posé — sinon le refus ne prouve rien');
            $this->scopeGucToClub(self::ER_CLUB);

            return $tester->execute(['--out' => $out, '--club' => [self::ER_CLUB]]);
        });

        self::assertSame(3, $code, $role . ' : sortie 3 attendue. ' . $tester->getDisplay());
        self::assertStringContainsString($reason, $tester->getDisplay(), 'le motif du refus est nommé');
        self::assertSame([], $this->filesUnder($out), 'rien n\'est écrit');
    }

    /**
     * La sonde lit TOUTES les tables à `club_id` sous RLS (lues dans le catalogue), pas une liste figée :
     * une policy permissive sur la seule `team_link` — lue par le placement sans filtre de
     * club — fait refuser, et la table fuyante est nommée.
     */
    public function testTheProbeReadsEveryClubTableNotAFixedList(): void
    {
        $connection = $this->erEm()->getConnection();
        $out = $this->newOutDir();
        $tester = $this->tester();
        $code = $this->underProbeRole(self::ROLE_LEAK_ONE, function () use ($connection, $out, $tester): int {
            $this->clearGuc();
            // Témoins : la fuite existe sur cette table seule ; les tables de club habituelles restent vides.
            self::assertGreaterThan(0, (int) $connection->fetchOne(\sprintf('SELECT count(*) FROM %s', self::LEAK_ONE_TABLE)), 'la policy de sonde doit ouvrir ' . self::LEAK_ONE_TABLE . ' — sinon le refus ne prouve rien');
            foreach (['team', 'fixture', 'schedule', 'schedule_plan'] as $table) {
                self::assertSame(0, (int) $connection->fetchOne(\sprintf('SELECT count(*) FROM %s', $table)), $table . ' reste fermée sans club posé');
            }
            $this->scopeGucToClub(self::ER_CLUB);

            return $tester->execute(['--out' => $out, '--club' => [self::ER_CLUB]]);
        });

        self::assertSame(3, $code, 'une seule table de club lisible sans club posé : sortie 3 attendue. ' . $tester->getDisplay());
        self::assertStringContainsString('sonde', $tester->getDisplay(), 'le motif du refus est nommé');
        self::assertStringContainsString(self::LEAK_ONE_TABLE, $tester->getDisplay(), 'la table fuyante est nommée');
        self::assertSame([], $this->filesUnder($out), 'rien n\'est écrit');
    }

    /**
     * Une option invalide est refusée (sortie 2) AVANT que la connexion soit jugée : sous un rôle qui
     * contourne la RLS, un `--club` invalide rend 2, pas 3. Jumeau de l'ordre 4 avant 2 :
     * `EngineRequestsRecordCommandTest::testProductionIsRefusedBeforeEveryOtherRefusal`.
     */
    public function testAnInvalidOptionIsRefusedBeforeTheConnectionIsJudged(): void
    {
        $out = $this->newOutDir();
        $tester = $this->tester();
        $code = $this->underProbeRole(self::ROLE_SUPER, static fn (): int => $tester->execute(['--out' => $out, '--club' => ['../x']]));

        self::assertSame(2, $code, 'option invalide d\'abord, connexion ensuite. ' . $tester->getDisplay());
        self::assertSame([], $this->filesUnder($out), 'rien n\'est écrit');
    }

    public function testTheApplicationRoleRecords(): void
    {
        $connection = $this->erEm()->getConnection();
        self::assertSame('amateo_app', $connection->fetchOne('SELECT current_user'));
        $this->clearGuc();
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM team'), 'amateo_app ne voit rien sans club posé');
        // Le club reste posé ici, comme après une graine : l'outil doit le relâcher avant sa sonde.
        $this->scopeGucToClub(self::ER_CLUB);

        $tester = $this->tester();
        $code = $tester->execute(['--out' => $this->newOutDir(), '--club' => [self::ER_CLUB]]);

        self::assertSame(0, $code, 'amateo_app : la sécurité par club est effective, l\'outil enregistre. ' . $tester->getDisplay());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        // Lu par la connexion propriétaire : interrogée depuis la connexion de l'application, la
        // question mettrait en cache, pour la transaction du test, ses appartenances d'avant le GRANT —
        // et son `SET LOCAL ROLE` serait refusé.
        $owner = self::ownerConnection();
        $memberships = $owner->fetchFirstColumn('SELECT r.rolname FROM pg_roles r WHERE r.rolname LIKE \'engine\_rec\_probe%\' AND pg_has_role(\'amateo_app\', r.oid, \'MEMBER\') ORDER BY 1');
        $owner->close();
        self::assertSame([], $memberships, 'entre deux tests, amateo_app ne peut devenir aucun rôle de sonde');
        $this->pinEngineRequestClock();
        $this->seedEngineRequestBase();
    }

    protected function tearDown(): void
    {
        $this->unpinEngineRequestClock();
        foreach ($this->outDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->outDirs = [];
        parent::tearDown();
    }

    /**
     * Joue `$check` sous le rôle de sonde `$role` : le rôle éphémère naît ici, `amateo_app` le reçoit
     * le temps de `$check`, puis le rend — et le rôle éphémère meurt — dans un `finally`.
     *
     * @template T
     *
     * @param callable(): T $check
     *
     * @return T
     */
    private function underProbeRole(string $role, callable $check): mixed
    {
        $connection = $this->erEm()->getConnection();
        $owner = self::ownerConnection();
        try {
            if (\array_key_exists($role, self::EPHEMERAL_ROLES)) {
                $owner->executeStatement(\sprintf('CREATE ROLE %s %s', $role, self::EPHEMERAL_ROLES[$role]));
            }
            $owner->executeStatement(\sprintf('GRANT %s TO amateo_app WITH INHERIT FALSE, SET TRUE', $role));
            $connection->executeStatement(\sprintf('SET LOCAL ROLE %s', $role));

            return $check();
        } finally {
            try {
                $connection->executeStatement('RESET ROLE');
            } finally {
                if (false !== $owner->fetchOne('SELECT 1 FROM pg_auth_members m JOIN pg_roles r ON r.oid = m.roleid JOIN pg_roles a ON a.oid = m.member WHERE r.rolname = ? AND a.rolname = \'amateo_app\'', [$role])) {
                    $owner->executeStatement(\sprintf('REVOKE %s FROM amateo_app', $role));
                }
                if (\array_key_exists($role, self::EPHEMERAL_ROLES)) {
                    self::dropRole($owner, $role);
                }
                $owner->close();
            }
        }
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:engine-requests:record'));
    }

    private function newOutDir(): string
    {
        $dir = sys_get_temp_dir() . '/engine-requests-guard-' . bin2hex(random_bytes(6));
        $this->outDirs[] = $dir;

        return $dir . '/out';
    }

    /** @return list<string> */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = (string) $file;
        }

        return $files;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($dir);
    }
}
