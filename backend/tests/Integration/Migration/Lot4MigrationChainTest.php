<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\EngineRequestDecorTrait;
use App\Tests\EngineRequestPlanSettingsDecorTrait;
use App\Tests\EngineRequestRunnerTrait;
use App\Tests\EngineRequestTravelDecorTrait;
use App\Tests\Integration\EngineRequestReferenceTest;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Promesse : les données d'un club écrites avant le lot 4, une fois toutes les migrations du lot jouées,
 * donnent au moteur les mêmes quinze demandes qu'avant (axe *chaîne de génération*, plan du lot 4 §4 PR 1).
 *
 * Pour chaque référence de {@see EngineRequestReferenceTest} : une base neuve, copiée d'un modèle chargé
 * depuis le schéma figé d'avant le lot (`tests/Fixtures/lot-4/schema-avant.sql`, table des versions de
 * migration comprise), reçoit le décor de cette référence écrit en SQL dans ce schéma (`decor-avant.sql`,
 * une section par référence) ; `doctrine:migrations:migrate` y joue tout ce qui manque ; puis le code de la
 * tête compose la demande sur cette base, par le même rail que la référence, et la compare au même fichier.
 *
 * `LOT_MIGRATIONS` nomme les migrations que la chaîne doit jouer, ni plus ni moins : zéro en PR 1, une de
 * plus à chaque PR du lot. Une migration ajoutée sans être nommée ici, ou nommée sans être jouée, rougit.
 *
 * Pourquoi une base dédiée plutôt que la transaction annulée des autres tests de migration : celles du
 * lot n'ont pas de `down()`, et c'est la seule base qui porte les réglages de période à convertir (la copie
 * de production n'en a aucun en cours). Hors transaction de test (`SkipDatabaseRollback`) : la base du cas
 * est détruite à la fin de chaque cas, le modèle à la fin de la classe ; la base de test n'est pas touchée.
 *
 * Bloquant (step `Blocking suite` de `ci.yml`, `docs/testing/blocking-tests.md`) : le groupe `phase1` est requis.
 */
#[Group('phase1')]
#[Group('integration')]
#[SkipDatabaseRollback]
final class Lot4MigrationChainTest extends KernelTestCase
{
    use EngineRequestDecorTrait;
    use EngineRequestPlanSettingsDecorTrait;
    use EngineRequestRunnerTrait;
    use EngineRequestTravelDecorTrait;

    /** Les migrations du lot 4 que la chaîne joue, dans l'ordre. */
    private const array LOT_MIGRATIONS = [];

    private const string SCHEMA_FILE = __DIR__ . '/../../Fixtures/lot-4/schema-avant.sql';

    private const array DATABASE_VARIABLES = ['DATABASE_URL', 'DATABASE_ADMIN_URL'];

    private static ?string $testDatabase = null;

    private static ?string $caseDatabase = null;

    /** @var array<string, string> les URL de la base de test, rendues à la fin de chaque cas */
    private array $testUrls = [];

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function references(): iterable
    {
        $nord = 'be0e0000-0000-4000-8000-000000000001';
        $cases = [];
        $cases[] = ['socle-1-premiere-generation-brouillon', 'generation', ['schedule' => self::ER_SOCLE_V1]];
        $cases[] = ['socle-2-regeneration-version-chargee-verrou', 'generation', ['schedule' => self::ER_SOCLE_V2, 'source' => self::ER_SOCLE_V1]];
        $cases[] = ['periode-3-generation-sur-place-verrou-garder-creneaux', 'generation', ['schedule' => self::ER_PERIOD_P1]];
        $cases[] = ['periode-4-comblement', 'generation', ['schedule' => self::ER_PERIOD_P2, 'fill' => self::ER_PERIOD_P1]];
        $cases[] = ['placement-5-club-entier', 'placement', []];
        $cases[] = ['placement-6-fenetre', 'placement', ['window' => ['from' => '2026-01-12', 'to' => '2026-01-18']]];
        $cases[] = ['socle-7-regles-de-groupe-et-jour-de-match', 'generation', ['schedule' => self::ER_SOCLE_V1]];
        $cases[] = ['periode-8-regle-de-groupe', 'generation', ['schedule' => self::ER_PERIOD_P1]];
        $cases[] = ['socle-9-trajets-saison-suivante', 'generation', ['schedule' => self::ER_NEXT_SOCLE_V1]];
        $cases[] = ['periode-10-trajets', 'generation', ['schedule' => self::ER_PERIOD_P1]];
        $cases[] = ['socle-11-socle-complet', 'generation', ['schedule' => self::ER_SOCLE_V1]];
        $cases[] = ['periode-12-vacances-reglages', 'generation', ['schedule' => self::ER_HOLIDAY_V1]];
        $cases[] = ['periode-13-fermeture-reglages', 'generation', ['schedule' => self::ER_CLOSURE_V1]];
        $cases[] = ['validation-14-deplacement-socle', 'validation', ['slot' => self::erId('5107', 2), 'target' => [1, '19:30', $nord]]];
        $cases[] = ['validation-15-deplacement-periode', 'validation', ['slot' => self::erId('5107', 12), 'target' => [1, '18:00', $nord]]];

        foreach ($cases as $case) {
            yield $case[0] => $case;
        }
    }

    /** Le modèle : une base neuve chargée du schéma figé, copiée ensuite pour chaque cas. */
    public static function setUpBeforeClass(): void
    {
        $server = self::maintenanceConnection();
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::templateName()));
        $server->executeStatement(\sprintf('CREATE DATABASE %s', self::templateName()));
        $server->close();

        $template = self::ownerConnection(self::templateName());
        $template->executeStatement((string) file_get_contents(self::SCHEMA_FILE));
        $template->close();
    }

    public static function tearDownAfterClass(): void
    {
        $server = self::maintenanceConnection();
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::caseName()));
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::templateName()));
        $server->close();
    }

    private static function maintenanceConnection(): Connection
    {
        return self::ownerConnection('postgres');
    }

    private static function ownerConnection(string $database): Connection
    {
        $params = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])->parse(self::withDatabase(self::variable('DATABASE_ADMIN_URL'), $database));

        return DriverManager::getConnection($params);
    }

    /** Le nom de la base de test, suffixé : la chaîne ne touche jamais la base de test elle-même. */
    private static function templateName(): string
    {
        return self::testDatabaseName() . '_lot4_avant';
    }

    private static function caseName(): string
    {
        return self::$caseDatabase ??= self::testDatabaseName() . '_lot4_cas';
    }

    /** Lu une fois, avant que l'URL ne soit branchée sur la base du cas. */
    private static function testDatabaseName(): string
    {
        if (null === self::$testDatabase) {
            $path = parse_url(self::variable('DATABASE_ADMIN_URL'), \PHP_URL_PATH);
            self::assertIsString($path);
            self::$testDatabase = ltrim($path, '/');
            self::assertMatchesRegularExpression('/^[a-z0-9_]+_test$/', self::$testDatabase, 'la chaîne ne tourne que sur une base de test');
        }

        return self::$testDatabase;
    }

    private static function withDatabase(string $url, string $database): string
    {
        $replaced = preg_replace('~/[^/?]+(\?.*)?$~', '/' . $database . '$1', $url);
        self::assertIsString($replaced);

        return $replaced;
    }

    private static function variable(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        self::assertIsString($value, \sprintf('%s doit être posée', $name));

        return $value;
    }

    /**
     * @param 'generation'|'placement'|'validation' $rail
     * @param array<string, mixed>                  $arguments
     */
    #[DataProvider('references')]
    public function testTheMigratedDecorGivesTheReferenceRequest(string $reference, string $rail, array $arguments): void
    {
        $played = $this->migrate();
        self::assertSame(self::LOT_MIGRATIONS, $played, 'la chaîne joue exactement les migrations du lot');

        $this->scopeGucToClub(self::ER_CLUB);
        $body = match ($rail) {
            'generation' => $this->runGeneration((string) $arguments['schedule'], $arguments['source'] ?? null, $arguments['fill'] ?? null)['body'],
            'placement' => $this->runPlacement($arguments['window'] ?? null),
            'validation' => $this->runMoveValidation((string) $arguments['slot'], $arguments['target']),
        };

        $this->assertReference($reference, $body);
    }

    /** La base du cas (copie du modèle et décor de sa référence), puis le noyau branché sur elle. */
    protected function setUp(): void
    {
        $reference = $this->providedData()[0] ?? null;
        self::assertIsString($reference);
        $sections = self::readDecorSections();
        self::assertArrayHasKey($reference, $sections, \sprintf('decor-avant.sql n\'a pas de section pour la référence %s', $reference));

        $server = self::maintenanceConnection();
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::caseName()));
        $server->executeStatement(\sprintf('CREATE DATABASE %s TEMPLATE %s', self::caseName(), self::templateName()));
        $server->close();

        $case = self::ownerConnection(self::caseName());
        $case->executeStatement("SET session_replication_role = replica;\n" . $sections[$reference] . "\nSET session_replication_role = DEFAULT;");
        $case->close();

        self::ensureKernelShutdown();
        foreach (self::DATABASE_VARIABLES as $variable) {
            $this->testUrls[$variable] = self::variable($variable);
            $this->setVariable($variable, self::withDatabase($this->testUrls[$variable], self::caseName()));
        }
        self::bootKernel();
        $this->pinEngineRequestClock();
    }

    protected function tearDown(): void
    {
        if (self::$booted) {
            $this->unpinEngineRequestClock();
        }
        self::ensureKernelShutdown();
        foreach ($this->testUrls as $variable => $url) {
            $this->setVariable($variable, $url);
        }
        $this->testUrls = [];
        $server = self::maintenanceConnection();
        $server->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::caseName()));
        $server->close();
        parent::tearDown();
    }

    private function setVariable(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }

    /**
     * Joue `doctrine:migrations:migrate` sur la base du cas et rend les versions jouées.
     *
     * @return list<string>
     */
    private function migrate(): array
    {
        $connection = $this->erEm()->getConnection();
        self::assertSame(self::$caseDatabase, $connection->fetchOne('SELECT current_database()'), 'le noyau compose sur la base du cas, jamais sur la base de test');
        $before = $connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');

        $console = new Application(self::$kernel ?? throw new LogicException('noyau non démarré'));
        $console->setAutoExit(false);
        $output = new BufferedOutput;
        $code = $console->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true]), $output);
        self::assertSame(0, $code, 'les migrations se jouent sur le décor d\'avant le lot : ' . $output->fetch());

        $after = $connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');

        return array_values(array_map(strval(...), array_diff($after, $before)));
    }
}
