<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Entity\Fixture;
use App\Matches\FederalFollowUp;
use App\Tests\FrozenSchemaDatabaseTrait;
use App\Tests\TenantGucTrait;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : la conversion du suivi fédéral (lot 7, PR 6b, plan §9) ne change le stade servi d'aucun match d'une
 * compétition FBI — « à placer » reste « à placer », « placé » reste « placé », « saisi » et « validé » deviennent
 * « placé » si gymnase et heure, sinon « à placer », l'état fédéral gardant « saisi » ou « validé » dans
 * `fixture_federal_tracking` avec ses écarts, son mémo « FBI affiche encore … » et son libellé de salle gardé. Le
 * statut stocké n'a plus que deux valeurs (CHECK) et le match perd ses trois colonnes de suivi.
 *
 * Base dédiée née du schéma figé d'avant la PR 3b (`tests/Fixtures/lot-7/schema-avant.sql`), décor de la référence 18
 * (`tests/Fixtures/lot-7/decor-avant.sql`), retouché en SQL par chaque cas dans le schéma d'avant, puis
 * `doctrine:migrations:migrate` (conversions des PR 3b, 4a, puis celle-ci). Le stade servi est lu par la porte
 * ({@see FederalFollowUp::stageOf}), jamais par la colonne : c'est lui que l'API sert.
 *
 * ⚠ Écart au plan à trancher (§9) : le plan dit « source FBI si la compétition a un identifiant fédéral ou un match
 * venu de la fédération ». Ce test pose en plus qu'un match déjà saisi ou validé est une trace fédérale (hors la
 * compétition d'office « Amicaux ») : sans cela, le domicile validé posé par le système du « Championnat 1 » de la
 * référence 18 redeviendrait « à placer » et serait rendu au moteur (la référence 18 changerait, contre le §8). Voir
 * {@see self::testAMatchAlreadyEnteredIsAFederalTraceOfItsCompetition}.
 *
 * Jumeaux : `CompetitionNatureMigrationTest` et `CompetitionEngagementMigrationTest` (même mécanique) ; la demande au
 * moteur après la chaîne : `Lot7MigrationChainTest`. NR de migration de données : obligatoire jusqu'à l'application en
 * production (charte des tests, §2 PHPUnit).
 */
#[Group('phase1')]
#[Group('integration')]
#[SkipDatabaseRollback]
final class FederalTrackingMigrationTest extends KernelTestCase
{
    use FrozenSchemaDatabaseTrait;
    use TenantGucTrait;

    private const string FROZEN_SCHEMA_FILE = __DIR__ . '/../../Fixtures/lot-7/schema-avant.sql';

    private const string FROZEN_DECOR_FILE = __DIR__ . '/../../Fixtures/lot-7/decor-avant.sql';

    private const string FROZEN_DATABASE_SUFFIX = 'lot7_suivi';

    private const string DECOR = 'placement-18-suivi-federal';

    private const string CLUB = 'c10b0000-0000-4000-8000-000000000001';

    private const string SEASON = '5ea50000-0000-4000-8000-000000000001';

    private const string VENUE_NORD = 'be0e0000-0000-4000-8000-000000000001';

    /** « Championnat 1 » de U13 F : la ligne copiée pour forger une compétition. */
    private const string CHAMPIONNAT_1 = 'c0e90000-0000-4000-8000-000000000001';

    /** Le domicile placé de U13 F dans « Championnat 1 » : la ligne copiée pour forger un match. */
    private const string MODEL_FIXTURE = 'f1c70000-0000-4000-8000-000000000004';

    /** Une compétition forgée qui porte un identifiant fédéral : FBI par la règle du plan. */
    private const string FBI_COMPETITION = 'c0e90000-0000-4000-8000-0000000006b1';

    /** Une compétition forgée sans aucune trace fédérale. */
    private const string BARE_COMPETITION = 'c0e90000-0000-4000-8000-0000000006b2';

    private const string FORGED = 'f1c70000-0000-4000-8000-0000000006b1';

    /**
     * Statut d'origine, domicile ou extérieur, gymnase, heure, origine du placement → placement stocké et état fédéral
     * attendus. Le stade servi reste toujours le statut d'origine.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string|null, 3: string|null, 4: string|null, 5: string, 6: string}>
     */
    public static function stages(): iterable
    {
        yield 'domicile à placer' => ['UNPLACED', 'HOME', null, null, null, 'UNPLACED', 'TO_ENTER'];
        yield 'domicile placé' => ['PLACED', 'HOME', self::VENUE_NORD, '14:00', 'MANUAL', 'PLACED', 'TO_ENTER'];
        yield 'domicile saisi avec gymnase et heure' => ['SUBMITTED', 'HOME', self::VENUE_NORD, '14:00', 'MANUAL', 'PLACED', 'SUBMITTED'];
        yield 'domicile validé avec gymnase et heure' => ['VALIDATED', 'HOME', self::VENUE_NORD, '14:00', 'MANUAL', 'PLACED', 'VALIDATED'];
        yield 'domicile validé posé par le système' => ['VALIDATED', 'HOME', self::VENUE_NORD, '16:00', 'SOLVER', 'PLACED', 'VALIDATED'];
        yield 'domicile saisi sans gymnase' => ['SUBMITTED', 'HOME', null, '15:00', null, 'UNPLACED', 'SUBMITTED'];
        yield 'domicile validé sans gymnase' => ['VALIDATED', 'HOME', null, null, null, 'UNPLACED', 'VALIDATED'];
        yield 'extérieur à placer' => ['UNPLACED', 'AWAY', null, '15:00', null, 'UNPLACED', 'TO_ENTER'];
        yield 'extérieur placé sans gymnase' => ['PLACED', 'AWAY', null, '15:00', null, 'PLACED', 'TO_ENTER'];
        yield 'extérieur saisi' => ['SUBMITTED', 'AWAY', null, '15:00', null, 'UNPLACED', 'SUBMITTED'];
        yield 'extérieur validé sans gymnase' => ['VALIDATED', 'AWAY', null, null, null, 'UNPLACED', 'VALIDATED'];
    }

    /**
     * Un match d'une compétition FBI garde son stade servi ; son placement stocké et son état fédéral suivent la
     * règle du plan ; l'origine du placement ne bouge pas.
     */
    #[DataProvider('stages')]
    public function testAMatchOfAnFbiCompetitionKeepsTheStageItIsServed(string $status, string $homeAway, ?string $venue, ?string $kickoff, ?string $source, string $placement, string $state): void
    {
        $admin = self::frozenCaseConnection();
        $this->forgeCompetition($admin, self::FBI_COMPETITION, 'Championnat fédéral', 'FFBB-6B-1');
        $this->forgeFixture($admin, self::FORGED, self::FBI_COMPETITION, ['status' => $status, 'home_away' => $homeAway, 'venue_id' => $venue, 'kickoff_time' => $kickoff, 'placement_source' => $source]);

        $this->migrateOrFail($admin);

        self::assertSame('FBI', $admin->fetchOne('SELECT source FROM competition WHERE id = ?', [self::FBI_COMPETITION]), 'une compétition à identifiant fédéral est FBI');
        self::assertSame(['status' => $placement, 'placement_source' => $source], $admin->fetchAssociative('SELECT status, placement_source FROM fixture WHERE id = ?', [self::FORGED]), 'le placement stocké suit la règle, l\'origine du placement ne bouge pas');
        self::assertSame([$state], $admin->fetchFirstColumn('SELECT state FROM fixture_federal_tracking WHERE fixture_id = ?', [self::FORGED]), 'une ligne de suivi, à l\'état fédéral attendu');
        self::assertSame($status, $this->servedStageOf(self::FORGED), 'le stade servi ne change pas');
    }

    /**
     * Un extérieur validé sans gymnase d'une compétition amicale de la fédération (« Amical PNM », amicale depuis la
     * PR 4a, connue par le numéro FBI de son match) reste validé : la mesure du 2026-11-23 en compte cinq chez un club.
     */
    public function testAnAwayValidatedFriendlyOfTheFederationStaysValidated(): void
    {
        $admin = self::frozenCaseConnection();
        $this->forgeCompetition($admin, self::FBI_COMPETITION, 'Amical PNM', null);
        $this->forgeFixture($admin, self::FORGED, self::FBI_COMPETITION, ['status' => 'VALIDATED', 'home_away' => 'AWAY', 'venue_id' => null, 'kickoff_time' => null, 'placement_source' => null, 'external_ref' => 'APNM-0001']);

        $this->migrateOrFail($admin);

        self::assertSame(['competition_type' => 'FRIENDLY', 'source' => 'FBI'], $admin->fetchAssociative('SELECT competition_type, source FROM competition WHERE id = ?', [self::FBI_COMPETITION]), 'amicale de la fédération, FBI par le numéro de son match');
        self::assertSame(['VALIDATED'], $admin->fetchFirstColumn('SELECT state FROM fixture_federal_tracking WHERE fixture_id = ?', [self::FORGED]));
        self::assertSame('VALIDATED', $this->servedStageOf(self::FORGED), 'l\'extérieur reste servi validé');
    }

    /**
     * Les écarts ouverts, le mémo « FBI affiche encore … » et le libellé de salle gardé passent du match à sa ligne de
     * suivi, à l'identique ; le match perd ses trois colonnes.
     */
    public function testDeviationsMemoAndKeptVenueLabelMoveToTheTrackingRow(): void
    {
        $admin = self::frozenCaseConnection();
        $deviations = [['field' => 'date', 'appValue' => '2026-02-07', 'sourceValue' => '2026-02-08', 'channel' => 'FBI_XLSX', 'seenAt' => '2026-01-20T10:00:00+00:00', 'autoApplied' => false]];
        $echo = ['field' => 'kickoff', 'value' => '15:30', 'at' => '2026-01-21T09:00:00+00:00'];
        $this->forgeCompetition($admin, self::FBI_COMPETITION, 'Championnat fédéral', 'FFBB-6B-1');
        $this->forgeFixture($admin, self::FORGED, self::FBI_COMPETITION, [
            'status' => 'PLACED', 'home_away' => 'HOME', 'venue_id' => self::VENUE_NORD, 'kickoff_time' => '14:00', 'placement_source' => 'MANUAL',
            'review_state' => 'OUT_OF_SYNC', 'pending_deviations' => $deviations, 'fbi_echo' => $echo, 'kept_venue_label' => 'gymnase nord',
        ]);

        $this->migrateOrFail($admin);

        $row = $admin->fetchAssociative('SELECT club_id, season_id, state, pending_deviations, fbi_echo, kept_venue_label FROM fixture_federal_tracking WHERE fixture_id = ?', [self::FORGED]);
        self::assertIsArray($row, 'le match a sa ligne de suivi');
        self::assertSame([self::CLUB, self::SEASON, 'TO_ENTER'], [$row['club_id'], $row['season_id'], $row['state']], 'club, saison et état du match');
        // Le JSON forgé a déjà traversé jsonb (ordre des clés de jsonb) : on compare sans l'ordre des clés, valeurs et
        // types stricts — rien perdu, rien ajouté.
        self::assertSame(self::keyOrderFree($deviations), self::keyOrderFree(json_decode((string) $row['pending_deviations'], true)), 'les écarts ouverts, à l\'identique');
        self::assertSame(self::keyOrderFree($echo), self::keyOrderFree(json_decode((string) $row['fbi_echo'], true)), 'le mémo « FBI affiche encore … », à l\'identique');
        self::assertSame('gymnase nord', $row['kept_venue_label'], 'le libellé de salle gardé');
        self::assertSame('OUT_OF_SYNC', $admin->fetchOne('SELECT review_state FROM fixture WHERE id = ?', [self::FORGED]), 'le traitement du match ne bouge pas');
        foreach (['pending_deviations', 'fbi_echo', 'kept_venue_label'] as $column) {
            self::assertFalse(self::frozenColumnExists($admin, 'fixture', $column), \sprintf('fixture.%s est retirée', $column));
        }
    }

    /**
     * Un match d'une compétition sans aucune trace fédérale (ni identifiant fédéral, ni match importé ou venu du
     * canal API, ni match saisi ou validé) n'a pas de ligne de suivi ; « à placer » et « placé » ne bougent pas. La
     * compétition d'office « Amicaux » est hors FBI.
     */
    public function testAMatchOfACompetitionWithoutAnyFederalTraceIsNotTracked(): void
    {
        $admin = self::frozenCaseConnection();
        $this->forgeCompetition($admin, self::BARE_COMPETITION, 'Tournoi du club', null);
        $placed = 'f1c70000-0000-4000-8000-0000000006b2';
        $this->forgeFixture($admin, self::FORGED, self::BARE_COMPETITION, ['status' => 'UNPLACED', 'venue_id' => null, 'kickoff_time' => null, 'placement_source' => null]);
        $this->forgeFixture($admin, $placed, self::BARE_COMPETITION, ['status' => 'PLACED', 'match_date' => '2026-02-21', 'placement_source' => 'MANUAL']);

        $this->migrateOrFail($admin);

        self::assertSame('MANUAL', $admin->fetchOne('SELECT source FROM competition WHERE id = ?', [self::BARE_COMPETITION]), 'sans trace fédérale : MANUAL');
        self::assertSame('MANUAL', $admin->fetchOne('SELECT source FROM competition WHERE default_friendly'), '« Amicaux » est MANUAL');
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM fixture_federal_tracking WHERE fixture_id IN (?, ?)', [self::FORGED, $placed]), 'aucune ligne de suivi');
        self::assertSame(['UNPLACED', 'PLACED'], [$this->servedStageOf(self::FORGED), $this->servedStageOf($placed)], '« à placer » et « placé » restent servis tels quels');
    }

    /** @return iterable<string, array{0: string, 1: string}> la colonne qui dit qu'un match vient de la fédération */
    public static function federationMarks(): iterable
    {
        yield 'numéro FBI (import xlsx)' => ['external_ref', 'U13F-0042'];
        yield 'rencontre du canal API' => ['ffbb_rencontre_id', '200000002871234'];
    }

    /**
     * Une compétition sans identifiant fédéral dont un match placé, jamais saisi, vient de la fédération (numéro FBI ou
     * rencontre du canal API) est FBI : son match est suivi, « à saisir ».
     */
    #[DataProvider('federationMarks')]
    public function testACompetitionHoldingAMatchFromTheFederationIsFbi(string $column, string $value): void
    {
        $admin = self::frozenCaseConnection();
        $this->forgeCompetition($admin, self::BARE_COMPETITION, 'Championnat sans identifiant', null);
        $this->forgeFixture($admin, self::FORGED, self::BARE_COMPETITION, ['status' => 'PLACED', 'external_ref' => null, 'ffbb_rencontre_id' => null, $column => $value]);

        $this->migrateOrFail($admin);

        self::assertSame('FBI', $admin->fetchOne('SELECT source FROM competition WHERE id = ?', [self::BARE_COMPETITION]), 'un match venu de la fédération fait la compétition FBI');
        self::assertSame(['TO_ENTER'], $admin->fetchFirstColumn('SELECT state FROM fixture_federal_tracking WHERE fixture_id = ?', [self::FORGED]), 'son match est suivi, à saisir');
        self::assertSame('PLACED', $this->servedStageOf(self::FORGED));
    }

    /**
     * Un amical posé à la main et déjà marqué saisi (avant la PR 4a, sans compétition ; versé dans « Amicaux » par la
     * PR 4a) n'est pas une trace fédérale : « Amicaux » reste MANUAL, le match s'arrête à « placé », sans ligne de suivi.
     */
    public function testAnEnteredFriendlyPutByHandInAmicauxStopsAtPlaced(): void
    {
        $admin = self::frozenCaseConnection();
        $this->forgeFixture($admin, self::FORGED, null, ['status' => 'SUBMITTED', 'home_away' => 'HOME', 'venue_id' => self::VENUE_NORD, 'kickoff_time' => '14:00', 'placement_source' => 'MANUAL', 'external_ref' => null, 'ffbb_rencontre_id' => null]);

        $this->migrateOrFail($admin);

        self::assertSame(['default_friendly' => true, 'source' => 'MANUAL'], $admin->fetchAssociative('SELECT c.default_friendly, c.source FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE f.id = ?', [self::FORGED]), 'le match est dans « Amicaux », MANUAL malgré son « saisi »');
        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM fixture_federal_tracking WHERE fixture_id = ?', [self::FORGED]), 'aucune ligne de suivi');
        self::assertSame('PLACED', $this->servedStageOf(self::FORGED), 'il s\'arrête à « placé »');
    }

    /**
     * Rien ne se perd hors FBI : un match d'une compétition sans trace fédérale qui portait encore un écart, un mémo
     * « FBI affiche encore … » ou un libellé de salle gardé reçoit une ligne de suivi « à saisir » qui les garde à
     * l'identique ; la compétition reste MANUAL et le match reste servi « placé ».
     */
    public function testAMatchOutsideFbiKeepsItsDeviationsMemoAndKeptLabelOnAToEnterRow(): void
    {
        $admin = self::frozenCaseConnection();
        $deviations = [['field' => 'kickoff', 'appValue' => '14:00', 'sourceValue' => '15:00', 'channel' => 'FBI_XLSX', 'seenAt' => '2026-01-20T10:00:00+00:00', 'autoApplied' => false]];
        $echo = ['field' => 'date', 'value' => '2026-02-08', 'at' => '2026-01-21T09:00:00+00:00'];
        $this->forgeCompetition($admin, self::BARE_COMPETITION, 'Tournoi du club', null);
        $this->forgeFixture($admin, self::FORGED, self::BARE_COMPETITION, [
            'status' => 'PLACED', 'home_away' => 'HOME', 'venue_id' => self::VENUE_NORD, 'kickoff_time' => '14:00', 'placement_source' => 'MANUAL',
            'pending_deviations' => $deviations, 'fbi_echo' => $echo, 'kept_venue_label' => 'gymnase nord',
        ]);

        $this->migrateOrFail($admin);

        self::assertSame('MANUAL', $admin->fetchOne('SELECT source FROM competition WHERE id = ?', [self::BARE_COMPETITION]), 'un écart ne fait pas une trace fédérale');
        $row = $admin->fetchAssociative('SELECT state, pending_deviations, fbi_echo, kept_venue_label FROM fixture_federal_tracking WHERE fixture_id = ?', [self::FORGED]);
        self::assertIsArray($row, 'le match hors FBI garde une ligne de suivi');
        self::assertSame('TO_ENTER', $row['state']);
        self::assertSame(self::keyOrderFree($deviations), self::keyOrderFree(json_decode((string) $row['pending_deviations'], true)), 'l\'écart ouvert, à l\'identique');
        self::assertSame(self::keyOrderFree($echo), self::keyOrderFree(json_decode((string) $row['fbi_echo'], true)), 'le mémo, à l\'identique');
        self::assertSame('gymnase nord', $row['kept_venue_label'], 'le libellé de salle gardé');
        self::assertSame('PLACED', $this->servedStageOf(self::FORGED), 'le match hors FBI reste servi « placé »');
    }

    /**
     * ⚠ Écart au plan à trancher (§9, voir le docblock de la classe) : un match déjà saisi ou validé est une trace
     * fédérale de sa compétition. « Championnat 1 » et « Championnat 3 » du décor n'ont ni identifiant fédéral ni match
     * importé, mais portent des matchs saisis et validés : FBI, et leurs matchs gardent leur suivi — le domicile validé
     * posé par le système reste validé, jamais rendu au moteur. « Championnat 2 » est FBI par son match importé.
     */
    public function testAMatchAlreadyEnteredIsAFederalTraceOfItsCompetition(): void
    {
        $admin = self::frozenCaseConnection();
        $before = $admin->fetchAllKeyValue('SELECT id, status FROM fixture ORDER BY id');
        self::assertSame('VALIDATED', $before['f1c70000-0000-4000-8000-000000000182'] ?? null, 'témoin : le domicile validé posé par le système du décor');

        $this->migrateOrFail($admin);

        self::assertSame(
            ['c0e90000-0000-4000-8000-000000000001' => 'FBI', 'c0e90000-0000-4000-8000-000000000002' => 'FBI', 'c0e90000-0000-4000-8000-000000000003' => 'FBI'],
            $admin->fetchAllKeyValue('SELECT id, source FROM competition WHERE NOT default_friendly ORDER BY id'),
            'les trois championnats du décor sont FBI',
        );
        foreach ($before as $id => $status) {
            self::assertSame($status, $this->servedStageOf((string) $id), \sprintf('le match %s garde le stade servi', $id));
        }
        self::assertSame(\count($before), (int) $admin->fetchOne('SELECT COUNT(*) FROM fixture_federal_tracking'), 'une ligne de suivi par match des compétitions FBI');
    }

    /**
     * Le statut stocké n'a plus que deux valeurs : la base refuse « saisi » et « validé » (CHECK), accepte « placé ».
     */
    public function testTheStoredStatusIsClosedToTwoValues(): void
    {
        $admin = self::frozenCaseConnection();

        $this->migrateOrFail($admin);

        foreach (['SUBMITTED', 'VALIDATED'] as $refused) {
            self::assertSame('23514', $this->sqlStateOf(static fn (): int|string => $admin->executeStatement('UPDATE fixture SET status = ? WHERE id = ?', [$refused, self::MODEL_FIXTURE])), \sprintf('la base refuse le statut stocké %s', $refused));
        }
        self::assertNull($this->sqlStateOf(static fn (): int|string => $admin->executeStatement('UPDATE fixture SET status = \'PLACED\' WHERE id = ?', [self::MODEL_FIXTURE])), 'témoin : « placé » passe');
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

    private function migrateOrFail(Connection $admin): void
    {
        [$code, $output, $played] = $this->runFrozenMigrations();
        self::assertSame(0, $code, 'les conversions des PR 3b, 4a et 6b se jouent sur le décor : ' . $output);
        self::assertNotSame([], $played, 'des migrations sont jouées');
        self::assertNotNull($admin->fetchOne('SELECT to_regclass(\'public.fixture_federal_tracking\')'), 'la conversion de la PR 6b est jouée (fixture_federal_tracking)');
    }

    /** Le stade que l'API sert, lu par la porte sur l'entité, sous le club du décor. */
    private function servedStageOf(string $fixtureId): string
    {
        $this->scopeGucToClub(self::CLUB);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fixture = $em->find(Fixture::class, $fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture, \sprintf('le match %s est lisible', $fixtureId));

        return self::getContainer()->get(FederalFollowUp::class)->stageOf($fixture)->value;
    }

    /** Une compétition de U13 F, copie de « Championnat 1 » sous un autre nom, dans le schéma d'avant. */
    private function forgeCompetition(Connection $admin, string $id, string $name, ?string $ffbbCompetitionId): void
    {
        $admin->executeStatement(
            'INSERT INTO competition SELECT * FROM json_populate_record(NULL::competition, (SELECT (row_to_json(c)::jsonb || jsonb_build_object(\'id\', ?::text, \'name\', ?::text, \'ffbb_competition_id\', ?::text))::json FROM competition c WHERE c.id = ?))',
            [$id, $name, $ffbbCompetitionId, self::CHAMPIONNAT_1],
        );
    }

    /**
     * Un match de U13 F, copie du domicile placé du décor sous les colonnes données, dans le schéma d'avant.
     *
     * @param array<string, mixed> $columns
     */
    private function forgeFixture(Connection $admin, string $id, ?string $competitionId, array $columns): void
    {
        $admin->executeStatement(
            'INSERT INTO fixture SELECT * FROM json_populate_record(NULL::fixture, (SELECT (row_to_json(f)::jsonb || ?::jsonb)::json FROM fixture f WHERE f.id = ?))',
            [json_encode(['id' => $id, 'competition_id' => $competitionId, 'opponent_label' => 'Adversaire forgé'] + $columns, \JSON_THROW_ON_ERROR), self::MODEL_FIXTURE],
        );
    }

    /** Une valeur JSON décodée, clés d'objet triées à tous les niveaux ; l'ordre d'une liste reste le sien. */
    private static function keyOrderFree(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::keyOrderFree(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @param callable(): (int|string) $statement */
    private function sqlStateOf(callable $statement): ?string
    {
        try {
            $statement();

            return null;
        } catch (DriverException $e) {
            return $e->getSQLState();
        }
    }
}
