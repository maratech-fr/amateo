<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Service\ImplicitRuleResolver;
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
use PHPUnit\Framework\Attributes\TestWith;
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

    /**
     * Les migrations du lot 4 que la chaîne joue, dans l'ordre.
     *
     * PR 2 « le socle a son plan » : plan de saison créé s'il manque, posé sur les lignes à plan nul des six
     * familles, `NOT NULL` et clé en cascade vers le plan, pointeur de version chargée recopié sur le plan puis
     * `season.live_context_schedule_id` supprimée, photos converties, règles de bien-être des plans anciens
     * matérialisées ; s'annule si un compte diffère ou si une ligne portait déjà le plan de saison.
     */
    private const array LOT_MIGRATIONS = ['DoctrineMigrations\Version20261127120000'];

    /** Les six familles dont « plan nul » voulait dire « socle » avant le lot 4. */
    private const array SOCLE_TABLES = ['venue_training_slot', 'venue_occupancy', 'reservation', 'shared_training_block', 'shared_training_block_team', 'implicit_rule_setting'];

    /** Le club et la saison du décor des références (`EngineRequestDecorTrait`). */
    private const string DECOR_CLUB = 'c10b0000-0000-4000-8000-000000000001';

    private const string DECOR_SEASON = '5ea50000-0000-4000-8000-000000000001';

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

    /**
     * La conversion de la PR 2 : chaque ligne du socle des six familles reçoit le plan de saison de sa saison,
     * une ligne de période garde son plan, aucune ligne n'est perdue ; la colonne devient obligatoire, avec une
     * clé en cascade vers le plan ; le pointeur de version chargée du socle passe de la saison à son plan.
     * Trois décors : le socle complet (occupation, groupe mutualisé), des vacances (pointeur posé), une
     * fermeture (groupes de période).
     */
    #[TestWith(['socle-11-socle-complet'])]
    #[TestWith(['periode-12-vacances-reglages'])]
    #[TestWith(['periode-13-fermeture-reglages'])]
    public function testTheConversionGivesEverySocleRowItsSeasonPlan(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $rowsBefore = [];
        foreach (self::SOCLE_TABLES as $table) {
            $rowsBefore[$table] = $this->planOfEachRow($admin, $table);
        }
        $pointerBefore = $admin->fetchOne('SELECT live_context_schedule_id FROM season WHERE id = ?', [self::DECOR_SEASON]);
        self::assertNotSame([], $rowsBefore['venue_training_slot'], 'témoin : le décor a une grille de socle à convertir');

        self::assertSame(self::LOT_MIGRATIONS, $this->migrate(), 'la chaîne joue exactement les migrations du lot');

        $seasonPlan = $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type = ?', [self::DECOR_SEASON, 'SEASON']);
        self::assertIsString($seasonPlan, 'la saison a son plan de saison');
        foreach (self::SOCLE_TABLES as $table) {
            $expected = array_map(static fn (?string $plan): string => $plan ?? $seasonPlan, $rowsBefore[$table]);
            self::assertSame($expected, $this->planOfEachRow($admin, $table), \sprintf('%s : chaque ligne du socle porte le plan de saison, chaque ligne de période garde le sien, aucune ne manque', $table));
            self::assertSame('NO', $admin->fetchOne('SELECT is_nullable FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = ? AND column_name = \'schedule_plan_id\'', [$table]), \sprintf('%s.schedule_plan_id est obligatoire', $table));
            self::assertSame(1, (int) $admin->fetchOne(
                'SELECT COUNT(*) FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)'
                . ' WHERE c.contype = \'f\' AND c.conrelid = (\'public.\' || ?)::regclass AND c.confrelid = \'public.schedule_plan\'::regclass'
                . ' AND a.attname = \'schedule_plan_id\' AND c.confdeltype = \'c\'',
                [$table],
            ), \sprintf('%s : une clé vers le plan, en cascade', $table));
        }

        self::assertFalse($this->columnExists($admin, 'season', 'live_context_schedule_id'), 'le pointeur de la saison est supprimé : un seul pointeur, sur le plan');
        self::assertSame(false === $pointerBefore ? null : $pointerBefore, $admin->fetchOne('SELECT lock_source_schedule_id FROM schedule_plan WHERE id = ?', [$seasonPlan]), 'la version chargée du socle est recopiée sur son plan');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_index i WHERE i.indrelid = \'public.schedule_plan\'::regclass AND i.indisunique AND i.indpred IS NULL'
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey::int2[])) = ARRAY[\'club_id\', \'id\']',
        ), 'schedule_plan porte l\'unicité (id, club_id) que les clés composées des tables à venir viseront');
    }

    /**
     * Une saison sans plan de saison (née avant les plans) en reçoit un à la conversion, et sa grille le porte.
     */
    #[TestWith(['socle-11-socle-complet'])]
    public function testTheConversionCreatesTheMissingSeasonPlan(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $orphanSeason = 'e1a50000-0000-4000-8000-000000000027';
        $orphanSlot = 'e1a50000-0000-4000-8000-0000000005a7';
        $admin->executeStatement(
            'INSERT INTO season SELECT * FROM json_populate_record(NULL::season, (SELECT (row_to_json(s)::jsonb || jsonb_build_object(\'id\', ?::text, \'name\', \'2026-2027\', \'start_date\', \'2026-08-01\', \'end_date\', \'2027-07-15\', \'live_context_schedule_id\', NULL))::json FROM season s WHERE s.id = ?))',
            [$orphanSeason, self::DECOR_SEASON],
        );
        $admin->executeStatement(
            'INSERT INTO venue_training_slot SELECT * FROM json_populate_record(NULL::venue_training_slot, (SELECT (row_to_json(v)::jsonb || jsonb_build_object(\'id\', ?::text, \'season_id\', ?::text))::json FROM venue_training_slot v WHERE v.season_id = ? AND v.schedule_plan_id IS NULL LIMIT 1))',
            [$orphanSlot, $orphanSeason, self::DECOR_SEASON],
        );
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM schedule_plan WHERE season_id = ?', [$orphanSeason]), 'témoin : la saison ajoutée n\'a pas de plan');

        self::assertSame(self::LOT_MIGRATIONS, $this->migrate(), 'la chaîne joue exactement les migrations du lot');

        $plans = $admin->fetchAllAssociative('SELECT id, club_id, type FROM schedule_plan WHERE season_id = ?', [$orphanSeason]);
        self::assertCount(1, $plans, 'la saison a un plan, un seul');
        self::assertSame(['club_id' => self::DECOR_CLUB, 'type' => 'SEASON'], ['club_id' => $plans[0]['club_id'], 'type' => $plans[0]['type']], 'c\'est le plan de saison du club');
        self::assertSame($plans[0]['id'], $admin->fetchOne('SELECT schedule_plan_id FROM venue_training_slot WHERE id = ?', [$orphanSlot]), 'la grille de cette saison porte ce plan');
    }

    /**
     * Les photos des versions sont converties : une ligne de grille ou de réservation du socle (plan nul) porte
     * le plan de saison, une ligne de période garde le sien, et rien d'autre de la photo ne change.
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    public function testTheConversionRewritesThePhotosOnTheSeasonPlan(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $periodPlan = $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type <> ?', [self::DECOR_SEASON, 'SEASON']);
        self::assertIsString($periodPlan, 'témoin : le décor a un plan de période');
        $photo = [
            'Team' => [['id' => '7ea00000-0000-4000-8000-000000000001', 'name' => 'U13', 'sessionsPerWeek' => 2]],
            'VenueTrainingSlot' => [
                ['id' => 'f0700000-0000-4000-8000-000000000001', 'dayOfWeek' => 1, 'schedulePlanId' => null],
                ['id' => 'f0700000-0000-4000-8000-000000000002', 'dayOfWeek' => 2, 'schedulePlanId' => $periodPlan],
            ],
            'Reservation' => [['id' => 'f0700000-0000-4000-8000-000000000003', 'teamId' => '7ea00000-0000-4000-8000-000000000001', 'schedulePlanId' => null]],
        ];
        $admin->executeStatement(
            'INSERT INTO schedule_structure_snapshot (id, created_at, updated_at, club_id, season_id, schedule_id, data) VALUES (?, now(), now(), ?, ?, ?, ?)',
            ['f0700000-0000-4000-8000-0000000000aa', self::DECOR_CLUB, self::DECOR_SEASON, '5c4e0000-0000-4000-8000-000000000001', json_encode($photo, \JSON_THROW_ON_ERROR)],
        );

        self::assertSame(self::LOT_MIGRATIONS, $this->migrate(), 'la chaîne joue exactement les migrations du lot');

        $seasonPlan = $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type = ?', [self::DECOR_SEASON, 'SEASON']);
        $converted = json_decode((string) $admin->fetchOne('SELECT data FROM schedule_structure_snapshot WHERE id = ?', ['f0700000-0000-4000-8000-0000000000aa']), true, 512, \JSON_THROW_ON_ERROR);
        $expected = $photo;
        $expected['VenueTrainingSlot'][0]['schedulePlanId'] = $seasonPlan;
        $expected['Reservation'][0]['schedulePlanId'] = $seasonPlan;
        self::assertSame($expected, $converted, 'la photo porte le plan de saison sur ses lignes de socle, et rien d\'autre ne change');
    }

    /**
     * Décision du fondateur (2026-11-22) : un plan de vacances né avant les copies de règles de bien-être les
     * reçoit à la conversion, aux valeurs de la saison du moment, puis ne suit plus la saison.
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    public function testAnOldHolidayPlanKeepsTheWellBeingRulesItHadAtConversion(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $holidayPlan = $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type = ?', [self::DECOR_SEASON, 'HOLIDAY']);
        self::assertIsString($holidayPlan);
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM implicit_rule_setting WHERE schedule_plan_id = ?', [$holidayPlan]), 'témoin : le plan de vacances n\'a aucune règle de bien-être à lui, il suit la saison');

        self::assertSame(self::LOT_MIGRATIONS, $this->migrate(), 'la chaîne joue exactement les migrations du lot');

        $this->scopeGucToClub(self::DECOR_CLUB);
        $resolver = self::getContainer()->get(ImplicitRuleResolver::class);
        $atConversion = $resolver->resolve(self::DECOR_CLUB, self::DECOR_SEASON);
        self::assertSame($atConversion, $resolver->resolveForPlan(self::DECOR_CLUB, self::DECOR_SEASON, $holidayPlan), 'à la conversion, les vacances ont les règles de la saison');
        self::assertGreaterThan(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM implicit_rule_setting WHERE schedule_plan_id = ?', [$holidayPlan]), 'les règles sont écrites sur le plan de vacances');

        $admin->executeStatement(
            'UPDATE implicit_rule_setting SET intensity = CASE WHEN intensity = \'HARD\' THEN \'OFF\' ELSE \'HARD\' END'
            . ' WHERE season_id = ? AND schedule_plan_id = (SELECT id FROM schedule_plan WHERE season_id = ? AND type = \'SEASON\')',
            [self::DECOR_SEASON, self::DECOR_SEASON],
        );
        $this->erEm()->clear();
        self::assertNotSame($atConversion, $resolver->resolve(self::DECOR_CLUB, self::DECOR_SEASON), 'témoin : les règles de la saison ont changé');
        self::assertSame($atConversion, $resolver->resolveForPlan(self::DECOR_CLUB, self::DECOR_SEASON, $holidayPlan), 'les vacances gardent les règles qu\'elles avaient à la conversion');
    }

    /**
     * Décor faussé : une ligne du socle porte déjà l'identifiant du plan de saison (la mesure 5 en attend
     * zéro). La conversion s'annule : rien n'est migré, la base reste celle d'avant.
     */
    #[TestWith(['socle-11-socle-complet'])]
    public function testTheConversionIsCancelledWhenASocleRowAlreadyCarriesTheSeasonPlan(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $admin->executeStatement(
            'UPDATE venue_training_slot SET schedule_plan_id = (SELECT id FROM schedule_plan WHERE season_id = ? AND type = \'SEASON\')'
            . ' WHERE id = (SELECT id FROM venue_training_slot WHERE season_id = ? AND schedule_plan_id IS NULL ORDER BY id LIMIT 1)',
            [self::DECOR_SEASON, self::DECOR_SEASON],
        );
        $nullBefore = (int) $admin->fetchOne('SELECT COUNT(*) FROM venue_training_slot WHERE schedule_plan_id IS NULL');
        self::assertGreaterThan(0, $nullBefore, 'témoin : il reste des lignes de socle à convertir');

        [$code, $output] = $this->runMigrations();

        self::assertNotSame(0, $code, 'la conversion refuse un décor où une ligne porte déjà le plan de saison : ' . $output);
        self::assertSame([], array_values(array_intersect(self::LOT_MIGRATIONS, array_map(strval(...), $admin->fetchFirstColumn('SELECT version FROM doctrine_migration_versions')))), 'la migration n\'est pas enregistrée');
        self::assertTrue($this->columnExists($admin, 'season', 'live_context_schedule_id'), 'le pointeur de la saison est toujours là');
        self::assertSame($nullBefore, (int) $admin->fetchOne('SELECT COUNT(*) FROM venue_training_slot WHERE schedule_plan_id IS NULL'), 'aucune ligne n\'a été convertie');
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

    /** @return array<string, ?string> le plan de chaque ligne, par identifiant */
    private function planOfEachRow(Connection $admin, string $table): array
    {
        $plans = [];
        foreach ($admin->fetchAllAssociative(\sprintf('SELECT id, schedule_plan_id FROM %s ORDER BY id', $table)) as $row) {
            $plans[(string) $row['id']] = null === $row['schedule_plan_id'] ? null : (string) $row['schedule_plan_id'];
        }

        return $plans;
    }

    private function columnExists(Connection $admin, string $table, string $column): bool
    {
        return 1 === (int) $admin->fetchOne('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = ? AND column_name = ?', [$table, $column]);
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

        [$code, $output] = $this->runMigrations();
        self::assertSame(0, $code, 'les migrations se jouent sur le décor d\'avant le lot : ' . $output);

        $after = $connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');

        return array_values(array_map(strval(...), array_diff($after, $before)));
    }

    /**
     * `doctrine:migrations:migrate` sur la base du cas, sans conclure : rend le code retour et la sortie.
     *
     * @return array{0: int, 1: string}
     */
    private function runMigrations(): array
    {
        $console = new Application(self::$kernel ?? throw new LogicException('noyau non démarré'));
        $console->setAutoExit(false);
        $output = new BufferedOutput;
        $code = $console->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true]), $output);

        return [$code, $output->fetch()];
    }
}
