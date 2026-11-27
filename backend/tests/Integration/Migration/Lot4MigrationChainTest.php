<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Plans\PlanStates;
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
     *
     * PR 3a « les équipes d'un plan » : une ligne `plan_team` par (plan, équipe), socle compris ; supprime
     * `team_period_override`, `team.sessions_per_week`, `team.max_sessions_per_day` et
     * `schedule_plan.team_selection_initialized` ; photos converties ; s'annule si `lignes ≠ équipes × plans`.
     *
     * PR 3b « les gymnases d'une période » : une ligne `plan_venue` par (plan de période, gymnase), aucune au plan de
     * saison ; supprime `venue_period_override` ; s'annule si `lignes ≠ gymnases × plans de période`.
     *
     * PR 4 « les règles appliquées » : une ligne `plan_constraint` par (plan de période, contrainte permanente), aucune
     * au plan de saison ni pour une contrainte datée ; supprime `constraint_period_override` ; s'annule si
     * `lignes ≠ contraintes permanentes × plans de période`.
     *
     * Lot 5, PR 2 « une version copie tout le froid » : table `schedule_state` (une copie par version, clé en cascade
     * vers la version, RLS forcée) ; elle ne copie rien elle-même — la capture est la commande de conversion
     * (`app:plans:capture-version-states`), jouée par la procédure manuelle du lot.
     *
     * Lot 5, PR 3 « Charger : une seule définition » : suppression de la table des photos `schedule_structure_snapshot`,
     * sans retour (testTheChainDropsThePhotoTable).
     */
    private const array LOT_MIGRATIONS = ['DoctrineMigrations\Version20261127120000', 'DoctrineMigrations\Version20261128120000', 'DoctrineMigrations\Version20261129120000', 'DoctrineMigrations\Version20261130120000', 'DoctrineMigrations\Version20261201120000', 'DoctrineMigrations\Version20261202120000', 'DoctrineMigrations\Version20261203120000', 'DoctrineMigrations\Version20261204120000', 'DoctrineMigrations\Version20261205120000'];

    /**
     * Les contraintes permanentes du décor des références 12 et 13 (`EngineRequestDecorTrait`,
     * `EngineRequestPlanSettingsDecorTrait`) : quatre de base (trois d'équipe, une de coach), puis une de club (12) ou
     * une d'équipe créée après la naissance de la fermeture (13).
     */
    private const array DECOR_RULES = [
        'équipe 1' => 'c0570000-0000-4000-8000-000000000001',
        'équipe 2' => 'c0570000-0000-4000-8000-000000000002',
        'équipe 3' => 'c0570000-0000-4000-8000-000000000003',
        'coach 4' => 'c0570000-0000-4000-8000-000000000004',
        'club 31' => 'c0570000-0000-4000-8000-000000000031',
        'équipe 42' => 'c0570000-0000-4000-8000-000000000042',
    ];

    /** Les gymnases du décor des références 12 et 13 (`EngineRequestDecorTrait`). */
    private const array DECOR_VENUES = [
        'Gymnase Nord' => 'be0e0000-0000-4000-8000-000000000001',
        'Gymnase Sud' => 'be0e0000-0000-4000-8000-000000000002',
    ];

    /** Les équipes du décor des références 12 et 13, par nom (`EngineRequestDecorTrait`). */
    private const array DECOR_TEAMS = [
        'U13 F' => '7ea00000-0000-4000-8000-000000000001',
        'U15 M' => '7ea00000-0000-4000-8000-000000000002',
        'SM1' => '7ea00000-0000-4000-8000-000000000003',
        'U17 M' => '7ea00000-0000-4000-8000-000000000031',
    ];

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
            // Seules les lignes d'avant se comparent : la conversion en ajoute (règles de bien-être d'un plan ancien).
            self::assertSame($expected, array_intersect_key($this->planOfEachRow($admin, $table), $expected), \sprintf('%s : chaque ligne du socle porte le plan de saison, chaque ligne de période garde le sien, aucune ne manque', $table));
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
     * Lot 5, PR 3 (ligne 19 du plan) : la chaîne supprime la table des photos de structure, sans retour (aucun
     * `down()`), même quand elle porte des photos — la copie d'état (`schedule_state`) l'a remplacée. Les deux
     * conversions de photos des PR 2 et 3a ne sont plus vérifiées ici : leur résultat part avec la table, dans la
     * même mise en production, et rien ne le lit plus.
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    public function testTheChainDropsThePhotoTable(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $admin->executeStatement(
            'INSERT INTO schedule_structure_snapshot (id, created_at, updated_at, club_id, season_id, schedule_id, data) VALUES (?, now(), now(), ?, ?, ?, ?)',
            ['f0700000-0000-4000-8000-0000000000aa', self::DECOR_CLUB, self::DECOR_SEASON, '5c4e0000-0000-4000-8000-000000000001', json_encode(['Team' => []], \JSON_THROW_ON_ERROR)],
        );
        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM schedule_structure_snapshot'), 'témoin : avant la chaîne, la table porte une photo');

        self::assertSame(self::LOT_MIGRATIONS, $this->migrate(), 'la chaîne joue exactement les migrations du lot');

        self::assertNull($admin->fetchOne('SELECT to_regclass(\'public.schedule_structure_snapshot\')'), 'la table des photos n\'existe plus');
    }

    /**
     * Lot 4, PR 3a (ligne 13 du plan) : la conversion donne une ligne `plan_team` à chaque couple (plan, équipe) de la
     * saison, socle compris. Le socle reçoit les valeurs de l'équipe ; un plan de période reçoit son exception si elle
     * existe, champ par champ, sinon la valeur du socle du jour. Table d'exception, colonnes de l'équipe et drapeau de
     * pré-sélection disparaissent ; la table porte son unicité, sa clé composée en cascade vers le plan et sa RLS.
     *
     * Ligne 20 du plan, deux plans de vacances de plus dans le décor, jamais ouverts (drapeau à faux) : celui qui a déjà
     * une version garde toutes ses équipes, pour ne pas changer un planning déjà fait ; celui qui n'en a aucune reçoit la
     * pré-sélection (les deux premiers rangs : SM1 rang 1, U15 M et U17 M rang 2 ; U13 F, rang 3, écartée).
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    public function testTheConversionGivesEveryTeamALineInEveryPlan(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $seasonPlan = (string) $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type = ?', [self::DECOR_SEASON, 'SEASON']);
        $openedHoliday = (string) $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type = ? AND team_selection_initialized', [self::DECOR_SEASON, 'HOLIDAY']);
        self::assertNotSame('', $openedHoliday, 'témoin : le décor a un plan de vacances déjà ouvert');
        [$withVersion, $withoutVersion] = $this->addTwoNeverOpenedHolidayPlans($admin, $openedHoliday);
        // Le référentiel des rangs, tel qu'en production (S, A, B, C, D) : le schéma figé l'a vide.
        $admin->executeStatement(
            'INSERT INTO priority_tier (id, version, created_at, updated_at, label, name, color, or_tools_weight, default_min_sessions)'
            . ' VALUES (1, 1, now(), now(), \'S\', \'Elite\', \'#FFD700\', 10000, 3), (2, 1, now(), now(), \'A\', \'Régional+\', \'#C0C0C0\', 1000, 2),'
            . ' (3, 1, now(), now(), \'B\', \'Régional\', \'#CD7F32\', 100, 2), (4, 1, now(), now(), \'C\', \'Départemental\', \'#3498DB\', 10, 2),'
            . ' (5, 1, now(), now(), \'D\', \'Loisir\', \'#95A5A6\', 1, 1) ON CONFLICT (id) DO NOTHING',
        );
        $teams = $admin->fetchAllAssociativeIndexed('SELECT id, sessions_per_week, max_sessions_per_day FROM team WHERE season_id = ? ORDER BY id', [self::DECOR_SEASON]);
        self::assertSame(array_values(self::DECOR_TEAMS), array_keys($teams), 'témoin : les quatre équipes du décor');
        $plans = (int) $admin->fetchOne('SELECT COUNT(*) FROM schedule_plan WHERE season_id = ?', [self::DECOR_SEASON]);
        self::assertSame(4, $plans, 'témoin : socle, vacances ouvertes, et les deux plans jamais ouverts');

        self::assertSame(self::LOT_MIGRATIONS, $this->migrate(), 'la chaîne joue exactement les migrations du lot');

        self::assertSame(\count($teams) * $plans, (int) $admin->fetchOne('SELECT COUNT(*) FROM plan_team WHERE season_id = ?', [self::DECOR_SEASON]), 'une ligne par (plan, équipe), ni plus ni moins');
        self::assertSame(0, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM plan_team l JOIN schedule_plan p ON p.id = l.schedule_plan_id WHERE l.club_id <> p.club_id OR l.season_id <> p.season_id',
        ), 'chaque ligne porte le club et la saison de son plan');

        $socle = [];
        foreach ($teams as $id => $team) {
            $socle[$id] = [true, (int) $team['sessions_per_week'], (int) $team['max_sessions_per_day'], null];
        }
        self::assertSame($socle, $this->linesOf($admin, $seasonPlan), 'le socle reçoit les séances et le plafond de chaque équipe');

        $u13f = self::DECOR_TEAMS['U13 F'];
        $u15m = self::DECOR_TEAMS['U15 M'];
        $sm1 = self::DECOR_TEAMS['SM1'];
        $opened = $socle;
        $opened[$u13f][0] = false;   // exception : écartée, séances et plafond nuls → ceux du socle du jour
        $opened[$u15m][1] = 1;       // exception : 1 séance
        $opened[$sm1][2] = 2;        // exception : plafond 2
        self::assertSame($opened, $this->linesOf($admin, $openedHoliday), 'le plan de vacances ouvert garde ses exceptions champ par champ, le reste vaut le socle du jour (U17 M, créée après la naissance, participe)');

        self::assertSame($socle, $this->linesOf($admin, $withVersion), 'un plan de vacances jamais ouvert qui a déjà une version garde toutes ses équipes');

        $preselected = $socle;
        $preselected[$u13f][0] = false;
        self::assertSame($preselected, $this->linesOf($admin, $withoutVersion), 'un plan de vacances jamais ouvert, sans version, reçoit la pré-sélection : les deux premiers rangs');

        self::assertNull($admin->fetchOne('SELECT to_regclass(\'public.team_period_override\')'), 'la table d\'exception est supprimée');
        self::assertFalse($this->columnExists($admin, 'team', 'sessions_per_week'), 'les séances quittent l\'équipe');
        self::assertFalse($this->columnExists($admin, 'team', 'max_sessions_per_day'), 'le plafond quitte l\'équipe');
        self::assertFalse($this->columnExists($admin, 'schedule_plan', 'team_selection_initialized'), 'le drapeau de pré-sélection est supprimé');
        foreach (['participates', 'sessions_per_week', 'max_sessions_per_day'] as $column) {
            self::assertSame('NO', $admin->fetchOne('SELECT is_nullable FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'plan_team\' AND column_name = ?', [$column]), \sprintf('plan_team.%s est obligatoire', $column));
        }
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_index i WHERE i.indrelid = \'public.plan_team\'::regclass AND i.indisunique AND i.indpred IS NULL'
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey::int2[])) = ARRAY[\'schedule_plan_id\', \'team_id\']',
        ), 'une ligne par couple (plan, équipe)');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_constraint c WHERE c.contype = \'f\' AND c.conrelid = \'public.plan_team\'::regclass'
            . ' AND c.confrelid = \'public.schedule_plan\'::regclass AND c.confdeltype = \'c\''
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)) = ARRAY[\'club_id\', \'schedule_plan_id\']',
        ), 'une clé composée (plan, club) vers le plan, en cascade : une ligne ne désigne jamais le plan d\'un autre club');
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM pg_constraint c WHERE c.contype = \'f\' AND c.conrelid = \'public.plan_team\'::regclass AND c.confrelid = \'public.team\'::regclass'), 'pas de clé vers l\'équipe : « Charger » efface puis réinsère les équipes (E6)');
        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM pg_class WHERE oid = \'public.plan_team\'::regclass AND relrowsecurity AND relforcerowsecurity'), 'la RLS est active et forcée');
    }

    /**
     * Lot 4, PR 3a, décor faussé : deux exceptions pour le même couple (plan, équipe) — l'unicité retirée exprès —
     * donneraient deux lignes, ou une au hasard. La conversion s'annule : la table d'exception et les colonnes de
     * l'équipe restent, aucune ligne n'est écrite, la migration n'est pas enregistrée.
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    public function testTheTeamConversionIsCancelledWhenTheCountWouldDiffer(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $admin->executeStatement('DROP INDEX uniq_team_period_override');
        $admin->executeStatement(
            'INSERT INTO team_period_override SELECT * FROM json_populate_record(NULL::team_period_override, (SELECT (row_to_json(o)::jsonb || jsonb_build_object(\'id\', ?::text, \'sessions_per_week\', 3))::json FROM team_period_override o WHERE o.team_id = ?))',
            ['d7e00000-0000-4000-8000-0000000000dd', self::DECOR_TEAMS['U15 M']],
        );
        self::assertSame(2, (int) $admin->fetchOne('SELECT COUNT(*) FROM team_period_override WHERE team_id = ?', [self::DECOR_TEAMS['U15 M']]), 'témoin : deux exceptions pour le même couple');

        [$code, $output] = $this->runMigrations();

        self::assertNotSame(0, $code, 'la conversion refuse un décor qui donnerait un autre compte que équipes × plans : ' . $output);
        $recorded = array_map(strval(...), $admin->fetchFirstColumn('SELECT version FROM doctrine_migration_versions'));
        self::assertNotContains('DoctrineMigrations\Version20261128120000', $recorded, 'la migration n\'est pas enregistrée');
        self::assertNotNull($admin->fetchOne('SELECT to_regclass(\'public.team_period_override\')'), 'la table d\'exception est toujours là');
        self::assertTrue($this->columnExists($admin, 'team', 'sessions_per_week'), 'les séances sont toujours sur l\'équipe');
        self::assertTrue($this->columnExists($admin, 'schedule_plan', 'team_selection_initialized'), 'le drapeau est toujours là');
        self::assertNull($admin->fetchOne('SELECT to_regclass(\'public.plan_team\')'), 'aucune ligne n\'est écrite');
    }

    /**
     * Lot 4, PR 3b (ligne 24 du plan) : la conversion donne une ligne `plan_venue` à chaque couple (plan de période,
     * gymnase) de la saison, aucune au plan de saison. Sans réglage, la ligne vaut le défaut (participe, fermeture non
     * levée, sans masque) ; « hors service » devient « ne participe pas », masque dormant conservé ; « grille
     * ressaisie » devient « effet de fermeture levé » ; le masque passe tel quel. La table d'exception disparaît ; la
     * table neuve porte son unicité, sa clé composée en cascade vers le plan, aucune clé vers le gymnase, et sa RLS.
     *
     * Décor 12 (vacances) : Gymnase Sud hors service, Gymnase Nord fermé le mercredi par le masque. Décor 13
     * (fermeture) : Gymnase Nord à grille ressaisie ; le test y ajoute le Gymnase Sud hors service avec un masque
     * (samedi ouvert), dormant.
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    #[TestWith(['periode-13-fermeture-reglages'])]
    public function testTheConversionGivesEveryVenueALineInEveryPeriodPlan(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $seasonPlan = (string) $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type = ?', [self::DECOR_SEASON, 'SEASON']);
        $periodPlan = (string) $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type <> ?', [self::DECOR_SEASON, 'SEASON']);
        self::assertNotSame('', $periodPlan, 'témoin : le décor a un plan de période');
        $nord = self::DECOR_VENUES['Gymnase Nord'];
        $sud = self::DECOR_VENUES['Gymnase Sud'];
        if ('periode-13-fermeture-reglages' === $decor) {
            $admin->executeStatement(
                'INSERT INTO venue_period_override (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, venue_id, mode, day_overrides)'
                . ' VALUES (?, 1, now(), now(), ?, ?, ?, ?, \'DISABLED\', \'{"6":"OPEN"}\')',
                ['dbe00000-0000-4000-8000-0000000000d1', self::DECOR_CLUB, self::DECOR_SEASON, $periodPlan, $sud],
            );
        }
        self::assertSame(array_values(self::DECOR_VENUES), array_map(strval(...), $admin->fetchFirstColumn('SELECT id FROM venue WHERE season_id = ? ORDER BY id', [self::DECOR_SEASON])), 'témoin : les deux gymnases du décor');
        self::assertSame(2, (int) $admin->fetchOne('SELECT COUNT(*) FROM venue_period_override WHERE schedule_plan_id = ?', [$periodPlan]), 'témoin : deux réglages de gymnase à convertir');

        self::assertSame(self::LOT_MIGRATIONS, $this->migrate(), 'la chaîne joue exactement les migrations du lot');

        $expected = match ($decor) {
            'periode-12-vacances-reglages' => [$nord => [true, false, [3 => 'CLOSED']], $sud => [false, false, null]],
            default => [$nord => [true, true, null], $sud => [false, false, [6 => 'OPEN']]],
        };
        self::assertSame($expected, $this->venueLinesOf($admin, $periodPlan), 'chaque réglage de gymnase devient sa ligne, champ par champ');
        self::assertSame([], $this->venueLinesOf($admin, $seasonPlan), 'aucune ligne de gymnase au plan de saison');
        self::assertSame(2, (int) $admin->fetchOne('SELECT COUNT(*) FROM plan_venue WHERE season_id = ?', [self::DECOR_SEASON]), 'une ligne par (plan de période, gymnase), ni plus ni moins');
        self::assertSame(0, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM plan_venue l JOIN schedule_plan p ON p.id = l.schedule_plan_id WHERE l.club_id <> p.club_id OR l.season_id <> p.season_id',
        ), 'chaque ligne porte le club et la saison de son plan');

        self::assertNull($admin->fetchOne('SELECT to_regclass(\'public.venue_period_override\')'), 'la table d\'exception est supprimée');
        foreach (['participates', 'closure_lifted'] as $column) {
            self::assertSame('NO', $admin->fetchOne('SELECT is_nullable FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'plan_venue\' AND column_name = ?', [$column]), \sprintf('plan_venue.%s est obligatoire', $column));
        }
        self::assertSame('false', $admin->fetchOne('SELECT column_default FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'plan_venue\' AND column_name = \'closure_lifted\''), 'l\'effet de fermeture n\'est pas levé par défaut');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_index i WHERE i.indrelid = \'public.plan_venue\'::regclass AND i.indisunique AND i.indpred IS NULL'
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey::int2[])) = ARRAY[\'schedule_plan_id\', \'venue_id\']',
        ), 'une ligne par couple (plan, gymnase)');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_constraint c WHERE c.contype = \'f\' AND c.conrelid = \'public.plan_venue\'::regclass'
            . ' AND c.confrelid = \'public.schedule_plan\'::regclass AND c.confdeltype = \'c\''
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)) = ARRAY[\'club_id\', \'schedule_plan_id\']',
        ), 'une clé composée (plan, club) vers le plan, en cascade : une ligne ne désigne jamais le plan d\'un autre club');
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM pg_constraint c WHERE c.contype = \'f\' AND c.conrelid = \'public.plan_venue\'::regclass AND c.confrelid = \'public.venue\'::regclass'), 'pas de clé vers le gymnase : « Charger » efface puis réinsère les gymnases (E6)');
        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM pg_class WHERE oid = \'public.plan_venue\'::regclass AND relrowsecurity AND relforcerowsecurity'), 'la RLS est active et forcée');
    }

    /**
     * Lot 4, PR 3b, décor faussé : deux réglages pour le même couple (plan, gymnase) — l'unicité retirée exprès —
     * donneraient deux lignes, ou une au hasard. La conversion s'annule : la table d'exception reste, aucune ligne
     * n'est écrite, la migration n'est pas enregistrée.
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    public function testTheVenueConversionIsCancelledWhenTheCountWouldDiffer(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $admin->executeStatement('DROP INDEX uniq_venue_period_override');
        $admin->executeStatement(
            'INSERT INTO venue_period_override SELECT * FROM json_populate_record(NULL::venue_period_override, (SELECT (row_to_json(o)::jsonb || jsonb_build_object(\'id\', ?::text, \'mode\', \'BLANK\', \'day_overrides\', NULL))::json FROM venue_period_override o WHERE o.venue_id = ?))',
            ['dbe00000-0000-4000-8000-0000000000dd', self::DECOR_VENUES['Gymnase Nord']],
        );
        self::assertSame(2, (int) $admin->fetchOne('SELECT COUNT(*) FROM venue_period_override WHERE venue_id = ?', [self::DECOR_VENUES['Gymnase Nord']]), 'témoin : deux réglages pour le même couple');

        [$code, $output] = $this->runMigrations();

        self::assertNotSame(0, $code, 'la conversion refuse un décor qui donnerait un autre compte que gymnases × plans de période : ' . $output);
        $recorded = array_map(strval(...), $admin->fetchFirstColumn('SELECT version FROM doctrine_migration_versions'));
        self::assertNotContains('DoctrineMigrations\Version20261129120000', $recorded, 'la migration n\'est pas enregistrée');
        self::assertNotNull($admin->fetchOne('SELECT to_regclass(\'public.venue_period_override\')'), 'la table d\'exception est toujours là');
        self::assertNull($admin->fetchOne('SELECT to_regclass(\'public.plan_venue\')'), 'aucune ligne n\'est écrite');
    }

    /**
     * Lot 4, PR 4 (ligne 29 du plan) : la conversion donne une ligne `plan_constraint` à chaque couple (plan de période,
     * contrainte permanente) de la saison, aucune au plan de saison ni pour une contrainte datée. Une coche de période
     * devient la valeur de sa ligne ; sans coche, la ligne vaut le défaut de la nature (fermeture : tout s'applique ;
     * vacances : seules les règles de club). Une coche dont la contrainte n'existe plus ne donne aucune ligne. La table
     * d'exception disparaît ; la table neuve porte son unicité, sa clé composée en cascade vers le plan, aucune clé vers
     * la contrainte, et sa RLS.
     *
     * Décor 12 (vacances) : la règle d'équipe 2 cochée, la règle de club 31 décochée ; la règle datée 32 sans ligne.
     * Décor 13 (fermeture) : la règle d'équipe 1 décochée ; la règle d'équipe 42 créée après la naissance de la
     * fermeture s'applique ; la fermeture datée 41 sans ligne. Le test ajoute une coche orpheline.
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    #[TestWith(['periode-13-fermeture-reglages'])]
    public function testTheConversionGivesEveryPermanentRuleALineInEveryPeriodPlan(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $seasonPlan = (string) $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type = ?', [self::DECOR_SEASON, 'SEASON']);
        $periodPlan = (string) $admin->fetchOne('SELECT id FROM schedule_plan WHERE season_id = ? AND type <> ?', [self::DECOR_SEASON, 'SEASON']);
        self::assertNotSame('', $periodPlan, 'témoin : le décor a un plan de période');
        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM schedule_plan WHERE season_id = ? AND type <> ?', [self::DECOR_SEASON, 'SEASON']), 'témoin : un seul plan de période');
        $permanent = array_map(strval(...), $admin->fetchFirstColumn('SELECT id FROM "constraint" WHERE season_id = ? AND calendar_entry_id IS NULL ORDER BY id', [self::DECOR_SEASON]));
        $dated = array_map(strval(...), $admin->fetchFirstColumn('SELECT id FROM "constraint" WHERE season_id = ? AND calendar_entry_id IS NOT NULL ORDER BY id', [self::DECOR_SEASON]));
        self::assertNotSame([], $dated, 'témoin : le décor a une contrainte datée, qui ne doit recevoir aucune ligne');
        $orphan = 'dc000000-0000-4000-8000-0000000000ff';
        $admin->executeStatement(
            'INSERT INTO constraint_period_override SELECT * FROM json_populate_record(NULL::constraint_period_override, (SELECT (row_to_json(o)::jsonb || jsonb_build_object(\'id\', ?::text, \'constraint_id\', ?::text))::json FROM constraint_period_override o WHERE o.schedule_plan_id = ? LIMIT 1))',
            [$orphan, 'c0570000-0000-4000-8000-0000000000ff', $periodPlan],
        );
        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM constraint_period_override WHERE id = ?', [$orphan]), 'témoin : une coche orpheline, dont la contrainte n\'existe pas');

        self::assertSame(self::LOT_MIGRATIONS, $this->migrate(), 'la chaîne joue exactement les migrations du lot');

        $rules = self::DECOR_RULES;
        $expected = match ($decor) {
            'periode-12-vacances-reglages' => [
                $rules['équipe 1'] => false, $rules['équipe 2'] => true, $rules['équipe 3'] => false, $rules['coach 4'] => false, $rules['club 31'] => false,
            ],
            default => [
                $rules['équipe 1'] => false, $rules['équipe 2'] => true, $rules['équipe 3'] => true, $rules['coach 4'] => true, $rules['équipe 42'] => true,
            ],
        };
        ksort($expected);
        self::assertSame(array_keys($expected), $permanent, 'témoin : les contraintes permanentes du décor');
        self::assertSame($expected, $this->ruleLinesOf($admin, $periodPlan), 'chaque coche devient sa ligne, le reste vaut le défaut de la nature');
        self::assertSame([], $this->ruleLinesOf($admin, $seasonPlan), 'aucune ligne de règle au plan de saison');
        self::assertSame(\count($permanent), (int) $admin->fetchOne('SELECT COUNT(*) FROM plan_constraint WHERE season_id = ?', [self::DECOR_SEASON]), 'une ligne par (plan de période, contrainte permanente), ni plus ni moins : ni datée, ni orpheline');
        self::assertSame(0, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM plan_constraint l JOIN schedule_plan p ON p.id = l.schedule_plan_id WHERE l.club_id <> p.club_id OR l.season_id <> p.season_id',
        ), 'chaque ligne porte le club et la saison de son plan');

        self::assertNull($admin->fetchOne('SELECT to_regclass(\'public.constraint_period_override\')'), 'la table d\'exception est supprimée');
        self::assertSame('NO', $admin->fetchOne('SELECT is_nullable FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'plan_constraint\' AND column_name = \'applies\''), 'plan_constraint.applies est obligatoire');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_index i WHERE i.indrelid = \'public.plan_constraint\'::regclass AND i.indisunique AND i.indpred IS NULL'
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey::int2[])) = ARRAY[\'constraint_id\', \'schedule_plan_id\']',
        ), 'une ligne par couple (plan, contrainte)');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_constraint c WHERE c.contype = \'f\' AND c.conrelid = \'public.plan_constraint\'::regclass'
            . ' AND c.confrelid = \'public.schedule_plan\'::regclass AND c.confdeltype = \'c\''
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)) = ARRAY[\'club_id\', \'schedule_plan_id\']',
        ), 'une clé composée (plan, club) vers le plan, en cascade : une ligne ne désigne jamais le plan d\'un autre club');
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM pg_constraint c WHERE c.contype = \'f\' AND c.conrelid = \'public.plan_constraint\'::regclass AND c.confrelid = \'public."constraint"\'::regclass'), 'pas de clé vers la contrainte : « Charger » efface puis réinsère les contraintes permanentes (E6)');
        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM pg_class WHERE oid = \'public.plan_constraint\'::regclass AND relrowsecurity AND relforcerowsecurity'), 'la RLS est active et forcée');
    }

    /**
     * Lot 4, PR 4, décor faussé : deux coches pour le même couple (plan, contrainte) — l'unicité retirée exprès —
     * donneraient deux lignes, ou une au hasard. La conversion s'annule : la table d'exception reste, aucune ligne n'est
     * écrite, la migration n'est pas enregistrée.
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    public function testTheRuleConversionIsCancelledWhenTheCountWouldDiffer(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $admin->executeStatement('DROP INDEX uniq_constraint_period_override');
        $admin->executeStatement(
            'INSERT INTO constraint_period_override SELECT * FROM json_populate_record(NULL::constraint_period_override, (SELECT (row_to_json(o)::jsonb || jsonb_build_object(\'id\', ?::text, \'is_active\', NOT o.is_active))::json FROM constraint_period_override o WHERE o.constraint_id = ?))',
            ['dc000000-0000-4000-8000-0000000000dd', self::DECOR_RULES['club 31']],
        );
        self::assertSame(2, (int) $admin->fetchOne('SELECT COUNT(*) FROM constraint_period_override WHERE constraint_id = ?', [self::DECOR_RULES['club 31']]), 'témoin : deux coches pour le même couple');

        [$code, $output] = $this->runMigrations();

        self::assertNotSame(0, $code, 'la conversion refuse un décor qui donnerait un autre compte que contraintes permanentes × plans de période : ' . $output);
        $recorded = array_map(strval(...), $admin->fetchFirstColumn('SELECT version FROM doctrine_migration_versions'));
        self::assertNotContains('DoctrineMigrations\Version20261130120000', $recorded, 'la migration n\'est pas enregistrée');
        self::assertNotNull($admin->fetchOne('SELECT to_regclass(\'public.constraint_period_override\')'), 'la table d\'exception est toujours là');
        self::assertNull($admin->fetchOne('SELECT to_regclass(\'public.plan_constraint\')'), 'aucune ligne n\'est écrite');
    }

    /**
     * Lot 5, PR 2 (ligne 9 du plan, §6) : après toutes les migrations, la table des copies existe sur son contrat (une
     * copie par version, clé en cascade vers la version, RLS forcée) et la migration n'a rien copié ; la commande de
     * conversion donne alors à chaque version validée ou chargée une copie — autant de copies que de versions validées
     * ou chargées — égale à l'état vivant de son plan, si bien qu'aucun plan n'est « en attente » ; `--check` rend 0.
     *
     * Décor 12 seul : c'est le décor figé qui porte une version validée (le socle) avec des plans de période ; le décor
     * 11 n'en porte aucune (pointeurs nuls) et `decor-avant.sql` ne se recapture jamais. La version chargée sans être
     * validée est prouvée par `Integration/Command/CaptureVersionStatesCommandTest`.
     */
    #[TestWith(['periode-12-vacances-reglages'])]
    public function testTheConversionGivesEveryKeptVersionItsCopy(string $decor): void
    {
        $admin = self::ownerConnection(self::caseName());
        $keptSql = 'SELECT DISTINCT v FROM (SELECT chosen_schedule_id AS v FROM schedule_plan UNION SELECT lock_source_schedule_id FROM schedule_plan) k WHERE v IS NOT NULL ORDER BY v';

        self::assertSame(self::LOT_MIGRATIONS, $this->migrate(), 'la chaîne joue exactement les migrations du lot');

        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM pg_class WHERE oid = \'public.schedule_state\'::regclass AND relrowsecurity AND relforcerowsecurity'), 'la RLS est active et forcée');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)'
            . ' WHERE c.contype = \'f\' AND c.conrelid = \'public.schedule_state\'::regclass AND c.confrelid = \'public.schedule\'::regclass'
            . ' AND a.attname = \'schedule_id\' AND c.confdeltype = \'c\'',
        ), 'une clé vers la version, en cascade : la copie part avec sa version');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_index i WHERE i.indrelid = \'public.schedule_state\'::regclass AND i.indisunique AND i.indpred IS NULL'
            . ' AND (SELECT array_agg(a.attname::text) FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey::int2[])) = ARRAY[\'schedule_id\']',
        ), 'une copie par version');
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM schedule_state'), 'la migration ne copie rien : la capture est l\'étape suivante de la procédure');
        $kept = array_map(strval(...), $admin->fetchFirstColumn($keptSql));
        self::assertNotSame([], $kept, 'témoin : le décor a une version validée ou chargée');

        self::assertSame(0, $this->console(['command' => 'app:plans:capture-version-states']), 'la capture réussit');

        self::assertSame($kept, array_map(strval(...), $admin->fetchFirstColumn('SELECT schedule_id FROM schedule_state ORDER BY schedule_id')), 'autant de copies que de versions validées ou chargées, une chacune');
        $this->scopeGucToClub(self::ER_CLUB);
        $states = self::getContainer()->get(PlanStates::class);
        foreach ($kept as $versionId) {
            $planId = (string) $admin->fetchOne('SELECT schedule_plan_id FROM schedule WHERE id = ?', [$versionId]);
            self::assertTrue($states->of($versionId)->changesTo($states->capture($planId))->isEmpty(), 'la copie est l\'état vivant : aucun plan n\'est « en attente » après la conversion');
        }
        self::assertSame(0, $this->console(['command' => 'app:plans:capture-version-states', '--check' => true]), '--check rend 0 après la capture');
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

    /** @param array<string, mixed> $input */
    private function console(array $input): int
    {
        $console = new Application(self::$kernel ?? throw new LogicException('noyau non démarré'));
        $console->setAutoExit(false);

        return $console->run(new ArrayInput([...$input, '--no-interaction' => true]), new BufferedOutput);
    }

    /**
     * Deux plans de vacances jamais ouverts (drapeau à faux), copiés de celui du décor sans son entrée de calendrier :
     * le premier reçoit une version, le second aucune.
     *
     * @return array{0: string, 1: string}
     */
    private function addTwoNeverOpenedHolidayPlans(Connection $admin, string $model): array
    {
        $withVersion = 'e1a50000-0000-4000-8000-0000000000a1';
        $withoutVersion = 'e1a50000-0000-4000-8000-0000000000a2';
        foreach ([$withVersion => 'Vacances jamais ouvertes, avec version', $withoutVersion => 'Vacances jamais ouvertes, sans version'] as $id => $name) {
            $admin->executeStatement(
                'INSERT INTO schedule_plan SELECT * FROM json_populate_record(NULL::schedule_plan, (SELECT (row_to_json(p)::jsonb || jsonb_build_object(\'id\', ?::text, \'name\', ?::text, \'calendar_entry_id\', NULL, \'chosen_schedule_id\', NULL, \'team_selection_initialized\', false, \'last_version_number\', 0))::json FROM schedule_plan p WHERE p.id = ?))',
                [$id, $name, $model],
            );
        }
        $admin->executeStatement(
            'INSERT INTO schedule SELECT * FROM json_populate_record(NULL::schedule, (SELECT (row_to_json(s)::jsonb || jsonb_build_object(\'id\', ?::text, \'schedule_plan_id\', ?::text, \'version_number\', 1))::json FROM schedule s WHERE s.schedule_plan_id = ? LIMIT 1))',
            ['5c4e0000-0000-4000-8000-0000000000a1', $withVersion, $model],
        );
        $admin->executeStatement('UPDATE schedule_plan SET last_version_number = 1 WHERE id = ?', [$withVersion]);
        self::assertSame(1, (int) $admin->fetchOne('SELECT COUNT(*) FROM schedule WHERE schedule_plan_id = ?', [$withVersion]), 'témoin : le premier a une version');
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM schedule WHERE schedule_plan_id = ?', [$withoutVersion]), 'témoin : le second n\'en a aucune');

        return [$withVersion, $withoutVersion];
    }

    /** @return array<string, array{0: bool, 1: int, 2: int, 3: ?string}> participe, séances, plafond, origine, par équipe */
    private function linesOf(Connection $admin, string $planId): array
    {
        $lines = [];
        foreach ($admin->fetchAllAssociative('SELECT team_id, participates, sessions_per_week, max_sessions_per_day, source FROM plan_team WHERE schedule_plan_id = ? ORDER BY team_id', [$planId]) as $row) {
            $lines[(string) $row['team_id']] = [(bool) $row['participates'], (int) $row['sessions_per_week'], (int) $row['max_sessions_per_day'], null === $row['source'] ? null : (string) $row['source']];
        }

        return $lines;
    }

    /** @return array<string, array{0: bool, 1: bool, 2: array<int, string>|null}> participe, fermeture levée, masque trié, par gymnase */
    private function venueLinesOf(Connection $admin, string $planId): array
    {
        $lines = [];
        foreach ($admin->fetchAllAssociative('SELECT venue_id, participates, closure_lifted, day_overrides FROM plan_venue WHERE schedule_plan_id = ? ORDER BY venue_id', [$planId]) as $row) {
            $mask = null === $row['day_overrides'] ? null : json_decode((string) $row['day_overrides'], true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($mask)) {
                ksort($mask);
            }
            $lines[(string) $row['venue_id']] = [(bool) $row['participates'], (bool) $row['closure_lifted'], \is_array($mask) && [] !== $mask ? $mask : null];
        }

        return $lines;
    }

    /** @return array<string, bool> « s'applique », par contrainte, trié par identifiant */
    private function ruleLinesOf(Connection $admin, string $planId): array
    {
        $lines = [];
        foreach ($admin->fetchAllAssociative('SELECT constraint_id, applies FROM plan_constraint WHERE schedule_plan_id = ? ORDER BY constraint_id', [$planId]) as $row) {
            $lines[(string) $row['constraint_id']] = (bool) $row['applies'];
        }

        return $lines;
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
