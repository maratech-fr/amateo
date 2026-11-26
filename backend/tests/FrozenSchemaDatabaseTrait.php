<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use LogicException;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Une base dédiée, née d'un schéma figé d'avant une migration irréversible, pour la jouer sur un décor écrit
 * dans ce schéma : un modèle chargé une fois par classe (`FROZEN_SCHEMA_FILE`), copié pour chaque cas, qui
 * reçoit la section de décor nommée par le premier argument du cas (`FROZEN_DECOR_FILE`, format de
 * {@see EngineRequestRunnerTrait::readDecorSections}) ; le noyau est ensuite branché sur la copie.
 *
 * Même mécanique que `Integration/Migration/Lot4MigrationChainTest` (qui garde la sienne, inchangée) ;
 * employée par `Lot7MigrationChainTest` et `CompetitionEngagementMigrationTest`. La classe hôte déclare
 * `FROZEN_SCHEMA_FILE`, `FROZEN_DECOR_FILE` et `FROZEN_DATABASE_SUFFIX`, porte `#[SkipDatabaseRollback]`
 * (la base de test n'est jamais touchée) et appelle `setUpFrozenCase()` / `tearDownFrozenCase()`.
 */
trait FrozenSchemaDatabaseTrait
{
    private const array FROZEN_DATABASE_VARIABLES = ['DATABASE_URL', 'DATABASE_ADMIN_URL'];

    private static ?string $frozenTestDatabase = null;

    /** @var array<string, string> les URL de la base de test, rendues à la fin de chaque cas */
    private array $frozenTestUrls = [];

    public static function setUpBeforeClass(): void
    {
        $server = self::frozenOwnerConnection('postgres');
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::frozenTemplateName()));
        $server->executeStatement(\sprintf('CREATE DATABASE %s', self::frozenTemplateName()));
        $server->close();

        $template = self::frozenOwnerConnection(self::frozenTemplateName());
        $template->executeStatement((string) file_get_contents(self::FROZEN_SCHEMA_FILE));
        $template->close();
    }

    public static function tearDownAfterClass(): void
    {
        $server = self::frozenOwnerConnection('postgres');
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::frozenCaseName()));
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::frozenTemplateName()));
        $server->close();
    }

    /** @return array<string, string> nom de section (`-- @reference <nom>`) → son SQL */
    private static function frozenDecorSections(): array
    {
        $sections = [];
        $current = null;
        foreach (explode("\n", (string) file_get_contents(self::FROZEN_DECOR_FILE)) as $line) {
            if (str_starts_with($line, '-- @reference ')) {
                $current = substr($line, \strlen('-- @reference '));
                $sections[$current] = '';

                continue;
            }
            if (null === $current || '' === $line) {
                continue;
            }
            $sections[$current] .= ('' === $sections[$current] ? '' : "\n") . $line;
        }

        return $sections;
    }

    /** La connexion propriétaire (hors RLS applicative) sur la base du cas. */
    private static function frozenCaseConnection(): Connection
    {
        return self::frozenOwnerConnection(self::frozenCaseName());
    }

    private static function frozenColumnExists(Connection $admin, string $table, string $column): bool
    {
        return 1 === (int) $admin->fetchOne('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = ? AND column_name = ?', [$table, $column]);
    }

    private static function frozenOwnerConnection(string $database): Connection
    {
        $params = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])->parse(self::frozenWithDatabase(self::frozenVariable('DATABASE_ADMIN_URL'), $database));

        return DriverManager::getConnection($params);
    }

    private static function frozenTemplateName(): string
    {
        return self::frozenTestDatabaseName() . '_' . self::FROZEN_DATABASE_SUFFIX . '_avant';
    }

    private static function frozenCaseName(): string
    {
        return self::frozenTestDatabaseName() . '_' . self::FROZEN_DATABASE_SUFFIX . '_cas';
    }

    /** Lu une fois, avant que l'URL ne soit branchée sur la base du cas. */
    private static function frozenTestDatabaseName(): string
    {
        if (null === self::$frozenTestDatabase) {
            $path = parse_url(self::frozenVariable('DATABASE_ADMIN_URL'), \PHP_URL_PATH);
            Assert::assertIsString($path);
            self::$frozenTestDatabase = ltrim($path, '/');
            Assert::assertMatchesRegularExpression('/^[a-z0-9_]+_test$/', self::$frozenTestDatabase, 'la base dédiée ne naît qu\'à côté d\'une base de test');
        }

        return self::$frozenTestDatabase;
    }

    private static function frozenWithDatabase(string $url, string $database): string
    {
        $replaced = preg_replace('~/[^/?]+(\?.*)?$~', '/' . $database . '$1', $url);
        Assert::assertIsString($replaced);

        return $replaced;
    }

    private static function frozenVariable(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        Assert::assertIsString($value, \sprintf('%s doit être posée', $name));

        return $value;
    }

    private static function frozenSetVariable(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }

    /** La base du cas (copie du modèle et section de décor nommée), puis le noyau branché sur elle. */
    private function setUpFrozenCase(string $section): void
    {
        $sections = self::frozenDecorSections();
        Assert::assertArrayHasKey($section, $sections, \sprintf('%s n\'a pas de section %s', basename(self::FROZEN_DECOR_FILE), $section));

        $server = self::frozenOwnerConnection('postgres');
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::frozenCaseName()));
        $server->executeStatement(\sprintf('CREATE DATABASE %s TEMPLATE %s', self::frozenCaseName(), self::frozenTemplateName()));
        $server->close();

        $case = self::frozenOwnerConnection(self::frozenCaseName());
        $case->executeStatement("SET session_replication_role = replica;\n" . $sections[$section] . "\nSET session_replication_role = DEFAULT;");
        $case->close();

        self::ensureKernelShutdown();
        foreach (self::FROZEN_DATABASE_VARIABLES as $variable) {
            $this->frozenTestUrls[$variable] = self::frozenVariable($variable);
            self::frozenSetVariable($variable, self::frozenWithDatabase($this->frozenTestUrls[$variable], self::frozenCaseName()));
        }
        self::bootKernel();
    }

    private function tearDownFrozenCase(): void
    {
        self::ensureKernelShutdown();
        foreach ($this->frozenTestUrls as $variable => $url) {
            self::frozenSetVariable($variable, $url);
        }
        $this->frozenTestUrls = [];
        $server = self::frozenOwnerConnection('postgres');
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::frozenCaseName()));
        $server->close();
    }

    /**
     * `doctrine:migrations:migrate` sur la base du cas, sans conclure : code retour, sortie, versions jouées.
     *
     * @return array{0: int, 1: string, 2: list<string>}
     */
    private function runFrozenMigrations(): array
    {
        $admin = self::frozenCaseConnection();
        $before = array_map(strval(...), $admin->fetchFirstColumn('SELECT version FROM doctrine_migration_versions'));
        $console = new Application(self::$kernel ?? throw new LogicException('noyau non démarré'));
        $console->setAutoExit(false);
        $output = new BufferedOutput;
        $code = $console->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true]), $output);
        $after = array_map(strval(...), $admin->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version'));
        $admin->close();

        return [$code, $output->fetch(), array_values(array_diff($after, $before))];
    }
}
