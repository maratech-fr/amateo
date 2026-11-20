<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\EngineRequestDecorTrait;
use DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension as DamaExtension;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use FilesystemIterator;
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
 *     — ici une policy permissive ajoutée pour le seul rôle de la sonde.
 * Témoin : le rôle `amateo_app` nu enregistre (sortie 0).
 *
 * Mise en place : les rôles de sonde (et la policy) sont créés une fois pour la classe, hors de la
 * transaction de test, par une connexion propriétaire DÉDIÉE (une DDL pendant la transaction
 * attendrait ses verrous) ; `amateo_app` les reçoit `WITH INHERIT FALSE` (il peut `SET LOCAL ROLE`,
 * il n'hérite de rien). Le test bascule la connexion de l'application par `SET LOCAL ROLE` (annulé
 * avec la transaction) : c'est le `current_user` que l'outil doit juger. Tout est retiré en fin de
 * classe ; un reste ferait rougir `RlsIsolationTest` (policies hors canon).
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
        $owner->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN SUPERUSER', self::ROLE_SUPER));
        $owner->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN NOSUPERUSER BYPASSRLS', self::ROLE_BYPASS));
        $owner->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN NOSUPERUSER NOBYPASSRLS IN ROLE amateo_owner', self::ROLE_OWNER));
        $owner->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN NOSUPERUSER NOBYPASSRLS', self::ROLE_LEAK));
        foreach ([self::ROLE_BYPASS, self::ROLE_LEAK] as $role) {
            $owner->executeStatement(\sprintf('GRANT USAGE ON SCHEMA public TO %s', $role));
            $owner->executeStatement(\sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %s', $role));
            $owner->executeStatement(\sprintf('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO %s', $role));
        }
        foreach ($owner->fetchFirstColumn(self::CLUB_ID_TABLES) as $table) {
            $owner->executeStatement(\sprintf('CREATE POLICY %s ON %s FOR SELECT TO %s USING (true)', self::LEAK_POLICY, $owner->quoteIdentifier((string) $table), self::ROLE_LEAK));
        }
        foreach ([self::ROLE_SUPER, self::ROLE_BYPASS, self::ROLE_OWNER, self::ROLE_LEAK] as $role) {
            $owner->executeStatement(\sprintf('GRANT %s TO amateo_app WITH INHERIT FALSE, SET TRUE', $role));
        }
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

    /** Connexion propriétaire HORS du conteneur (et donc hors de toute transaction de test). */
    private static function ownerConnection(): Connection
    {
        $url = $_ENV['DATABASE_ADMIN_URL'] ?? $_SERVER['DATABASE_ADMIN_URL'] ?? getenv('DATABASE_ADMIN_URL');
        self::assertIsString($url, 'DATABASE_ADMIN_URL requis');

        return DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($url));
    }

    private static function dropProbes(Connection $owner): void
    {
        foreach ($owner->fetchFirstColumn(self::CLUB_ID_TABLES) as $table) {
            $owner->executeStatement(\sprintf('DROP POLICY IF EXISTS %s ON %s', self::LEAK_POLICY, $owner->quoteIdentifier((string) $table)));
        }
        foreach ([self::ROLE_SUPER, self::ROLE_BYPASS, self::ROLE_OWNER, self::ROLE_LEAK] as $role) {
            if (false !== $owner->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role])) {
                $owner->executeStatement(\sprintf('DROP OWNED BY %s', $role));
                $owner->executeStatement(\sprintf('DROP ROLE %s', $role));
            }
        }
    }

    #[DataProvider('bypassingRoles')]
    public function testAConnectionThatBypassesClubSecurityIsRefused(string $role, string $reason): void
    {
        $connection = $this->erEm()->getConnection();
        $connection->executeStatement(\sprintf('SET LOCAL ROLE %s', $role));
        // Le refus attendu a un sens : sous ce rôle, une table de club se lit sans club posé.
        $this->clearGuc();
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT count(*) FROM team'), $role . ' doit voir les équipes sans club posé — sinon le refus ne prouve rien');
        $this->scopeGucToClub(self::ER_CLUB);

        $out = $this->newOutDir();
        $tester = $this->tester();
        $code = $tester->execute(['--out' => $out, '--club' => [self::ER_CLUB]]);
        $connection->executeStatement('RESET ROLE');

        self::assertSame(3, $code, $role . ' : sortie 3 attendue. ' . $tester->getDisplay());
        self::assertStringContainsString($reason, $tester->getDisplay(), 'le motif du refus est nommé');
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
