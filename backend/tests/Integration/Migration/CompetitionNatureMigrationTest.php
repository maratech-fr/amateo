<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\FrozenSchemaDatabaseTrait;
use App\Tests\Unit\Matches\CompetitionClassifierTest;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : la conversion de la nature (lot 7, PR 4a, plan §9, Q2) pose la nature que le classifieur poserait, et
 * ne laisse aucun match sans compétition — chaque saison reçoit UNE compétition « Amicaux » marquée d'office, les
 * matchs sans compétition y sont versés avec l'engagement de leur équipe, et `fixture.competition_id` devient
 * obligatoire.
 *
 * Base dédiée née du schéma figé d'avant la PR 3b (`tests/Fixtures/lot-7/schema-avant.sql`), décor de la référence 17
 * (« Amical PNF » rangée en championnat, deux amicaux sans compétition de U15 M, `tests/Fixtures/lot-7/decor-avant.sql`),
 * retouché en SQL par chaque cas dans le schéma d'avant, puis `doctrine:migrations:migrate` (conversion de la PR 3b,
 * puis celle-ci). Le jeu de libellés est celui de {@see CompetitionClassifierTest::NATURE_CASES}.
 *
 * Jumeau : `CompetitionEngagementMigrationTest` (même mécanique, conversion de la PR 3b). NR de migration de données :
 * obligatoire jusqu'à l'application en production (charte des tests, §2 PHPUnit).
 */
#[Group('phase1')]
#[Group('integration')]
#[SkipDatabaseRollback]
final class CompetitionNatureMigrationTest extends KernelTestCase
{
    use FrozenSchemaDatabaseTrait;

    private const string FROZEN_SCHEMA_FILE = __DIR__ . '/../../Fixtures/lot-7/schema-avant.sql';

    private const string FROZEN_DECOR_FILE = __DIR__ . '/../../Fixtures/lot-7/decor-avant.sql';

    private const string FROZEN_DATABASE_SUFFIX = 'lot7_nature';

    private const string DECOR = 'placement-17-amicaux';

    private const string CLUB = 'c10b0000-0000-4000-8000-000000000001';

    private const string SEASON = '5ea50000-0000-4000-8000-000000000001';

    private const string U13 = '7ea00000-0000-4000-8000-000000000001';

    private const string U15 = '7ea00000-0000-4000-8000-000000000002';

    private const string SM1 = '7ea00000-0000-4000-8000-000000000003';

    /** « Championnat 1 » de U13 F : la ligne copiée pour forger une compétition. */
    private const string CHAMPIONNAT_1 = 'c0e90000-0000-4000-8000-000000000001';

    /** « Amical PNF » de SM1, rangée en championnat, deux domiciles et un extérieur. */
    private const string AMICAL_PNF = 'c0e90000-0000-4000-8000-000000000171';

    private const string FORGED = 'c0e90000-0000-4000-8000-0000000000b1';

    /** Les deux amicaux de U15 M, sans compétition. */
    private const array FIXTURES_WITHOUT_COMPETITION = ['f1c70000-0000-4000-8000-000000000171', 'f1c70000-0000-4000-8000-000000000172'];

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function natureCases(): iterable
    {
        return CompetitionClassifierTest::natureCases();
    }

    /** Une compétition stockée championnat prend la nature que son nom dit, comme le classifieur la dirait. */
    #[DataProvider('natureCases')]
    public function testAStoredChampionshipGetsTheNatureItsNameSays(string $name, string $expected): void
    {
        $admin = self::frozenCaseConnection();
        $this->forgeCompetition($admin, $name, 'CHAMPIONSHIP');

        $this->migrateOrFail();

        self::assertSame($expected, $admin->fetchOne('SELECT competition_type FROM competition WHERE id = ?', [self::FORGED]), \sprintf('« %s » stockée championnat', $name));
        self::assertSame('FRIENDLY', $admin->fetchOne('SELECT competition_type FROM competition WHERE id = ?', [self::AMICAL_PNF]), 'la « Amical PNF » du décor devient amicale');
        self::assertSame($name, $admin->fetchOne('SELECT name FROM competition WHERE id = ?', [self::FORGED]), 'le nom ne change pas');
    }

    /**
     * Une nature déjà posée n'est pas défaite quand le nom ne dit rien ; un « Amical … » rangé en coupe (défaut du
     * canal API) devient amical, comme les autres.
     */
    #[TestWith(['Poule haute', 'CUP', 'CUP'])]
    #[TestWith(['Poule haute', 'BRASSAGE', 'BRASSAGE'])]
    #[TestWith(['AMICAL RM3', 'CUP', 'FRIENDLY'])]
    public function testANatureAlreadySetIsKeptUnlessTheNameSaysAmical(string $name, string $stored, string $expected): void
    {
        $admin = self::frozenCaseConnection();
        $this->forgeCompetition($admin, $name, $stored);

        $this->migrateOrFail();

        self::assertSame($expected, $admin->fetchOne('SELECT competition_type FROM competition WHERE id = ?', [self::FORGED]));
    }

    /**
     * Aucun match ne reste sans compétition : les deux amicaux de U15 M sont versés dans « Amicaux » de leur saison,
     * U15 M y est engagée une fois ; les matchs d'une compétition restent où ils sont, « Amical PNF » comprise ; la
     * colonne devient obligatoire.
     */
    public function testEveryFixtureWithoutCompetitionLandsInItsSeasonsFriendlyCompetition(): void
    {
        $admin = self::frozenCaseConnection();
        self::assertSame(2, (int) $admin->fetchOne('SELECT COUNT(*) FROM fixture WHERE competition_id IS NULL'), 'témoin : deux amicaux sans compétition');
        $placed = $admin->fetchAllKeyValue('SELECT id, competition_id FROM fixture WHERE competition_id IS NOT NULL ORDER BY id');
        self::assertNotSame([], $placed, 'témoin : des matchs rangés dans une compétition');

        $this->migrateOrFail();

        $default = $this->defaultFriendlyOf($admin, self::SEASON);
        self::assertSame(['name' => 'Amicaux', 'competition_type' => 'FRIENDLY', 'club_id' => self::CLUB], $admin->fetchAssociative('SELECT name, competition_type, club_id FROM competition WHERE id = ?', [$default]), 'la compétition d\'office : « Amicaux », amicale, du club');
        foreach (self::FIXTURES_WITHOUT_COMPETITION as $fixture) {
            self::assertSame($default, $admin->fetchOne('SELECT competition_id FROM fixture WHERE id = ?', [$fixture]), \sprintf('le match %s est versé dans « Amicaux »', $fixture));
        }
        self::assertSame([self::U15], $admin->fetchFirstColumn('SELECT team_id FROM engagement WHERE competition_id = ? ORDER BY team_id', [$default]), 'U15 M est engagée une fois dans « Amicaux », personne d\'autre');
        self::assertSame($placed, $admin->fetchAllKeyValue('SELECT id, competition_id FROM fixture WHERE id IN (\'' . implode('\', \'', array_keys($placed)) . '\') ORDER BY id'), 'un match déjà rangé ne bouge pas, « Amical PNF » comprise');
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM fixture WHERE competition_id IS NULL'), 'plus aucun match sans compétition');
        self::assertSame('NO', $admin->fetchOne('SELECT is_nullable FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'fixture\' AND column_name = \'competition_id\''), 'fixture.competition_id est obligatoire');
    }

    /**
     * Chaque saison a sa compétition « Amicaux », une seule, même sans aucun match ; la base en refuse une seconde.
     */
    public function testEverySeasonGetsExactlyOneFriendlyCompetition(): void
    {
        $admin = self::frozenCaseConnection();
        $empty = '5ea50000-0000-4000-8000-0000000000e1';
        $admin->executeStatement(
            'INSERT INTO season SELECT * FROM json_populate_record(NULL::season, (SELECT (row_to_json(s)::jsonb || jsonb_build_object(\'id\', ?::text, \'name\', \'2026-2027\', \'start_date\', \'2026-08-01\', \'end_date\', \'2027-07-15\', \'status\', \'draft\'))::json FROM season s WHERE s.id = ?))',
            [$empty, self::SEASON],
        );
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM fixture WHERE season_id = ?', [$empty]), 'témoin : une saison sans match');

        $this->migrateOrFail();

        self::assertSame(
            [self::SEASON => 1, $empty => 1],
            array_map(intval(...), $admin->fetchAllKeyValue('SELECT s.id, (SELECT COUNT(*) FROM competition c WHERE c.season_id = s.id AND c.default_friendly) FROM season s ORDER BY s.id')),
            'une compétition d\'office par saison, saison sans match comprise',
        );
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM engagement WHERE competition_id = ?', [$this->defaultFriendlyOf($admin, $empty)]), 'sans match, « Amicaux » n\'engage personne');
        self::assertSame(1, (int) $admin->fetchOne(
            'SELECT COUNT(*) FROM pg_index i WHERE i.indrelid = \'public.competition\'::regclass AND i.indisunique AND i.indpred IS NOT NULL'
            . ' AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey::int2[])) = ARRAY[\'club_id\', \'season_id\']',
        ), 'un unique partiel (club, saison) où la compétition est d\'office');
    }

    /**
     * Une compétition que le club a déjà nommée « Amicaux » n'est pas prise pour la compétition d'office sur son nom :
     * le premier mot n'est pas « amical », elle garde sa nature et ses matchs ; la compétition d'office est une autre
     * ligne.
     */
    public function testACompetitionAlreadyNamedAmicauxIsNotTakenForTheDefaultOne(): void
    {
        $admin = self::frozenCaseConnection();
        $this->forgeCompetition($admin, 'Amicaux', 'CHAMPIONSHIP', self::SM1);
        $admin->executeStatement('UPDATE fixture SET competition_id = ? WHERE id = ?', [self::FORGED, 'f1c70000-0000-4000-8000-000000000173']);

        $this->migrateOrFail();

        self::assertSame(['competition_type' => 'CHAMPIONSHIP', 'default_friendly' => false], $admin->fetchAssociative('SELECT competition_type, default_friendly FROM competition WHERE id = ?', [self::FORGED]), 'la compétition du club garde sa nature et n\'est pas d\'office');
        self::assertNotSame(self::FORGED, $this->defaultFriendlyOf($admin, self::SEASON), 'la compétition d\'office est une autre ligne');
        self::assertSame(self::FORGED, $admin->fetchOne('SELECT competition_id FROM fixture WHERE id = ?', ['f1c70000-0000-4000-8000-000000000173']), 'son match ne bouge pas');
    }

    protected function setUp(): void
    {
        $this->setUpFrozenCase(self::DECOR);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrozenCase();
        parent::tearDown();
    }

    private function migrateOrFail(): void
    {
        [$code, $output, $played] = $this->runFrozenMigrations();
        self::assertSame(0, $code, 'les conversions des PR 3b et 4a se jouent sur le décor : ' . $output);
        self::assertNotSame([], $played, 'des migrations sont jouées');
        self::assertTrue(self::frozenColumnExists(self::frozenCaseConnection(), 'competition', 'default_friendly'), 'la conversion de la PR 4a est jouée (competition.default_friendly)');
    }

    /** Une compétition de l'équipe (U13 F par défaut), copie de « Championnat 1 » sous un autre nom et une nature stockée, dans le schéma d'avant. */
    private function forgeCompetition(Connection $admin, string $name, string $type, string $teamId = self::U13): void
    {
        $admin->executeStatement(
            'INSERT INTO competition SELECT * FROM json_populate_record(NULL::competition, (SELECT (row_to_json(c)::jsonb || jsonb_build_object(\'id\', ?::text, \'name\', ?::text, \'team_id\', ?::text, \'competition_type\', ?::text))::json FROM competition c WHERE c.id = ?))',
            [self::FORGED, $name, $teamId, $type, self::CHAMPIONNAT_1],
        );
    }

    private function defaultFriendlyOf(Connection $admin, string $seasonId): string
    {
        $ids = $admin->fetchFirstColumn('SELECT id FROM competition WHERE season_id = ? AND default_friendly', [$seasonId]);
        self::assertCount(1, $ids, 'une seule compétition d\'office pour la saison');

        return (string) $ids[0];
    }
}
