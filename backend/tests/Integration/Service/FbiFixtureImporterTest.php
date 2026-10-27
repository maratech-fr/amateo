<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\FbiCorrection;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CompetitionType;
use App\Enum\FbiCorrectionCloseSource;
use App\Enum\FbiCorrectionField;
use App\Enum\FbiIngestionSource;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Enum\SeasonStatus;
use App\Exception\ImportRejectedException;
use App\Repository\FbiIngestionRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\FbiFixtureImporter;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * FBI club-wide importer (cadrage P1-4 §3, REAL format — the frozen sample
 * tests/Fixtures/fbi/rechercherRencontre.xlsx IS the measured export, 124 rows):
 * analyze() = dry-run mapping table, import() = one-pass create + diff/update.
 *
 * Every PR-4 guard keeps a successor here: derby, unknown club, invalid dates,
 * overlong number, pinned reader, missing columns, word-boundary needle.
 */
#[Group('integration')]
final class FbiFixtureImporterTest extends KernelTestCase
{
    use TenantGucTrait;

    private const CLUB_NAME = 'BC Testville';

    private const REAL_EXPORT = __DIR__ . '/../../Fixtures/fbi/rechercherRencontre.xlsx';

    private const REAL_CLUB_NAME = 'B CHARPENNES CROIX LUIZET';

    private EntityManagerInterface $em;

    private FbiFixtureImporter $importer;

    private Club $club;

    private Team $team;

    /** @var list<string> */
    private array $tempFiles = [];

    /** Gymnase par défaut (sans alias) pour l'appariement automatique de {@see importMapped}. */
    private ?string $defaultVenueId = null;

    // ── The real export ────────────────────────────────────────────────────

    public function testRealExportAnalyzeGroupsEveryDivision(): void
    {
        [$club] = $this->realClub();

        $result = $this->importer->analyze(self::REAL_EXPORT, $club);

        // 124 data rows, 2 « Exempt » bye rounds (fact F5), zero row errors —
        // every remaining row carries the club needle on exactly one side.
        self::assertSame(124, $result['totalRows']);
        self::assertSame(2, $result['exempted']);
        self::assertSame([], $result['errors']);

        // 14 divisions (fact F1), none mapped yet, sum of rows = 122.
        self::assertCount(14, $result['divisions']);
        self::assertSame(122, array_sum(array_column($result['divisions'], 'rowCount')));
        foreach ($result['divisions'] as $division) {
            self::assertNull($division['teamId']);
            self::assertNull($division['competitionId']);
            // One club team per division in the real file → no label needed.
            self::assertNull($division['fbiTeamLabel']);
        }

        $byName = array_column($result['divisions'], 'rowCount', 'name');
        self::assertSame(22, $byName['DF2'] ?? null);
        self::assertSame(10, $byName['PNM'] ?? null);
        // Fact F8: the trailing-space division is normalized for GROUPING but
        // displayed trimmed-only (mb_substr of the trimmed cell).
        self::assertArrayHasKey('RFU13 Brassage', $byName);
    }

    public function testRealExportImportsMappedDivisionsInOnePass(): void
    {
        [$club, $teamA, $teamB] = $this->realClub();
        // Chantier A — les domiciles DF2+PNM du réel export jouent tous « GYMNASE MATEO » :
        // on apparie ce libellé au gymnase du club au dépôt (sans alias → inerte tant que
        // le serveur n'enforce pas ; couvre les domiciles une fois la règle en vigueur).
        $venueMateo = $this->createVenue('Gymnase Matéo');

        $result = $this->importer->import(self::REAL_EXPORT, $club, [
            ['division' => 'DF2', 'fbiTeamLabel' => null, 'teamId' => $teamA->getId()],
            ['division' => 'PNM', 'fbiTeamLabel' => null, 'teamId' => $teamB->getId()],
        ], [], null, [['label' => 'GYMNASE MATEO', 'venueId' => $venueMateo]]);

        self::assertSame(32, $result['created']); // 22 DF2 + 10 PNM
        self::assertSame(0, $result['updated']);
        self::assertSame(0, $result['unchanged']);
        self::assertSame(2, $result['exempted']);
        self::assertSame([], $result['errors']);
        self::assertSame([], $result['warnings']);
        self::assertCount(12, $result['unmappedDivisions']);

        // Fact F2: « 00:00 » = hour NOT set → null kickoff. DF2 n°169 is away
        // at La Perréonnaise with the venue label carried (fact F3).
        $df2 = $this->em->getRepository(Fixture::class)->findOneBy(['teamId' => $teamA->getId(), 'externalRef' => '169']);
        self::assertNotNull($df2);
        self::assertSame(FixtureHomeAway::AWAY, $df2->getHomeAway());
        self::assertNull($df2->getKickoffTime());
        self::assertSame('SALLE POLYVALENTE', $df2->getFbiVenueLabel());
        self::assertSame('2027-01-30', $df2->getMatchDate()->format('Y-m-d'));
        self::assertSame('LA PERREONNAISE BASKET', $df2->getOpponentLabel());
        self::assertSame(FixtureStatus::UNPLACED, $df2->getStatus());

        // PNM arrives timed (regional matches carry real hours).
        $pnm = $this->em->getRepository(Fixture::class)->findOneBy(['teamId' => $teamB->getId(), 'externalRef' => '101118']);
        self::assertSame(FixtureHomeAway::HOME, $pnm?->getHomeAway());
        self::assertSame('15:30', $pnm?->getKickoffTime()?->format('H:i'));
        self::assertSame('GYMNASE MATEO', $pnm?->getFbiVenueLabel());

        // The mapping persisted (fact F7): the SAME file re-imported without
        // any mapping resolves alone and changes nothing.
        $again = $this->importer->import(self::REAL_EXPORT, $club, []);
        self::assertSame(0, $again['created']);
        self::assertSame(0, $again['updated']);
        self::assertSame(32, $again['unchanged']);
        self::assertCount(12, $again['unmappedDivisions']);
    }

    // ── One-pass mapping mechanics ─────────────────────────────────────────

    public function testUnmappedDivisionRowsAreReportedNotCreatedNotErrors(): void
    {
        $file = $this->xlsx([
            ['D2', 'R1001', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
        ]);

        $result = $this->importer->import($file, $this->club, []);

        self::assertSame(0, $result['created']);
        self::assertSame([], $result['errors']);
        self::assertSame([['name' => 'D2', 'fbiTeamLabel' => null, 'rowCount' => 1]], $result['unmappedDivisions']);
        self::assertCount(0, $this->em->getRepository(Fixture::class)->findBy(['teamId' => $this->team->getId()]));
    }

    public function testMappingPersistsACompetitionAndInfersBrassage(): void
    {
        $result = $this->importMapped([
            ['RMU18 Brassage', 'R2001', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '', ''],
        ], [
            ['division' => 'RMU18 Brassage', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId()],
        ]);

        self::assertSame(1, $result['created']);
        $competition = $this->em->getRepository(Competition::class)->findOneBy(['teamId' => $this->team->getId()]);
        self::assertSame('RMU18 Brassage', $competition?->getName());
        self::assertSame(CompetitionType::BRASSAGE, $competition?->getCompetitionType());

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'R2001']);
        self::assertSame($competition?->getId(), $fixture?->getCompetitionId());
    }

    public function testTwoClubTeamsInOneDivisionResolveByLabel(): void
    {
        $teamTwo = $this->createTeam('U15-2');
        $rows = [
            ['D2', 'A1', 'BC TESTVILLE - 1', 'AS X', '03/10/2026', '', ''],
            ['D2', 'A2', 'BC TESTVILLE - 2', 'AS Y', '03/10/2026', '', ''],
        ];
        $file = $this->xlsx($rows);

        // Analyze splits the division per club-team label (décision fondateur
        // 2026-08-02 : le suffixe « - 2 » appareille la 2ᵉ équipe).
        $analysis = $this->importer->analyze($file, $this->club);
        $labels = array_column($analysis['divisions'], 'fbiTeamLabel');
        sort($labels);
        self::assertSame(['BC TESTVILLE - 1', 'BC TESTVILLE - 2'], $labels);

        $result = $this->importMapped($rows, [
            ['division' => 'D2', 'fbiTeamLabel' => 'BC TESTVILLE - 1', 'teamId' => $this->team->getId()],
            ['division' => 'D2', 'fbiTeamLabel' => 'BC TESTVILLE - 2', 'teamId' => $teamTwo->getId()],
        ]);

        self::assertSame(2, $result['created']);
        self::assertSame('AS X', $this->em->getRepository(Fixture::class)->findOneBy(['teamId' => $this->team->getId()])?->getOpponentLabel());
        self::assertSame('AS Y', $this->em->getRepository(Fixture::class)->findOneBy(['teamId' => $teamTwo->getId()])?->getOpponentLabel());

        // The stored labels resolve the re-analyze without any new mapping.
        $again = $this->importer->analyze($file, $this->club);
        foreach ($again['divisions'] as $division) {
            self::assertNotNull($division['teamId']);
        }
    }

    // ── Poule guard + completeness + suggestion (P1-4 PR F2) ─────────────────

    public function testWrongFileOnAPairedDivisionIsRefusedNamedAndSkipped(): void
    {
        // The division is paired to a poule whose clubs are known; the file's
        // opponents are ALL foreign (> 50 %) → error NAMED, division SKIPPED,
        // the other division still imports (founder decision 2026-08-03).
        $paired = $this->pairedCompetition('D2', ['AS VOISINS', 'BC RIVAUX', 'ES AILLEURS'], 12);
        $teamTwo = $this->createTeam('U15-2');

        $result = $this->importMapped([
            ['D2', 'X1', 'BC TESTVILLE - 1', 'US INCONNU', '03/10/2026', '', ''],
            ['D2', 'X2', 'BC TESTVILLE - 1', 'CS MYSTERE', '10/10/2026', '', ''],
            ['D3', 'Y1', 'BC TESTVILLE - 1', 'AS Libre', '03/10/2026', '', ''],
        ], [
            ['division' => 'D3', 'fbiTeamLabel' => null, 'teamId' => $teamTwo->getId()],
        ]);

        self::assertSame(1, $result['created'], 'the healthy division imports');
        self::assertNull($this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'X1']), 'the faulty division is skipped');
        $pouleErrors = array_values(array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'hors de la poule')));
        self::assertCount(1, $pouleErrors);
        self::assertStringContainsString('Division « D2 » ignorée', $pouleErrors[0]);
        self::assertStringContainsString('US INCONNU', $pouleErrors[0]);
        self::assertSame($paired->getId(), $this->em->getRepository(Competition::class)->findOneBy(['name' => 'D2'])?->getId());
    }

    public function testMinorPouleDriftIsAWarningNotARefusal(): void
    {
        // 1 unknown out of 3 distinct (≤ 50 %) → POULE_MISMATCH warning, import passes.
        $this->pairedCompetition('D2', ['AS VOISINS', 'BC RIVAUX', 'ES AILLEURS'], 12);

        $result = $this->importMapped([
            ['D2', 'X1', 'BC TESTVILLE - 1', 'AS VOISINS', '03/10/2026', '', ''],
            ['D2', 'X2', 'BC TESTVILLE - 1', 'BC RIVAUX', '10/10/2026', '', ''],
            ['D2', 'X3', 'BC TESTVILLE - 1', 'US INTRUS', '17/10/2026', '', ''],
        ], []);

        self::assertSame(3, $result['created']);
        $mismatch = array_values(array_filter($result['warnings'], static fn (array $w): bool => 'POULE_MISMATCH' === $w['type']));
        self::assertCount(1, $mismatch);
        self::assertStringContainsString('US INTRUS', $mismatch[0]['message']);
    }

    public function testDivisionWithoutPairingIsNeverChecked(): void
    {
        // No FFBB refs on the competition → today's behaviour, zero control.
        $result = $this->importMapped([
            ['D2', 'X1', 'BC TESTVILLE - 1', 'N IMPORTE QUI', '03/10/2026', '', ''],
        ], [
            ['division' => 'D2', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId()],
        ]);

        self::assertSame(1, $result['created']);
        self::assertSame([], array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'poule')));
    }

    public function testCompletenessNamesThePartialFile(): void
    {
        // Expectation frozen at pairing: 22 matchdays; the file brings 2 → named.
        $this->pairedCompetition('D2', ['AS VOISINS', 'BC RIVAUX'], 22);

        $result = $this->importMapped([
            ['D2', 'X1', 'BC TESTVILLE - 1', 'AS VOISINS', '03/10/2026', '', ''],
            ['D2', 'X2', 'BC TESTVILLE - 1', 'BC RIVAUX', '10/10/2026', '', ''],
        ], []);

        self::assertCount(1, $result['completeness']);
        self::assertSame(2, $result['completeness'][0]['imported']);
        self::assertSame(22, $result['completeness'][0]['expected']);
    }

    public function testAnalyzeSuggestsThePairedCompetitionForAnUnmappedDivision(): void
    {
        // The real inter-phase case: the stored competition carries the OLD FBI
        // division label as its name (« RM2 »), the pairing gave it the CANONICAL
        // FFBB name — the NEW phase's file uses the canonical wording. The name
        // resolver misses, the canonical suggester hits → suggestion, never a
        // resolution (teamId stays null, the manager confirms).
        $this->pairedCompetition('RM2', ['AS VOISINS'], 14, canonicalName: 'Pré régionale masculine');
        $file = $this->xlsx([
            ['PRE REGIONALE MASCULINE', 'X1', 'BC TESTVILLE - 1', 'AS VOISINS', '03/10/2026', '', ''],
        ]);

        $analysis = $this->importer->analyze($file, $this->club);

        self::assertNull($analysis['divisions'][0]['teamId']);
        self::assertSame($this->team->getId(), $analysis['divisions'][0]['suggestedTeamId']);
    }

    public function testAnalyzeBridgesAnUnmappedDivisionBySignatureWhenTheCanonicalNameMisses(): void
    {
        // Décision fondateur 2026-10-01 — le nom stocké n'est NI le libellé du fichier
        // (« RM2 ») NI une variante normalisable du nom canonique : seul le PONT SIGNATURE
        // (code FBI « RM2 » → {R, div 2, M, seniors} ⇄ « … - Division 2 ») retrouve la
        // compétition appariée. Suggestion, jamais résolution (teamId reste null).
        $this->pairedCompetition('ANCIEN CODE', ['AS VOISINS'], 14, canonicalName: 'Régionale masculine seniors - Division 2');
        $file = $this->xlsx([
            ['RM2', 'X1', 'BC TESTVILLE - 1', 'AS VOISINS', '03/10/2026', '', ''],
        ]);

        $analysis = $this->importer->analyze($file, $this->club);

        self::assertNull($analysis['divisions'][0]['teamId'], 'a suggestion is never a resolution');
        self::assertSame($this->team->getId(), $analysis['divisions'][0]['suggestedTeamId'], 'the signature bridge finds the paired competition');
    }

    public function testARemapWithADriftedLabelUpdatesTheStoredOne(): void
    {
        // Multi-label division mapped once with « - 1 »… then the FBI export
        // drifts the club label. Re-mapping with the NEW label must update the
        // stored one — otherwise the division stays unmapped forever (the
        // manager re-maps, the import still says unmapped: a dead loop).
        $teamTwo = $this->createTeam('U15-2');
        $this->importMapped([
            ['D2', 'B1', 'BC TESTVILLE - 1', 'AS X', '03/10/2026', '', ''],
            ['D2', 'B2', 'BC TESTVILLE - 2', 'AS Y', '03/10/2026', '', ''],
        ], [
            ['division' => 'D2', 'fbiTeamLabel' => 'BC TESTVILLE - 1', 'teamId' => $this->team->getId()],
            ['division' => 'D2', 'fbiTeamLabel' => 'BC TESTVILLE - 2', 'teamId' => $teamTwo->getId()],
        ]);

        $result = $this->importMapped([
            ['D2', 'B3', 'BC TESTVILLE 1B', 'AS Z', '10/10/2026', '', ''],
            ['D2', 'B4', 'BC TESTVILLE - 2', 'AS W', '10/10/2026', '', ''],
        ], [
            ['division' => 'D2', 'fbiTeamLabel' => 'BC TESTVILLE 1B', 'teamId' => $this->team->getId()],
        ]);

        self::assertSame(2, $result['created']);
        self::assertSame([], $result['unmappedDivisions']);
        $competition = $this->em->getRepository(Competition::class)->findOneBy(['teamId' => $this->team->getId(), 'name' => 'D2']);
        self::assertSame('BC TESTVILLE 1B', $competition?->getFbiTeamLabel());
    }

    public function testForeignTeamInMappingIsRejected(): void
    {
        // Tenant+season filters make a foreign teamId invisible → clean 400,
        // no cross-tenant Competition write.
        $file = $this->xlsx([['D2', 'R1', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '', '']]);

        $this->expectException(ImportRejectedException::class);
        $this->importer->import($file, $this->club, [
            ['division' => 'D2', 'fbiTeamLabel' => null, 'teamId' => '00000000-0000-4000-8000-000000000000'],
        ]);
    }

    // ── Diff/update semantics ──────────────────────────────────────────────

    public function testRescheduledDateUnplacesAndWarns(): void
    {
        // RMM-4: a date écart on a PLACED home is now a CHOICE — « take_file »
        // keeps the pre-RMM-4 reschedule (la ligue a re-décidé → un-place).
        $this->importMapped([['D2', 'R3001', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('R3001');
        $id = $this->fixtureId('R3001');

        $result = $this->importMapped(
            [['D2', 'R3001', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']],
            null,
            [['fixtureId' => $id, 'field' => 'date', 'choice' => 'take_file']],
        );

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['updated']);
        self::assertSame([], $result['unresolvedDeviations']);
        self::assertSame('RESCHEDULED', $result['warnings'][0]['type']);
        self::assertStringContainsString('placement annulé', $result['warnings'][0]['message']);

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'R3001']);
        self::assertSame('2026-10-10', $fixture?->getMatchDate()->format('Y-m-d'));
        self::assertSame(FixtureStatus::UNPLACED, $fixture?->getStatus());
        self::assertNull($fixture?->getVenueId());
    }

    public function testHomeAwaySwitchUnplacesAndWarns(): void
    {
        $this->importMapped([['D2', 'R3002', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('R3002');

        // La ligue force le switch : mêmes équipes, côtés inversés.
        $result = $this->importMapped([['D2', 'R3002', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', '']]);

        self::assertSame(1, $result['updated']);
        self::assertSame('SWITCHED', $result['warnings'][0]['type']);
        self::assertStringContainsString('EXTÉRIEUR', $result['warnings'][0]['message']);
        self::assertStringContainsString('libéré', $result['warnings'][0]['message']);

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'R3002']);
        self::assertSame(FixtureHomeAway::AWAY, $fixture?->getHomeAway());
        self::assertSame(FixtureStatus::UNPLACED, $fixture?->getStatus());
        self::assertNull($fixture?->getVenueId());
    }

    public function testRealHourChangeUpdatesInPlaceKeepingTheVenue(): void
    {
        // RMM-4: a kickoff écart on a PLACED home is a CHOICE — « take_file »
        // updates the hour IN PLACE (venue kept), the pre-RMM-4 behaviour.
        $this->importMapped([['D2', 'R3003', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $venueId = $this->place('R3003');
        $id = $this->fixtureId('R3003');

        $result = $this->importMapped(
            [['D2', 'R3003', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', '']],
            null,
            [['fixtureId' => $id, 'field' => 'kickoff', 'choice' => 'take_file']],
        );

        // The hour is the league's; the venue stays the club's choice.
        self::assertSame(1, $result['updated']);
        self::assertSame([], $result['unresolvedDeviations']);
        self::assertSame('RESCHEDULED', $result['warnings'][0]['type']);

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'R3003']);
        self::assertSame('17:00', $fixture?->getKickoffTime()?->format('H:i'));
        self::assertSame(FixtureStatus::PLACED, $fixture?->getStatus());
        self::assertSame($venueId, $fixture?->getVenueId());
    }

    public function testZeroZeroNeverErasesAClubSetKickoff(): void
    {
        $this->importMapped([['D2', 'R3004', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '00:00', '']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'R3004']);
        self::assertNull($fixture?->getKickoffTime()); // fact F2: sentinel → not set

        // The club proposes 13:30 (places the match)…
        $fixture?->setKickoffTime(new DateTimeImmutable('1970-01-01 13:30'));
        $this->em->flush();

        // …and the league file still says 00:00: NOT an erasure.
        $result = $this->importMapped([['D2', 'R3004', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '00:00', '']]);

        self::assertSame(1, $result['unchanged']);
        self::assertSame(0, $result['updated']);
        $this->em->refresh($fixture);
        self::assertSame('13:30', $fixture->getKickoffTime()?->format('H:i'));
    }

    public function testVenueLabelDriftUpdatesSilently(): void
    {
        $this->importMapped([['D2', 'R3005', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '', 'Gymnase X']]);

        $result = $this->importMapped([['D2', 'R3005', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '', 'Gymnase Y']]);

        self::assertSame(1, $result['updated']);
        self::assertSame([], $result['warnings']);
        self::assertSame('Gymnase Y', $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'R3005'])?->getFbiVenueLabel());
    }

    public function testSameNumberInTwoDivisionsIsNotADuplicate(): void
    {
        // Fact F6: « 26 » exists in RMU18 Brassage AND DF2 in the real export —
        // the number is only unique within its division (→ its team).
        $teamTwo = $this->createTeam('U15-2');
        $result = $this->importMapped(
            [
                ['D2', '26', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '', ''],
                ['D3', '26', 'BC TESTVILLE - 2', 'AS Autres', '03/10/2026', '', ''],
            ],
            [
                ['division' => 'D2', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId()],
                ['division' => 'D3', 'fbiTeamLabel' => null, 'teamId' => $teamTwo->getId()],
            ],
        );

        self::assertSame(2, $result['created']);
    }

    // ── Identité d'une rencontre = équipe + DIVISION + numéro (D1/D2, M15) ──

    public function testANumberReusedUnderAnotherDivisionOfTheSameTeamCreatesANewMatchAndLeavesTheFirstIntact(): void
    {
        // M15 (retours BCCL 2026-10-27) : l'équipe joue DFU18-3 n°22 (phase 1, placé),
        // puis RFU18-3 n°22 (phase 2) — DEUX championnats de la MÊME équipe réutilisent le
        // n°22. Ce sont DEUX rencontres distinctes ; le dépôt de la phase 2 ne doit JAMAIS
        // réécrire la rencontre de la phase 1. Aujourd'hui la clé « équipe|numéro » les
        // confond → la phase 2 dé-place / dé-phase le match de la phase 1.
        $this->importMapped([['DFU18-3', '22', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X']]);
        $venueId = $this->place('22'); // phase 1 : PLACÉE (venue + statut PLACED)

        $result = $this->importMapped([['RFU18-3', '22', 'BC TESTVILLE - 1', 'AS Voisins', '14/11/2026', '15:30', 'Gymnase Y']]);

        self::assertSame(1, $result['created'], 'le n°22 de la 2ᵉ division est une NOUVELLE rencontre');
        $this->em->clear();

        self::assertCount(2, $this->em->getRepository(Fixture::class)->findBy(['teamId' => $this->team->getId(), 'externalRef' => '22']), 'les deux phases coexistent');

        $phase1 = $this->fixtureUnderDivision('22', 'DFU18-3');
        self::assertNotNull($phase1);
        self::assertSame('2026-10-10', $phase1->getMatchDate()->format('Y-m-d'), 'la phase 1 garde SA date');
        self::assertSame('AS Voisins', $phase1->getOpponentLabel());
        self::assertSame(FixtureStatus::PLACED, $phase1->getStatus(), 'la phase 1 reste placée');
        self::assertSame($venueId, $phase1->getVenueId(), 'la phase 1 garde son gymnase');
        self::assertSame(FixtureReviewState::REVIEWED, $phase1->getReviewState(), 'la phase 1 n\'est pas dé-phasée par la phase 2');
        self::assertSame([], $phase1->getPendingDeviations());

        $phase2 = $this->fixtureUnderDivision('22', 'RFU18-3');
        self::assertNotNull($phase2, 'la phase 2 existe bien sous SON championnat');
        self::assertSame('2026-11-14', $phase2->getMatchDate()->format('Y-m-d'));
    }

    public function testBothPhasesInOneFileKeepBothFixturesNeverSilentlySkippingALine(): void
    {
        // U13F3 (prod BCCL) : les deux phases dans le MÊME fichier, même numéro, même équipe,
        // divisions différentes → DEUX matchs. La dédup intra-fichier « équipe|numéro »
        // (seenInFile) saute aujourd'hui la 2ᵉ ligne EN SILENCE ; elle doit porter sur
        // « équipe|division|numéro ».
        $result = $this->importMapped([
            ['DFU13-3', '10', 'BC TESTVILLE - 1', 'AS Un', '03/10/2026', '15:30', 'Gymnase X'],
            ['DFU13-3-P2', '10', 'BC TESTVILLE - 1', 'AS Deux', '14/11/2026', '15:30', 'Gymnase X'],
        ]);

        self::assertSame(2, $result['created'], 'les deux divisions produisent deux matchs — aucune ligne sautée en silence');
        $this->em->clear();
        self::assertNotNull($this->fixtureUnderDivision('10', 'DFU13-3'));
        self::assertNotNull($this->fixtureUnderDivision('10', 'DFU13-3-P2'));
    }

    public function testReDepositingTheSameDivisionAndNumberIsIdempotentNeverASecondFixture(): void
    {
        // « même division (normalisée) + même numéro = même match » : un re-dépôt, même avec
        // une graphie de division qui ne diffère que par la casse/les espaces, ne crée jamais
        // de doublon (la division entre dans la clé NORMALISÉE).
        $first = $this->importMapped([['DFU18-3', '22', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X']]);
        self::assertSame(1, $first['created']);

        $again = $this->importMapped([['  dfu18-3 ', '22', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X']]);
        self::assertSame(0, $again['created'], 'même division normalisée + même numéro → aucun nouveau match');
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Fixture::class)->findBy(['teamId' => $this->team->getId(), 'externalRef' => '22']));
    }

    public function testADuplicateDivisionAndNumberIsRejectedButTheSameNumberUnderAnotherDivisionCoexists(): void
    {
        // La contrainte d'unicité porte sur (club, saison, équipe, division, ref) : deux
        // rencontres de la même équipe partageant le n°22 sous DEUX divisions coexistent ;
        // un vrai doublon (même équipe, même division, même ref) est refusé par l'index.
        $this->em->persist($this->rawReferencedFixture('22', 'DFU18-3', 'AS Voisins', '2026-10-10'));
        $this->em->persist($this->rawReferencedFixture('22', 'RFU18-3', 'AS Autres', '2026-11-14'));
        $this->em->flush(); // le même numéro sous deux divisions coexiste

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->persist($this->rawReferencedFixture('22', 'DFU18-3', 'AS Encore', '2026-12-05'));
        $this->em->flush();
    }

    // ── Adversaire différent = ANOMALIE, jamais un écrasement (décision fondateur) ──

    public function testSameDivisionAndNumberWithADifferentOpponentIsAnAnomalyLeftIntactAndWarned(): void
    {
        // Décision fondateur 2026-10-27 : « un match ne change pas d'adversaire ; si ça arrive
        // c'est un bug FFBB ». Même division + même numéro mais adversaire différent dans le
        // fichier = ANOMALIE — la rencontre existante n'est PAS modifiée (ni adversaire, ni
        // date, ni statut) ; la ligne est écartée et SIGNALÉE au rapport. Aujourd'hui
        // `opponentLabel` est écrasé en silence (FbiFixtureImporter l.1280-1283 / 1360-1363).
        $this->importMapped([['D2', '5', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X']]);
        $this->place('5');

        $result = $this->importMapped([['D2', '5', 'BC TESTVILLE - 1', 'AS Autres', '03/10/2026', '15:30', 'Gymnase X']]);

        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['updated'], 'une anomalie n\'écrit RIEN');
        $warning = array_values(array_filter($result['warnings'], static fn (array $w): bool => 'OPPONENT_CHANGED' === $w['type']));
        self::assertCount(1, $warning, 'l\'anomalie est signalée au rapport');
        self::assertStringContainsString('AS Autres', $warning[0]['message']);
        self::assertStringContainsString('AS Voisins', $warning[0]['message']);

        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => '5']);
        self::assertSame('AS Voisins', $fixture?->getOpponentLabel(), 'l\'adversaire d\'origine est intact');
        self::assertSame(FixtureStatus::PLACED, $fixture?->getStatus());
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
    }

    // ── D4 : interdire de greffer une 2ᵉ phase sur le championnat de la 1ʳᵉ ──

    public function testMappingADivisionOntoACompetitionAlreadyFilledWithFbiRencontresUnderAnotherLabelIsRefused(): void
    {
        // D4 (M15 / U13F3, décision fondateur « ce sont deux championnats différents ») :
        // rattacher la division de la 2ᵉ phase (« DFU13-3-P2 ») au championnat DÉJÀ peuplé de
        // rencontres FBI de la 1ʳᵉ (« DFU13-3 ») = refus NOMMÉ, division sautée, le championnat
        // d'origine ni renommé ni absorbé. Aujourd'hui le mapping porteur du competitionId
        // RENOMME le championnat (FbiFixtureImporter l.1543-1553) → absorption.
        $this->importMapped([['DFU13-3', '1', 'BC TESTVILLE - 1', 'AS Un', '03/10/2026', '15:30', 'Gymnase X']]);
        $c1 = $this->em->getRepository(Competition::class)->findOneBy(['teamId' => $this->team->getId(), 'name' => 'DFU13-3']);
        self::assertNotNull($c1);
        $c1Id = $c1->getId();

        $result = $this->importer->import(
            $this->xlsx([['DFU13-3-P2', '2', 'AS Deux', 'BC TESTVILLE - 1', '14/11/2026', '15:30', '']]),
            $this->club,
            [['division' => 'DFU13-3-P2', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId(), 'competitionId' => $c1Id]],
            [], null, [], [],
        );

        $refusals = array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'DFU13-3') && str_contains($e, 'championnat'));
        self::assertNotSame([], $refusals, 'la 2ᵉ phase refuse de se greffer sur le championnat de la 1ʳᵉ');
        $this->em->clear();
        self::assertSame('DFU13-3', $this->em->getRepository(Competition::class)->find($c1Id)?->getName(), 'le championnat de la 1ʳᵉ phase n\'est ni renommé ni absorbé');
        self::assertNull($this->em->getRepository(Fixture::class)->findOneBy(['teamId' => $this->team->getId(), 'externalRef' => '2']), 'la rencontre de la 2ᵉ phase n\'est pas greffée');
        self::assertNotNull($this->em->getRepository(Fixture::class)->findOneBy(['competitionId' => $c1Id, 'externalRef' => '1']), 'la 1ʳᵉ phase reste intacte');
    }

    public function testMappingOntoACompetitionFilledOnlyByApiRencontresIsAccepted(): void
    {
        // Contre-cas D4 (le garde ne sur-bloque pas) : une compétition peuplée UNIQUEMENT de
        // rencontres du canal API (external_ref NULL, ffbb_rencontre_id posé) n'est PAS un
        // championnat FBI — un mapping de division s'y rattache légitimement.
        $cup = $this->rawCompetition('Coupe API');
        $apiFixture = new Fixture;
        $apiFixture->setClubId($this->club->getId());
        $apiFixture->setSeasonId($this->team->getSeasonId());
        $apiFixture->setTeamId($this->team->getId());
        $apiFixture->setCompetitionId($cup->getId());
        $apiFixture->setMatchDate(new DateTimeImmutable('2026-11-01'));
        $apiFixture->setHomeAway(FixtureHomeAway::AWAY);
        $apiFixture->setOpponentLabel('AS API');
        $apiFixture->setFfbbRencontreId('RENC-API-1'); // canal API, external_ref reste NULL
        $this->em->persist($apiFixture);
        $this->em->flush();

        $result = $this->importer->import(
            $this->xlsx([['Coupe API', '9', 'AS Away', 'BC TESTVILLE - 1', '14/11/2026', '15:30', '']]),
            $this->club,
            [['division' => 'Coupe API', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId(), 'competitionId' => $cup->getId()]],
            [], null, [], [],
        );

        self::assertSame([], array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'championnat')), 'une compétition du canal API seul ne bloque pas');
        self::assertSame(1, $result['created']);
    }

    public function testAForgedCompetitionIdNeverHijacksAChampionshipThroughTheImportOrTheGuard(): void
    {
        // Abus nommé (tenant) : un mapping forgé portant un competitionId qui ne résout PAS sous
        // le filtre tenant (étranger ou inexistant) n'écrit jamais ailleurs — l'import crée le
        // PROPRE championnat du club courant, et le garde D4 (qui lit les rencontres de la cible)
        // n'ouvre aucune porte cross-tenant. L'isolation stricte de bout en bout est prouvée par
        // {@see App\Tests\Security\MatchTenantIsolationTest} + {@see testForeignTeamInMappingIsRejected}.
        $result = $this->importer->import(
            $this->xlsx([['DX', '7', 'AS Away', 'BC TESTVILLE - 1', '14/11/2026', '15:30', '']]),
            $this->club,
            [['division' => 'DX', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId(), 'competitionId' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']],
            [], null, [], [],
        );

        self::assertSame(1, $result['created']);
        $comp = $this->em->getRepository(Competition::class)->findOneBy(['teamId' => $this->team->getId(), 'name' => 'DX']);
        self::assertNotNull($comp, 'l\'import crée le championnat du club courant, jamais sur un id forgé');
        self::assertSame($comp?->getId(), $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => '7'])?->getCompetitionId());
    }

    // ── Reconciliation FBI (RMM-4) ─────────────────────────────────────────

    public function testPlacedHomeDateDivergenceIsAReportedDeviationNotWritten(): void
    {
        $this->importMapped([['D2', 'RD01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X']]);
        $this->place('RD01');

        // No decision: the écart is NOT applied — reported, app value INTACT.
        $result = $this->importMapped([['D2', 'RD01', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X']]);

        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['updated']);
        self::assertSame(1, $result['unchanged']);
        self::assertCount(1, $result['unresolvedDeviations']);
        $deviation = $result['unresolvedDeviations'][0];
        self::assertSame('RD01', $deviation['externalRef']);
        self::assertFalse($deviation['persisting']);
        self::assertSame(['app' => '2026-10-03', 'file' => '2026-10-10'], $deviation['fields']['date']);

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RD01']);
        self::assertSame('2026-10-03', $fixture?->getMatchDate()->format('Y-m-d'));
        self::assertSame(FixtureStatus::PLACED, $fixture?->getStatus());
    }

    public function testUndecidedDivergenceBornsAPersistingTraceThenKeepAppResolvesIt(): void
    {
        // PR-3a — the trace lives on the fixture now : an UNDECIDED perimeter écart
        // borns a pending entry (OUT_OF_SYNC), reappears « persisting » next deposit
        // with the SAME seenAt, and a keep_app DECISION resolves it (REVIEWED, D5).
        // Distinct deposit instants so seenAt preservation is observable.
        $this->pinClock(new DateTimeImmutable('2026-09-01 10:00:00'));
        $this->importMapped([['D2', 'RD02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RD02');
        $id = $this->fixtureId('RD02');

        // Deposit 1, NO decision: nothing written, an écart is born — not yet persisting.
        $this->pinClock(new DateTimeImmutable('2026-09-08 10:00:00'));
        $r1 = $this->importMapped([['D2', 'RD02', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']]);
        self::assertSame(0, $r1['updated']);
        self::assertCount(1, $r1['unresolvedDeviations']);
        self::assertFalse($r1['unresolvedDeviations'][0]['persisting']);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RD02']);
        self::assertSame('2026-10-03', $fixture?->getMatchDate()->format('Y-m-d'), 'app value intact');
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $fixture?->getReviewState());
        $bornSeenAt = $fixture?->getPendingDeviation('date')['seenAt'] ?? null;
        self::assertNotNull($bornSeenAt);

        // Deposit 2, still no decision, same divergence: persisting, seenAt conserved.
        $this->pinClock(new DateTimeImmutable('2026-09-15 10:00:00'));
        $r2 = $this->importMapped([['D2', 'RD02', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']]);
        self::assertCount(1, $r2['unresolvedDeviations']);
        self::assertTrue($r2['unresolvedDeviations'][0]['persisting']);
        $this->em->clear();
        $fixture2 = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RD02']);
        self::assertSame($bornSeenAt, $fixture2?->getPendingDeviation('date')['seenAt'] ?? null, 'seenAt is preserved across deposits');

        // Deposit 3, keep_app: the écart is RETIRED → REVIEWED, app value kept (D5).
        $r3 = $this->importMapped(
            [['D2', 'RD02', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']],
            null,
            [['fixtureId' => $id, 'field' => 'date', 'choice' => 'keep_app']],
        );
        self::assertSame([], $r3['unresolvedDeviations']);
        $this->em->clear();
        $fixture3 = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RD02']);
        self::assertSame(FixtureReviewState::REVIEWED, $fixture3?->getReviewState());
        self::assertSame([], $fixture3?->getPendingDeviations());
        self::assertSame('2026-10-03', $fixture3?->getMatchDate()->format('Y-m-d'), 'keep_app never writes the file value');

        // A conforming re-deposit: no deviation at all.
        $conform = $this->importer->analyze($this->xlsx([['D2', 'RD02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]), $this->club);
        self::assertSame([], $conform['deviations']);
    }

    public function testTakeFileOnADateUnplacesAndResolvesTheEcart(): void
    {
        // Retained semantics: take_file on a DATE = la ligue a re-décidé → the
        // placement is invalidated (un-placed). The écart is then resolved.
        $this->importMapped([['D2', 'RD03', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RD03');
        $id = $this->fixtureId('RD03');

        $result = $this->importMapped(
            [['D2', 'RD03', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']],
            null,
            [['fixtureId' => $id, 'field' => 'date', 'choice' => 'take_file']],
        );
        self::assertSame(1, $result['updated']);
        self::assertSame([], $result['unresolvedDeviations']);

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RD03']);
        self::assertSame('2026-10-10', $fixture?->getMatchDate()->format('Y-m-d'));
        self::assertSame(FixtureStatus::UNPLACED, $fixture?->getStatus());
        self::assertNull($fixture?->getVenueId());

        // Idempotence: re-deposit the same file — now UNPLACED, no deviation.
        $after = $this->importer->analyze($this->xlsx([['D2', 'RD03', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']]), $this->club);
        self::assertSame([], $after['deviations']);
    }

    public function testAwayDivergenceNeverProducesADeviationTheFileWinsDirectly(): void
    {
        // Fondateur invariant: an AWAY match is informational only — never a
        // choice. Even forced « placed », an écart on it is written directly.
        $this->importMapped([['D2', 'RA01', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $away = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RA01']);
        self::assertSame(FixtureHomeAway::AWAY, $away?->getHomeAway());
        $away?->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $this->em->flush();

        $result = $this->importMapped([['D2', 'RA01', 'AS Voisins', 'BC TESTVILLE - 1', '10/10/2026', '15:30', 'Salle Adverse']]);

        self::assertSame([], $result['unresolvedDeviations']);
        self::assertSame(1, $result['updated']); // the file wrote the new date directly
        self::assertSame('2026-10-10', $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RA01'])?->getMatchDate()->format('Y-m-d'));
    }

    public function testUnplacedHomeDivergenceIsNotADeviationTheFileWins(): void
    {
        // A home match still « à placer » keeps the pre-RMM-4 behaviour: the file
        // wins (reschedule), no deviation to arbitrate.
        $this->importMapped([['D2', 'RH01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);

        $result = $this->importMapped([['D2', 'RH01', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']]);

        self::assertSame([], $result['unresolvedDeviations']);
        self::assertSame(1, $result['updated']);
        self::assertSame('2026-10-10', $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RH01'])?->getMatchDate()->format('Y-m-d'));
    }

    public function testSubmittedTakeFileDropsToPlacedSubmittedKeepAppStaysSubmitted(): void
    {
        // take_file on a SUBMITTED fixture: writes the value AND un-submits to
        // PLACED (the FBI checkmark was on a wrong hour); keep_app leaves it.
        $this->importMapped([['D2', 'RS01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RS01');
        $this->setStatus('RS01', FixtureStatus::SUBMITTED);
        $id = $this->fixtureId('RS01');

        $take = $this->importMapped(
            [['D2', 'RS01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', '']],
            null,
            [['fixtureId' => $id, 'field' => 'kickoff', 'choice' => 'take_file']],
        );
        self::assertSame([], $take['unresolvedDeviations']);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RS01']);
        self::assertSame(FixtureStatus::PLACED, $fixture?->getStatus());
        self::assertSame('17:00', $fixture?->getKickoffTime()?->format('H:i'));

        // Re-submit, then keep_app on a fresh divergence: status untouched.
        $this->setStatus('RS01', FixtureStatus::SUBMITTED);
        $keep = $this->importMapped(
            [['D2', 'RS01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '18:30', '']],
            null,
            [['fixtureId' => $id, 'field' => 'kickoff', 'choice' => 'keep_app']],
        );
        self::assertSame([], $keep['unresolvedDeviations']);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RS01']);
        self::assertSame(FixtureStatus::SUBMITTED, $fixture?->getStatus());
        self::assertSame('17:00', $fixture?->getKickoffTime()?->format('H:i'));
    }

    // ── Registre « à corriger dans FBI » (keep_app crée) + mémo fbiEcho ──────

    public function testKeepAppOpensAnFbiCorrectionEntry(): void
    {
        // « Garder l'appli » sur un écart de DATE d'un domicile placé : FBI est en
        // retard → une entrée OUVERTE « à corriger dans FBI » (app = ce qu'il faut
        // taper, fbi = ce que FBI affiche encore). L'écart pendant, lui, est retiré.
        $this->importMapped([['D2', 'RK01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RK01');
        $id = $this->fixtureId('RK01');

        $this->importMapped(
            [['D2', 'RK01', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']],
            null,
            [['fixtureId' => $id, 'field' => 'date', 'choice' => 'keep_app']],
        );
        $this->em->clear();

        $entries = $this->em->getRepository(FbiCorrection::class)->findBy(['fixtureId' => $id]);
        self::assertCount(1, $entries);
        self::assertTrue($entries[0]->isOpen());
        self::assertSame(FbiCorrectionField::DATE, $entries[0]->getField());
        self::assertSame('2026-10-03', $entries[0]->getAppValue(), 'la valeur de l\'appli — à taper dans FBI');
        self::assertSame('2026-10-10', $entries[0]->getFbiValue(), 'la valeur que FBI affiche encore');
        self::assertNotNull($entries[0]->getLastSeenInFbiAt());
    }

    public function testKeepAppOnVenueServesTheAmateoVenueFbiAlias(): void
    {
        // « Garder l'appli » sur un écart de SALLE d'un domicile placé dans un gymnase
        // qui porte un alias FBI confirmé → l'entrée sert `venueFbiLabel` (le nom FBI
        // du gymnase de l'appli), pour dire quoi sélectionner dans FBI.
        $venueId = $this->createVenueWithAliases('Gymnase Coubertin', ['gymnase pierre de coubertin']);
        $this->importMapped([['D2', 'RK02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase Coubertin']]);
        $this->placeAt('RK02', $venueId);
        $id = $this->fixtureId('RK02');

        $this->importMapped(
            [['D2', 'RK02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']],
            null,
            [['fixtureId' => $id, 'field' => 'venue', 'choice' => 'keep_app']],
        );
        $this->em->clear();

        $entry = $this->em->getRepository(FbiCorrection::class)->findOneBy(['fixtureId' => $id, 'field' => FbiCorrectionField::VENUE]);
        self::assertInstanceOf(FbiCorrection::class, $entry);
        self::assertSame('Gymnase Coubertin', $entry->getAppValue());
        self::assertSame('GYMNASE MATEO', $entry->getFbiValue());
        self::assertSame('gymnase pierre de coubertin', $entry->getVenueFbiLabel(), 'aucune sœur n\'atteste la graphie brute → repli sur le premier alias');
    }

    public function testVenueFbiLabelServesTheRawSpellingASiblingAttests(): void
    {
        // Le registre « à corriger dans FBI » rend la GRAPHIE BRUTE que la source
        // atteste pour ce gymnase — pas le premier alias, stocké NORMALISÉ (minuscules
        // sans accents), illisible à recopier dans un écran fédéral. Une rencontre
        // SŒUR (même saison, même gymnase) dont le libellé normalisé est un alias
        // confirmé en porte la graphie d'origine.
        $venueId = $this->createVenueWithAliases('Gymnase Coubertin', ['gymnase pierre de coubertin']);
        // La sœur, rattachée par cet alias, atteste sa graphie brute « Gymnase Pierre de Coubertin ».
        $this->importMapped([['D2', 'SIB', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase Pierre de Coubertin']]);
        self::assertSame($venueId, $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'SIB'])?->getVenueId());

        // La rencontre arbitrée est placée dans le MÊME gymnase, mais sous le nom Amateo.
        $this->importMapped([['D2', 'PB1', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase Coubertin']]);
        $this->placeAt('PB1', $venueId);
        $id = $this->fixtureId('PB1');

        // « Garder l'appli » sur un écart salle ouvre l'entrée : elle sert la graphie brute.
        $this->importMapped(
            [['D2', 'PB1', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']],
            null,
            [['fixtureId' => $id, 'field' => 'venue', 'choice' => 'keep_app']],
        );
        $this->em->clear();

        $entry = $this->em->getRepository(FbiCorrection::class)->findOneBy(['fixtureId' => $id, 'field' => FbiCorrectionField::VENUE]);
        self::assertInstanceOf(FbiCorrection::class, $entry);
        self::assertSame('Gymnase Pierre de Coubertin', $entry->getVenueFbiLabel(), 'la graphie BRUTE attestée par la sœur, pas l\'alias normalisé');
    }

    public function testTakeFileNeverOpensAnFbiCorrectionEntry(): void
    {
        // « Prendre le fichier » aligne l'appli sur FBI : rien à reporter dans FBI,
        // donc AUCUNE entrée « à corriger » n'est ouverte.
        $this->importMapped([['D2', 'RK03', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RK03');
        $id = $this->fixtureId('RK03');

        $this->importMapped(
            [['D2', 'RK03', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']],
            null,
            [['fixtureId' => $id, 'field' => 'date', 'choice' => 'take_file']],
        );
        $this->em->clear();

        self::assertCount(0, $this->em->getRepository(FbiCorrection::class)->findBy(['fixtureId' => $id]));
    }

    public function testKickoffTakeFileOnSubmittedPostsFbiEchoClearedOnResubmit(): void
    {
        // « Prendre le fichier » sur l'HEURE d'un SUBMITTED : rétrogradé à PLACED (la
        // coche FBI portait une mauvaise heure) ET un mémo fbiEcho « FBI affiche 17:00 »
        // pour la ligne « à saisir ». Re-passer SUBMITTED efface le mémo.
        $this->importMapped([['D2', 'RK04', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RK04');
        $this->setStatus('RK04', FixtureStatus::SUBMITTED);
        $id = $this->fixtureId('RK04');

        $this->importMapped(
            [['D2', 'RK04', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', '']],
            null,
            [['fixtureId' => $id, 'field' => 'kickoff', 'choice' => 'take_file']],
        );
        $this->em->clear();

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RK04']);
        self::assertSame(FixtureStatus::PLACED, $fixture?->getStatus());
        $echo = $fixture?->getFbiEcho();
        self::assertIsArray($echo);
        self::assertSame('kickoff', $echo['field']);
        self::assertSame('17:00', $echo['value']);
        self::assertArrayHasKey('at', $echo);
        // take_file n'ouvre jamais d'entrée « à corriger » (l'appli s'aligne sur FBI).
        self::assertCount(0, $this->em->getRepository(FbiCorrection::class)->findBy(['fixtureId' => $id]));

        // Re-saisi dans FBI → SUBMITTED → le mémo n'a plus d'objet, il est effacé.
        $this->setStatus('RK04', FixtureStatus::SUBMITTED);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RK04'])?->getFbiEcho());
    }

    /**
     * Invariant d'appel du ledger (index partiel unique) : une rencontre est traitée UNE
     * fois par dépôt (garde $seenInFile sur team|ref) → AU PLUS un `open()` par (rencontre,
     * champ). Une ligne DUPLIQUÉE dans le même fichier n'ouvre donc qu'UNE entrée, sans
     * violer l'index `WHERE closed_at IS NULL` (deux INSERT non flushés ne se voient pas).
     */
    public function testADuplicateRowInOneDepositOpensOnlyOneCorrectionEntry(): void
    {
        $this->importMapped([['D2', 'RDUP', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RDUP');
        $id = $this->fixtureId('RDUP');

        $this->importMapped(
            [
                ['D2', 'RDUP', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', ''],
                ['D2', 'RDUP', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', ''],
            ],
            null,
            [['fixtureId' => $id, 'field' => 'kickoff', 'choice' => 'keep_app']],
        );
        $this->em->clear();

        self::assertCount(1, $this->em->getRepository(FbiCorrection::class)->findBy(['fixtureId' => $id]));
    }

    // ── Fermeture / idempotence du registre (dépôt) ─────────────────────────

    /** (a) Un re-dépôt de la MÊME valeur FBI ne re-crée pas d'écart — juste « vu dans FBI ». */
    public function testReDepositingTheSameDivergenceRefreshesSeenWithoutRecreatingAnEcart(): void
    {
        $this->pinClock(new DateTimeImmutable('2026-09-01 10:00:00'));
        $this->importMapped([['D2', 'RF01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RF01');
        $id = $this->fixtureId('RF01');
        $this->importMapped(
            [['D2', 'RF01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', '']],
            null,
            [['fixtureId' => $id, 'field' => 'kickoff', 'choice' => 'keep_app']],
        );

        // Re-dépôt du MÊME fichier divergent, sans décision : FBI affiche toujours 17:00.
        $this->pinClock(new DateTimeImmutable('2026-09-08 10:00:00'));
        $result = $this->importMapped([['D2', 'RF01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', '']]);
        self::assertSame([], $result['unresolvedDeviations'], 'aucun écart re-créé — le gestionnaire a déjà tranché');
        $this->em->clear();

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RF01']);
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState(), 'pas de retour « à traiter »');
        self::assertSame([], $fixture?->getPendingDeviations());
        $entry = $this->em->getRepository(FbiCorrection::class)->findOneBy(['fixtureId' => $id, 'field' => FbiCorrectionField::KICKOFF]);
        self::assertInstanceOf(FbiCorrection::class, $entry);
        self::assertTrue($entry->isOpen(), 'l\'entrée reste ouverte — FBI n\'a pas été corrigé');
        self::assertSame('2026-09-08', $entry->getLastSeenInFbiAt()?->format('Y-m-d'), '« vu dans FBI » re-daté');
    }

    /** (b) FBI corrigé (le fichier montre de nouveau la valeur de l'appli) → entrée fermée par le dépôt. */
    public function testFbiCorrectedClosesTheEntryByDeposit(): void
    {
        $this->importMapped([['D2', 'RF02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RF02');
        $id = $this->fixtureId('RF02');
        $this->importMapped(
            [['D2', 'RF02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', '']],
            null,
            [['fixtureId' => $id, 'field' => 'kickoff', 'choice' => 'keep_app']],
        );

        // FBI est corrigé : le fichier montre de nouveau 15:30 (la valeur de l'appli).
        $this->importMapped([['D2', 'RF02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->em->clear();

        $entry = $this->em->getRepository(FbiCorrection::class)->findOneBy(['fixtureId' => $id, 'field' => FbiCorrectionField::KICKOFF]);
        self::assertInstanceOf(FbiCorrection::class, $entry);
        self::assertFalse($entry->isOpen(), 'FBI reflète l\'appli → l\'entrée est faite');
        self::assertSame(FbiCorrectionCloseSource::DEPOSIT, $entry->getClosedBy());
    }

    /** (c) FBI affiche une TROISIÈME valeur → ancienne entrée fermée (dépôt) ET un écart normal à arbitrer. */
    public function testAThirdValueClosesTheOldEntryAndOpensANormalEcart(): void
    {
        $this->importMapped([['D2', 'RF03', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RF03');
        $id = $this->fixtureId('RF03');
        $this->importMapped(
            [['D2', 'RF03', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', '']],
            null,
            [['fixtureId' => $id, 'field' => 'kickoff', 'choice' => 'keep_app']],
        );

        // FBI affiche maintenant 18:00 (ni l'appli 15:30 ni le 17:00 mémorisé).
        $result = $this->importMapped([['D2', 'RF03', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '18:00', '']]);
        self::assertCount(1, $result['unresolvedDeviations'], 'un écart normal à arbitrer');
        $this->em->clear();

        $entry = $this->em->getRepository(FbiCorrection::class)->findOneBy(['fixtureId' => $id, 'field' => FbiCorrectionField::KICKOFF]);
        self::assertInstanceOf(FbiCorrection::class, $entry);
        self::assertFalse($entry->isOpen(), 'la correction devient caduque (3ᵉ valeur)');
        self::assertSame(FbiCorrectionCloseSource::DEPOSIT, $entry->getClosedBy());
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RF03']);
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $fixture?->getReviewState());
    }

    /** (d) Fenêtre imminente (« FBI fait foi ») → l'appli s'aligne d'office, l'entrée se ferme (dépôt). */
    public function testImminentWindowClosesTheEntryByDeposit(): void
    {
        $this->pinClock(new DateTimeImmutable('2026-09-01 10:00:00'));
        $this->importMapped([['D2', 'RF04', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RF04');
        $id = $this->fixtureId('RF04');
        $this->importMapped(
            [['D2', 'RF04', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', '']],
            null,
            [['fixtureId' => $id, 'field' => 'kickoff', 'choice' => 'keep_app']],
        );

        // La date de match entre dans la semaine ISO en cours → source d'office.
        $this->pinClock(new DateTimeImmutable('2026-10-01 10:00:00'));
        $this->importMapped([['D2', 'RF04', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '18:00', '']]);
        $this->em->clear();

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RF04']);
        self::assertSame('18:00', $fixture?->getKickoffTime()?->format('H:i'), 'la source a fait foi');
        $entry = $this->em->getRepository(FbiCorrection::class)->findOneBy(['fixtureId' => $id, 'field' => FbiCorrectionField::KICKOFF]);
        self::assertInstanceOf(FbiCorrection::class, $entry);
        self::assertFalse($entry->isOpen(), 'la source imminente ferme la correction');
        self::assertSame(FbiCorrectionCloseSource::DEPOSIT, $entry->getClosedBy());
    }

    public function testVenueContainmentIsNoDeviationADifferentRoomIs(): void
    {
        $venueId = $this->createVenue('GYMNASE PIERRE DE COUBERTIN');
        $this->importMapped([['D2', 'RV01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE PIERRE DE COUBERTIN']]);
        $this->placeAt('RV01', $venueId);

        // Whole-word containment (« Coubertin » ⊂ the placed venue): no deviation.
        $contained = $this->importMapped([['D2', 'RV01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Coubertin']]);
        self::assertSame([], $contained['unresolvedDeviations']);

        // A genuinely different room: a venue deviation.
        $different = $this->importMapped([['D2', 'RV01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']]);
        self::assertCount(1, $different['unresolvedDeviations']);
        self::assertSame(
            ['app' => 'GYMNASE PIERRE DE COUBERTIN', 'file' => 'GYMNASE MATEO'],
            $different['unresolvedDeviations'][0]['fields']['venue'],
        );
    }

    public function testPlacedHomeNamedByAConfirmedAliasIsNoDeviationAndGetsValidated(): void
    {
        // Le FAUX écart qui revenait à chaque dépôt : la source nomme le gymnase PLACÉ
        // par un alias CONFIRMÉ (pas le nom Amateo, et sans recouvrement de mot). Le
        // chemin placé porte désormais la même clause alias que le non placé → plus
        // d'écart ; et comme la source atteste date + heure + salle, la réconciliation
        // passe enfin la rencontre en VALIDATED (D9 — l'attestation qui fonctionne).
        $venueId = $this->createVenueWithAliases('GYMNASE COUBERTIN', ['salle du 8 mai']);
        $this->importMapped([['D2', 'PA1', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Salle du 8 Mai']]);
        $this->placeAt('PA1', $venueId); // placé dans le gymnase que l'alias désigne

        $result = $this->importMapped([['D2', 'PA1', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Salle du 8 Mai']]);

        self::assertSame([], $result['unresolvedDeviations'], 'l\'alias confirmé du gymnase placé n\'est pas un écart');
        $this->em->clear();
        self::assertSame(
            FixtureStatus::VALIDATED,
            $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'PA1'])?->getStatus(),
            'attestée sur les trois champs (salle par alias) → validée',
        );
    }

    public function testPlacedHomeWhoseFileAliasPointsAnotherVenueStillRaisesTheDeviation(): void
    {
        // La clause alias reste STRICTE sur l'identité : un alias qui désigne un AUTRE
        // gymnase que celui où la rencontre est placée lève toujours l'écart (jamais
        // validée sur la foi d'une salle qui n'est pas celle du placement).
        $jdr = $this->createVenue('GYMNASE JDR');
        $this->createVenueWithAliases('Debarros', ['salle raphael de barros']);
        $this->importMapped([['D2', 'PA2', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE JDR']]);
        $this->placeAt('PA2', $jdr); // placé dans JDR

        // La source nomme la salle d'un AUTRE gymnase (alias confirmé de Debarros).
        $result = $this->importMapped([['D2', 'PA2', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE RAPHAEL DE BARROS']]);

        self::assertCount(1, $result['unresolvedDeviations']);
        self::assertSame(
            ['app' => 'GYMNASE JDR', 'file' => 'SALLE RAPHAEL DE BARROS'],
            $result['unresolvedDeviations'][0]['fields']['venue'],
        );
        $this->em->clear();
        self::assertSame(
            FixtureStatus::PLACED,
            $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'PA2'])?->getStatus(),
            'un alias vers un autre gymnase n\'atteste pas → jamais validée',
        );
    }

    public function testTheZeroZeroSentinelIsNeverADeviation(): void
    {
        $this->importMapped([['D2', 'RZ01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RZ01');

        $result = $this->importMapped([['D2', 'RZ01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '00:00', '']]);

        self::assertSame([], $result['unresolvedDeviations']);
        self::assertSame(1, $result['unchanged']);
        self::assertSame('15:30', $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RZ01'])?->getKickoffTime()?->format('H:i'));
    }

    public function testFixturePlacedAfterAnalyzeSurfacesAtImportWithoutDecision(): void
    {
        // The race the founder named: at analyze the home fixture was UNPLACED
        // (no deviation). Placed BETWEEN analyze and import → the recomputed diff
        // at import makes it a deviation; without a decision it stays intact.
        $this->importMapped([['D2', 'RC01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);

        $analysis = $this->importer->analyze($this->xlsx([['D2', 'RC01', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']]), $this->club);
        self::assertSame([], $analysis['deviations']); // UNPLACED at analyze → not in perimeter

        $this->place('RC01'); // the manager places it after analyzing

        $result = $this->importMapped([['D2', 'RC01', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']]);
        self::assertCount(1, $result['unresolvedDeviations']);
        self::assertSame('2026-10-03', $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RC01'])?->getMatchDate()->format('Y-m-d'));

        // Idempotence: re-deposit → the écart is presented again (still unresolved).
        $again = $this->importMapped([['D2', 'RC01', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']]);
        self::assertCount(1, $again['unresolvedDeviations']);
    }

    // ── Review workflow (PR-3a) ─────────────────────────────────────────────

    public function testAnImportedFixtureIsNewUntilTreated(): void
    {
        $this->importMapped([['D2', 'RN01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RN01']);
        self::assertSame(FixtureReviewState::NEW, $fixture?->getReviewState());
        self::assertNull($fixture?->getReviewedAt());
        self::assertSame([], $fixture?->getPendingDeviations());
    }

    public function testAttestedPlacedHomeIsValidatedD9(): void
    {
        $venueId = $this->createVenue('GYMNASE MATEO');
        $this->importMapped([['D2', 'RV9', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']]);
        $this->placeAt('RV9', $venueId); // PLACED + REVIEWED

        // Same file re-deposited: date AND kickoff AND salle attested → VALIDATED.
        $result = $this->importMapped([['D2', 'RV9', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']]);
        self::assertSame([], $result['unresolvedDeviations']);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RV9']);
        self::assertSame(FixtureStatus::VALIDATED, $fixture?->getStatus());
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
    }

    public function testAttestedSubmittedHomeIsValidatedD9(): void
    {
        $venueId = $this->createVenue('GYMNASE MATEO');
        $this->importMapped([['D2', 'RV8', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']]);
        $this->placeAt('RV8', $venueId);
        $this->setStatus('RV8', FixtureStatus::SUBMITTED);

        $this->importMapped([['D2', 'RV8', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']]);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RV8']);
        self::assertSame(FixtureStatus::VALIDATED, $fixture?->getStatus());
    }

    public function testNoD9WithoutARealKickoffOrWithoutASalle(): void
    {
        $venueId = $this->createVenue('GYMNASE MATEO');
        // No kickoff attested (00:00 sentinel) → no D9.
        $this->importMapped([['D2', 'RV7', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']]);
        $this->placeAt('RV7', $venueId);
        $this->importMapped([['D2', 'RV7', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '00:00', 'GYMNASE MATEO']]);
        $this->em->clear();
        self::assertSame(FixtureStatus::PLACED, $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RV7'])?->getStatus());

        // No salle attested (empty « Salle ») → no D9.
        $this->importMapped([['D2', 'RV6', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']]);
        $this->placeAt('RV6', $venueId);
        $this->importMapped([['D2', 'RV6', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->em->clear();
        self::assertSame(FixtureStatus::PLACED, $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RV6'])?->getStatus());
    }

    public function testAnAwayEcartIsTakenAsAcknowledgedStaysReviewedNeverOutOfSync(): void
    {
        // P4-199 décision 2 — un EXTÉRIEUR naît traité (REVIEWED) ; un écart ultérieur
        // applique la source d'office, laisse une entrée `autoApplied` (le bandeau
        // « la source a déplacé ce match ») mais RESTE traité — jamais OUT_OF_SYNC :
        // le club ne place pas un extérieur, il n'y a rien à ré-arbitrer.
        $this->importMapped([['D2', 'RA9', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $away = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RA9']);
        self::assertSame(FixtureHomeAway::AWAY, $away?->getHomeAway());
        self::assertSame(FixtureReviewState::REVIEWED, $away?->getReviewState(), 'un extérieur naît déjà traité');

        $result = $this->importMapped([['D2', 'RA9', 'AS Voisins', 'BC TESTVILLE - 1', '10/10/2026', '15:30', 'Salle Adverse']]);
        self::assertSame(1, $result['updated']);
        self::assertSame([], $result['unresolvedDeviations'], 'un extérieur ne produit jamais d\'écart à arbitrer');
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RA9']);
        self::assertSame('2026-10-10', $fixture?->getMatchDate()->format('Y-m-d'), 'la source est appliquée d\'office');
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState(), 'reste traité — jamais OUT_OF_SYNC');
        $entry = $fixture?->getPendingDeviation('date');
        self::assertNotNull($entry);
        self::assertTrue($entry['autoApplied'], 'l\'entrée autoApplied allume le bandeau « pris en compte »');
        self::assertSame('2026-10-10', $entry['sourceValue']);
    }

    public function testAutoAppliedChangeOnANewUnplacedHomeStaysNew(): void
    {
        // Never treated (a HOME still UNPLACED and future = NEW) → a reschedule out of
        // the perimeter stays NEW, no pending entry (an AWAY can no longer be NEW —
        // it is born REVIEWED, P4-199 décision 1).
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // mercredi
        $this->importMapped([['D2', 'RH8', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $born = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RH8']);
        self::assertSame(FixtureReviewState::NEW, $born?->getReviewState(), 'un domicile futur hors semaine naît NEW');

        $this->importMapped([['D2', 'RH8', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']]);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RH8']);
        self::assertSame(FixtureReviewState::NEW, $fixture?->getReviewState());
        self::assertSame([], $fixture?->getPendingDeviations());
        self::assertSame('2026-10-10', $fixture?->getMatchDate()->format('Y-m-d'), 'hors périmètre, la source gagne directement');
    }

    // ── P4-199 : naissance « traitée » selon l'extérieur / la fenêtre temporelle ──

    public function testAnAwayFixtureIsBornTreated(): void
    {
        // Décision 1 — un extérieur naît REVIEWED + horodaté (date future indifférente).
        $this->importMapped([['D2', 'RX1', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RX1']);
        self::assertSame(FixtureHomeAway::AWAY, $fixture?->getHomeAway());
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
        self::assertNotNull($fixture?->getReviewedAt());
    }

    public function testAPastHomeFixtureIsBornTreated(): void
    {
        // Décision 1 — une rencontre PASSÉE (avant la semaine ISO en cours) naît traitée.
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // mercredi, semaine 14→20 sept.
        $this->importMapped([['D2', 'RP1', 'BC TESTVILLE - 1', 'AS Voisins', '12/09/2026', '15:30', '']]); // samedi passé
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RP1']);
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
        self::assertNotNull($fixture?->getReviewedAt());
    }

    public function testAHomeFixtureInTheCurrentIsoWeekIsBornTreated(): void
    {
        // Décision 1 — un match du samedi de la semaine ISO en cours (≤ dimanche) naît traité.
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // mercredi ; dimanche = 20 sept.
        $this->importMapped([['D2', 'RW1', 'BC TESTVILLE - 1', 'AS Voisins', '19/09/2026', '15:30', '']]); // samedi de cette semaine
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RW1']);
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
        self::assertNotNull($fixture?->getReviewedAt());
    }

    public function testAFutureHomeFixtureOutsideTheWeekStaysNew(): void
    {
        // Décision 1 — un domicile futur HORS de la semaine ISO reste à traiter (NEW).
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // dimanche = 20 sept.
        $this->importMapped([['D2', 'RF1', 'BC TESTVILLE - 1', 'AS Voisins', '26/09/2026', '15:30', '']]); // samedi suivant
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RF1']);
        self::assertSame(FixtureReviewState::NEW, $fixture?->getReviewState());
        self::assertNull($fixture?->getReviewedAt());
    }

    // ── D2 : rattrapage d'un existant resté « à traiter » au re-dépôt ───────

    public function testAnExistingNewAwayIsCaughtUpOnReDeposit(): void
    {
        // Résidu d'un dépôt antérieur à la naissance-traitée : un extérieur resté
        // NEW. Un re-dépôt identique le rattrape (REVIEWED + horodaté).
        $this->importMapped([['D2', 'CU1', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $this->forceReviewState('CU1', FixtureReviewState::NEW);

        $this->importMapped([['D2', 'CU1', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'CU1']);
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
        self::assertNotNull($fixture?->getReviewedAt());
    }

    public function testAnExistingNewPastHomeIsCaughtUpOnReDeposit(): void
    {
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // mercredi ; dimanche = 20 sept.
        $this->importMapped([['D2', 'CU2', 'BC TESTVILLE - 1', 'AS Voisins', '12/09/2026', '15:30', '']]); // samedi passé
        $this->forceReviewState('CU2', FixtureReviewState::NEW);

        $this->importMapped([['D2', 'CU2', 'BC TESTVILLE - 1', 'AS Voisins', '12/09/2026', '15:30', '']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'CU2']);
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
        self::assertNotNull($fixture?->getReviewedAt());
    }

    public function testAnExistingNewHomeInTheCurrentWeekIsCaughtUpOnReDeposit(): void
    {
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // dimanche = 20 sept.
        $this->importMapped([['D2', 'CU3', 'BC TESTVILLE - 1', 'AS Voisins', '19/09/2026', '15:30', '']]); // samedi de cette semaine
        $this->forceReviewState('CU3', FixtureReviewState::NEW);

        $this->importMapped([['D2', 'CU3', 'BC TESTVILLE - 1', 'AS Voisins', '19/09/2026', '15:30', '']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'CU3']);
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
        self::assertNotNull($fixture?->getReviewedAt());
    }

    public function testAnExistingNewFutureHomeOutsideTheWeekStaysNewOnReDeposit(): void
    {
        // Le prédicat ne mord pas : un domicile futur hors semaine reste « à traiter ».
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // dimanche = 20 sept.
        $this->importMapped([['D2', 'CU4', 'BC TESTVILLE - 1', 'AS Voisins', '26/09/2026', '15:30', '']]); // samedi suivant
        // Il naît déjà NEW (hors fenêtre) ; un re-dépôt identique le laisse NEW.
        $this->importMapped([['D2', 'CU4', 'BC TESTVILLE - 1', 'AS Voisins', '26/09/2026', '15:30', '']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'CU4']);
        self::assertSame(FixtureReviewState::NEW, $fixture?->getReviewState());
        self::assertNull($fixture?->getReviewedAt());
    }

    public function testCatchUpNeverTouchesAnOutOfSyncFixture(): void
    {
        // Le rattrapage n'agit QUE sur NEW : un OUT_OF_SYNC (même un extérieur, que
        // le prédicat qualifierait) garde son état de traitement au re-dépôt.
        $this->importMapped([['D2', 'CU5', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $this->forceReviewState('CU5', FixtureReviewState::OUT_OF_SYNC);

        $this->importMapped([['D2', 'CU5', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'CU5']);
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $fixture?->getReviewState());
    }

    public function testCatchUpIsIdempotentOnASecondReDeposit(): void
    {
        // Le premier re-dépôt rattrape (horodate à T1) ; un second, horloge avancée,
        // ne re-marque pas — reviewedAt reste T1 (catchUpReview ne mord plus, non-NEW).
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00'));
        $this->importMapped([['D2', 'CU6', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $this->forceReviewState('CU6', FixtureReviewState::NEW);

        $this->pinClock(new DateTimeImmutable('2026-09-17 10:00:00'));
        $this->importMapped([['D2', 'CU6', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $this->em->clear();
        $firstStamp = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'CU6'])?->getReviewedAt();
        self::assertNotNull($firstStamp);

        $this->pinClock(new DateTimeImmutable('2026-09-25 10:00:00'));
        $this->importMapped([['D2', 'CU6', 'AS Voisins', 'BC TESTVILLE - 1', '03/10/2026', '15:30', 'Salle Adverse']]);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'CU6']);
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
        self::assertSame($firstStamp->format('Y-m-d'), $fixture?->getReviewedAt()?->format('Y-m-d'), 'jamais re-horodaté');
    }

    // ── P4-199 : « FBI fait foi » sur un domicile placé déphasé dans la fenêtre ──

    public function testPlacedHomeDeviationWithAPastAppDateIsAppliedAtOnceAndStaysTreated(): void
    {
        // Décision 3 (D3) — la date de la SOURCE (19/09) tombe dans la semaine ISO en cours
        // (14/09 → 20/09) : la source est appliquée D'OFFICE (dé-placée), une entrée autoApplied
        // allume le bandeau, la rencontre reste traitée (jamais un arbitrage OUT_OF_SYNC).
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // semaine ISO 14/09 → 20/09
        $this->importMapped([['D2', 'RW9', 'BC TESTVILLE - 1', 'AS Voisins', '12/09/2026', '15:30', '']]); // date app passée
        $this->place('RW9'); // PLACED + REVIEWED

        $result = $this->importMapped([['D2', 'RW9', 'BC TESTVILLE - 1', 'AS Voisins', '19/09/2026', '15:30', '']]);
        self::assertSame(1, $result['updated']);
        self::assertSame([], $result['unresolvedDeviations'], 'FBI fait foi : rien à arbitrer');
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RW9']);
        self::assertSame('2026-09-19', $fixture?->getMatchDate()->format('Y-m-d'), 'la source est écrite d\'office');
        self::assertSame(FixtureStatus::UNPLACED, $fixture?->getStatus(), 'le déplacement de date dé-place');
        self::assertNull($fixture?->getVenueId());
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
        $entry = $fixture?->getPendingDeviation('date');
        self::assertNotNull($entry);
        self::assertTrue($entry['autoApplied']);
        self::assertSame('2026-09-19', $entry['sourceValue']);
    }

    public function testPlacedHomeDeviationWhoseSourceFallsBeforeTheCurrentWeekStaysAnArbitration(): void
    {
        // D3 (M15) — RESTREINT à la semaine ISO en cours : une date de source PASSÉE mais
        // ANTÉRIEURE au lundi de la semaine courante ne fait PLUS foi d'office (régime d'avant :
        // tout « ≤ dimanche » passait). App 26/09 (future hors semaine) et source 12/09 (avant
        // le lundi 14/09) → les deux hors fenêtre → écart à trancher, valeur app GARDÉE.
        // Angle « borne basse » (sensible : matchs) de la fenêtre, jumeau de bout en bout du
        // témoin unitaire {@see FbiArrivalReviewWindowTest::testTheDayBeforeTheCurrentIsoMondayIsOutsideTheWindow} ;
        // distinct de {@see testPlacedHomeDeviationFullyOutsideTheWeekStaysAnArbitration} (deux dates futures).
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // semaine ISO 14/09 → 20/09
        $this->importMapped([['D2', 'RW8', 'BC TESTVILLE - 1', 'AS Voisins', '26/09/2026', '15:30', '']]); // date app future hors semaine
        $this->place('RW8');

        $result = $this->importMapped([['D2', 'RW8', 'BC TESTVILLE - 1', 'AS Voisins', '12/09/2026', '15:30', '']]); // source avant le lundi courant
        self::assertCount(1, $result['unresolvedDeviations'], 'source hors de la semaine ISO → écart à trancher');
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RW8']);
        self::assertSame('2026-09-26', $fixture?->getMatchDate()->format('Y-m-d'), 'valeur app gardée, jamais écrasée');
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $fixture?->getReviewState());
        self::assertFalse($fixture?->getPendingDeviation('date')['autoApplied'] ?? true, 'écart à arbitrer, pas auto-appliqué');
    }

    public function testPlacedHomeDeviationFullyOutsideTheWeekStaysAnArbitration(): void
    {
        // Décision 3 (borne) — les deux dates hors fenêtre : arbitrage inchangé
        // (OUT_OF_SYNC, valeur app gardée), le régime RMM-4 d'origine.
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // dimanche = 20 sept.
        $this->importMapped([['D2', 'RW7', 'BC TESTVILLE - 1', 'AS Voisins', '26/09/2026', '15:30', '']]);
        $this->place('RW7');

        $result = $this->importMapped([['D2', 'RW7', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        self::assertCount(1, $result['unresolvedDeviations']);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RW7']);
        self::assertSame('2026-09-26', $fixture?->getMatchDate()->format('Y-m-d'), 'valeur app gardée, jamais écrasée');
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $fixture?->getReviewState());
        self::assertFalse($fixture?->getPendingDeviation('date')['autoApplied'] ?? true, 'écart à arbitrer, pas auto-appliqué');
    }

    public function testPlacedHomeVenueDeviationTowardAnUnknownSalleInTheWindowKeepsItsVenueAndStaysAnEcart(): void
    {
        // Chantier A PR 4 — « FBI fait foi » applique date/heure d'OFFICE, mais une SALLE
        // INCONNUE (aucun alias confirmé) ne dé-place JAMAIS un domicile à venueId null,
        // même dans la fenêtre : le gymnase courant est conservé et l'écart salle reste à
        // arbitrer. Sans cela, un domicile imminent perdrait son gymnase en silence.
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00')); // dimanche = 20 sept.
        $coubertin = $this->createVenue('GYMNASE COUBERTIN');
        // Un domicile PLACÉ au gymnase Coubertin, à une date DANS la fenêtre (19 sept.).
        $fixture = $this->unplacedHomeWithVenue('RWV1', $coubertin, 'GYMNASE COUBERTIN');
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $fixture->setMatchDate(new DateTimeImmutable('2026-09-19'));
        $this->em->flush();

        // Re-dépôt : MÊME date (en fenêtre) et MÊME heure, mais une salle INCONNUE —
        // seule la salle diverge. Opt-out d'appariement : le libellé reste vraiment inconnu.
        $result = $this->importMapped([['D2', 'RWV1', 'BC TESTVILLE - 1', 'AS Voisins', '19/09/2026', '15:30', 'SALLE INCONNUE']], null, [], [], []);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RWV1']);
        self::assertSame($coubertin, $reloaded?->getVenueId(), 'le gymnase actuel est conservé — jamais venueId null');
        self::assertSame(FixtureStatus::PLACED, $reloaded?->getStatus(), 'le domicile n\'est pas dé-placé par une salle inconnue');
        self::assertCount(1, $result['unresolvedDeviations'], 'la salle inconnue reste un écart à arbitrer, jamais « FBI fait foi »');
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $reloaded?->getReviewState());
        $entry = $reloaded?->getPendingDeviation('venue');
        self::assertNotNull($entry, 'l\'écart de salle est ouvert');
        self::assertFalse($entry['autoApplied'] ?? true, 'la salle n\'est pas appliquée d\'office — c\'est un écart à trancher');
    }

    public function testAPlacedHomeWeeksOldReDatedFarAheadBecomesAnEcartNeverRewritten(): void
    {
        // D3 (M15) — le bug : un domicile PLACÉ de plusieurs semaines (10/10), que le dépôt
        // re-date loin devant (14/11), était réécrit D'OFFICE (« FBI fait foi ») parce que la
        // borne n'était qu'un plafond. Restreint à la semaine ISO en cours : AUCUNE des deux
        // dates n'y tombe → écart À TRANCHER, le placement est CONSERVÉ (jamais réécrit).
        // Jumeau de bout en bout du témoin unitaire {@see FbiArrivalReviewWindowTest} (sensible : matchs).
        $this->pinClock(new DateTimeImmutable('2026-10-28 10:00:00')); // semaine ISO 26/10 → 01/11
        $this->importMapped([['D2', '22', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X']]);
        $venueId = $this->place('22');

        $result = $this->importMapped([['D2', '22', 'BC TESTVILLE - 1', 'AS Voisins', '14/11/2026', '15:30', 'Gymnase X']]);

        self::assertCount(1, $result['unresolvedDeviations'], 'hors semaine ISO → écart à trancher, jamais « FBI fait foi »');
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => '22']);
        self::assertSame('2026-10-10', $fixture?->getMatchDate()->format('Y-m-d'), 'la date d\'origine est intacte — jamais re-datée d\'office');
        self::assertSame(FixtureStatus::PLACED, $fixture?->getStatus(), 'le placement est conservé');
        self::assertSame($venueId, $fixture?->getVenueId());
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $fixture?->getReviewState());
        self::assertFalse($fixture?->getPendingDeviation('date')['autoApplied'] ?? true, 'un écart à arbitrer, pas une application d\'office');
    }

    // ── P4-199 : retrait du suffixe FFBB « (n) » ────────────────────────────

    public function testTheFfbbTeamNumberSuffixIsStrippedFromTheStoredOpponent(): void
    {
        $this->importMapped([['D2', 'RS9', 'BC TESTVILLE - 1', 'AS Voisins (2)', '03/10/2026', '15:30', '']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RS9']);
        self::assertSame('AS Voisins', $fixture?->getOpponentLabel(), 'le suffixe « (2) » est retiré du libellé adverse stocké');
    }

    public function testAnalyzeReturnsTheClubLabelWithoutItsFfbbNumberSuffix(): void
    {
        // Le libellé de division renvoyé au dialog (fbiTeamLabel d'une division
        // multi-équipes) est nettoyé → la correspondance persistée l'est aussi.
        $this->createTeam('U15-2');
        $file = $this->xlsx([
            ['D2', 'A1', 'BC TESTVILLE - 1 (3)', 'AS X', '03/10/2026', '', ''],
            ['D2', 'A2', 'BC TESTVILLE - 2 (5)', 'AS Y', '03/10/2026', '', ''],
        ]);

        $analysis = $this->importer->analyze($file, $this->club);
        $labels = array_column($analysis['divisions'], 'fbiTeamLabel');
        sort($labels);
        self::assertSame(['BC TESTVILLE - 1', 'BC TESTVILLE - 2'], $labels, 'les suffixes « (3) »/« (5) » sont retirés du libellé renvoyé');
    }

    public function testEveryDepositWritesADatedIngestionAndOnlyXlsxIsTheLastDeposit(): void
    {
        $this->importMapped([['D2', 'RI01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        /** @var FbiIngestionRepository $repository */
        $repository = self::getContainer()->get(FbiIngestionRepository::class);
        $xlsx = $repository->latestXlsx();
        self::assertNotNull($xlsx);
        self::assertSame(FbiIngestionSource::FBI_XLSX, $xlsx->getSource());
        self::assertSame(1, $xlsx->getCreated());

        // A (future) API ingestion must NOT count as the last deposit and never
        // kills/reports a trace — the repository only ever returns the xlsx one.
        $api = new FbiIngestion($this->club->getId(), $this->team->getSeasonId(), FbiIngestionSource::FFBB_API, new DateTimeImmutable('+1 hour'), 0, 0, 0, 0);
        $this->em->persist($api);
        $this->em->flush();

        $stillXlsx = $repository->latestXlsx();
        self::assertSame(FbiIngestionSource::FBI_XLSX, $stillXlsx?->getSource());
    }

    // ── Row-level guards (PR-4 successors) ─────────────────────────────────

    public function testExemptIsCountedNotAnError(): void
    {
        $result = $this->importMapped([
            ['D2', 'R4001', 'BC TESTVILLE - 1', 'Exempt', '03/10/2026', '', ''],
            ['D2', 'R4002', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '', ''],
        ]);

        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['exempted']);
        self::assertSame([], $result['errors']);
    }

    public function testUnrecognizedClubIsARowErrorAndValidRowsStillImport(): void
    {
        $result = $this->importMapped([
            ['D2', 'R5001', 'AS Ailleurs', 'US Autrepart', '03/10/2026', '', ''], // club absent
            ['D2', 'R5002', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '', ''],
        ]);

        self::assertSame(1, $result['created']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('aucune équipe ne correspond', $result['errors'][0]);
    }

    public function testIntraClubDerbyIsARowError(): void
    {
        $result = $this->importMapped([
            ['D2', 'R5003', 'BC TESTVILLE - 1', 'BC TESTVILLE - 2', '03/10/2026', '', ''],
        ]);

        self::assertSame(0, $result['created']);
        self::assertStringContainsString('derby intra-club', $result['errors'][0]);
    }

    public function testInvalidAndCalendarInvalidDatesAreRowErrors(): void
    {
        $result = $this->importMapped([
            ['D2', 'R6001', 'BC TESTVILLE - 1', 'AS Voisins', 'pas-une-date', '', ''],
            ['D2', 'R6002', 'BC TESTVILLE - 1', 'AS Voisins', '31/02/2026', '', ''], // no rollover
        ]);

        self::assertSame(0, $result['created']);
        self::assertCount(2, $result['errors']);
    }

    public function testUnpaddedDateAndTimeAreAccepted(): void
    {
        $result = $this->importMapped([
            ['D2', 'R6003', 'BC TESTVILLE - 1', 'AS Voisins', '3/10/2026', '9:30', ''],
        ]);

        self::assertSame(1, $result['created']);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'R6003']);
        self::assertSame('2026-10-03', $fixture?->getMatchDate()->format('Y-m-d'));
        self::assertSame('09:30', $fixture?->getKickoffTime()?->format('H:i'));
    }

    public function testClubNameAsPrefixOfOpponentIsNotADerby(): void
    {
        // Word-boundary match: "BC Testville" must not match "BC TESTVILLENORD".
        $result = $this->importMapped([
            ['D2', 'R6004', 'BC TESTVILLE - 1', 'AS BC TESTVILLENORD', '03/10/2026', '', ''],
        ]);

        self::assertSame(1, $result['created']);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'R6004']);
        self::assertSame(FixtureHomeAway::HOME, $fixture?->getHomeAway());
    }

    public function testOverlongMatchNumberIsARowError(): void
    {
        $result = $this->importMapped([
            ['D2', str_repeat('X', 65), 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '', ''],
        ]);

        self::assertSame(0, $result['created']);
        self::assertStringContainsString('numéro de rencontre trop long', $result['errors'][0]);
    }

    public function testLegacyNumeroHeaderIsStillAccepted(): void
    {
        // PR-4 files titled « Numéro » — both header generations parse.
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Division', 'Numéro', 'Équipe 1', 'Équipe 2', 'Date de rencontre', 'Heure', 'Salle'],
            ['D2', 'R7001', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '', ''],
        ], null, 'A1');

        // Chantier A PR 2 — « pas de salles appariées, pas d'import » : le domicile sans salle
        // de D2 prend un gymnase via venueChoices (en-tête legacy → fichier custom, hors helper
        // importMapped qui écrit l'en-tête courant).
        $venueId = $this->createVenue('Gymnase Legacy');
        $result = $this->importer->import($this->write($spreadsheet), $this->club, [
            ['division' => 'D2', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId()],
        ], [], null, [], [['division' => 'D2', 'venueId' => $venueId]]);

        self::assertSame(1, $result['created']);
    }

    public function testNonXlsxContentIsRejectedByThePinnedReader(): void
    {
        // Arbitrary text renamed .xlsx must never fall back to the Csv/Html
        // readers (reader pinned to Xlsx — security-review PR-4).
        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.xlsx';
        file_put_contents($path, "Division;N° de match\nD2;R1");
        $this->tempFiles[] = $path;

        $this->expectException(Throwable::class);
        $this->importer->import($path, $this->club, []);
    }

    public function testMissingRequiredColumnsRejectTheFile(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['Division', 'N° de match ', 'Equipe 1']], null, 'A1');

        // Type dédié depuis P4-5 : SEUL `ImportRejectedException` voit son message
        // relayé à l'utilisateur ; une exception nue serait masquée par le contrôleur.
        $this->expectException(ImportRejectedException::class);
        $this->importer->analyze($this->write($spreadsheet), $this->club);
    }

    public function testAWrongMappingRefusedByTheGuardIsNotPersisted(): void
    {
        // Revue F2 round 1 : le garde-fou PRÉCÈDE l'écriture — un mapping dont
        // la division est refusée ne colle pas (le dialog n'a pas de re-mapping).
        $paired = $this->pairedCompetition('RM2', ['AS VOISINS'], 14, canonicalName: 'Pré régionale masculine');
        $file = $this->xlsx([
            ['PRE REGIONALE MASCULINE', 'X1', 'BC TESTVILLE - 1', 'US INCONNU', '03/10/2026', '', ''],
        ]);

        $result = $this->importer->import($file, $this->club, [
            ['division' => 'PRE REGIONALE MASCULINE', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId(), 'competitionId' => $paired->getId()],
        ]);

        self::assertSame(0, $result['created']);
        self::assertCount(1, array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'hors de la poule')));
        self::assertSame([], $result['unmappedDivisions'], 'une division refusée n\'est pas re-signalée « à mapper »');
        $this->em->clear();
        $reloaded = $this->em->getRepository(Competition::class)->findOneBy(['id' => $paired->getId()]);
        self::assertSame('RM2', $reloaded?->getName(), 'le mapping refusé n\'a pas renommé la compétition appariée');
        self::assertCount(1, $this->em->getRepository(Competition::class)->findBy(['teamId' => $this->team->getId()]), 'et n\'a rien créé');
    }

    public function testAcceptedSuggestionReusesThePairedCompetition(): void
    {
        // Revue F2 round 1 : la suggestion voyage AVEC son competitionId — la
        // compétition appariée est RÉUTILISÉE (renommée vers le libellé FBI,
        // réfs/attendus/poule conservés), jamais dupliquée.
        $paired = $this->pairedCompetition('RM2', ['AS VOISINS'], 14, canonicalName: 'Pré régionale masculine');

        $result = $this->importMapped([
            ['PRE REGIONALE MASCULINE', 'X1', 'BC TESTVILLE - 1', 'AS VOISINS', '03/10/2026', '', ''],
        ], [
            ['division' => 'PRE REGIONALE MASCULINE', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId(), 'competitionId' => $paired->getId()],
        ]);

        self::assertSame(1, $result['created']);
        $competitions = $this->em->getRepository(Competition::class)->findBy(['teamId' => $this->team->getId()]);
        self::assertCount(1, $competitions, 'pas de doublon non apparié');
        self::assertSame('PRE REGIONALE MASCULINE', $competitions[0]->getName(), 'le libellé FBI devient la clé du résolveur');
        self::assertSame('Pré régionale masculine', $competitions[0]->getFfbbCompetitionName(), 'le nom canonique reste');
        self::assertSame(14, $competitions[0]->getExpectedMatchdays(), 'l\'appariement est conservé');
        self::assertSame($competitions[0]->getId(), $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'X1'])?->getCompetitionId());
    }

    public function testTwoMappingsToTheSameTeamAndDivisionInOneBatchCreateOneCompetition(): void
    {
        // Revue F2 round 1 : le dedupe DB ne voit pas les frères non flushés —
        // garde en mémoire dans le lot.
        $this->importMapped([
            ['D2', 'A1', 'BC TESTVILLE - 1', 'AS X', '03/10/2026', '', ''],
            ['D2', 'A2', 'BC TESTVILLE - 2', 'AS Y', '03/10/2026', '', ''],
        ], [
            ['division' => 'D2', 'fbiTeamLabel' => 'BC TESTVILLE - 1', 'teamId' => $this->team->getId()],
            ['division' => 'D2', 'fbiTeamLabel' => 'BC TESTVILLE - 2', 'teamId' => $this->team->getId()],
        ]);

        self::assertCount(1, $this->em->getRepository(Competition::class)->findBy(['teamId' => $this->team->getId(), 'name' => 'D2']));
    }

    public function testNoSuggestionForAMultiLabelDivision(): void
    {
        // Revue F2 round 1 : le nom canonique ne sait pas dire LAQUELLE des deux
        // équipes du club — aucune suggestion aveugle.
        $this->pairedCompetition('RM2', ['AS VOISINS'], 14, canonicalName: 'D2');
        $file = $this->xlsx([
            ['D2', 'A1', 'BC TESTVILLE - 1', 'AS X', '03/10/2026', '', ''],
            ['D2', 'A2', 'BC TESTVILLE - 2', 'AS Y', '03/10/2026', '', ''],
        ]);

        $analysis = $this->importer->analyze($file, $this->club);

        foreach ($analysis['divisions'] as $division) {
            self::assertNull($division['suggestedTeamId']);
        }
    }

    // ── P4-187a : rattachement du gymnase depuis l'alias ────────────────────

    public function testHomeImportWithAConfirmedAliasIsBornWithItsVenueButStaysUnplaced(): void
    {
        $venueId = $this->createVenueWithAliases('Gymnase Matéo', ['gymnase mateo']);

        $this->importMapped([['D2', 'AL1', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']]);

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'AL1']);
        self::assertNotNull($fixture);
        self::assertSame($venueId, $fixture->getVenueId(), 'le gymnase est rattaché depuis l\'alias confirmé');
        // …mais on ne place JAMAIS d'office : la rencontre reste à traiter et à placer.
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus());
        self::assertSame(FixtureReviewState::NEW, $fixture->getReviewState());
    }

    public function testHomeImportWithoutAnyVenueCoverageIsRefused(): void
    {
        // Chantier A PR 2 — « pas de salle appariée, pas d'import » : un domicile dont la
        // salle n'est appariée à AUCUN gymnase (ni alias confirmé, ni venueMapping) fait
        // REFUSER tout le dépôt. L'ancienne promesse « naît sans gymnase » est devenue
        // impossible (le domicile venueless ne survit que par terrain neutre).
        $this->createVenueWithAliases('Gymnase Matéo', ['gymnase mateo']);

        $this->expectException(ImportRejectedException::class);
        $this->importMapped(
            [['D2', 'AL2', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE INCONNUE']],
            venueMappings: [],
            venueChoices: [],
        );
    }

    public function testAppairingAtDepositBornWithVenueThenASecondHomeIsAttachedByTheAlias(): void
    {
        // Nouveau monde — on apparie la salle AU DÉPÔT (venueMapping) : le domicile naît
        // AVEC son gymnase (UNPLACED). L'appariement pose l'alias, si bien qu'un dépôt
        // suivant d'un AUTRE match au même libellé est rattaché d'office, sans ré-apparier.
        $venue = $this->createVenue('Gymnase Matéo');

        $this->importMapped(
            [['D2', 'AL3', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']],
            venueMappings: [['label' => 'GYMNASE MATEO', 'venueId' => $venue]],
            venueChoices: [],
        );
        $first = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'AL3']);
        self::assertSame($venue, $first?->getVenueId(), 'le domicile naît avec le gymnase apparié');
        self::assertSame(FixtureStatus::UNPLACED, $first?->getStatus(), 'apparier ne place jamais');

        // Un AUTRE match au même libellé, sans nouvel appariement (l'alias est confirmé).
        $this->importMapped(
            [['D2', 'AL3b', 'BC TESTVILLE - 1', 'AS Autre', '10/10/2026', '15:30', 'GYMNASE MATEO']],
            null,
            [],
            [],
            [],
        );
        self::assertSame($venue, $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'AL3b'])?->getVenueId(), 'rattaché d\'office par l\'alias confirmé');
    }

    public function testAPlacedFixtureIsNeverMovedByAnAlias(): void
    {
        $aliased = $this->createVenue('Gymnase Matéo');
        $placedVenue = $this->createVenue('Autre Gymnase');

        // Apparié au dépôt sur « Gymnase Matéo », puis PLACÉ à la main sur un AUTRE gymnase.
        $this->importMapped(
            [['D2', 'AL4', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']],
            venueMappings: [['label' => 'GYMNASE MATEO', 'venueId' => $aliased]],
            venueChoices: [],
        );
        $this->placeAt('AL4', $placedVenue);

        // Un re-dépôt au même libellé (alias confirmé) ne déplace JAMAIS un match déjà placé :
        // le backfill d'appariement ne touche que les domiciles encore SANS gymnase.
        $this->importMapped([['D2', 'AL4', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']], null, [], [], []);

        self::assertSame($placedVenue, $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'AL4'])?->getVenueId());
    }

    public function testAttachingAVenueClearsAPersistedVenueLostReason(): void
    {
        $venue = $this->em->getRepository(Venue::class)->find($this->createVenue('Gymnase Matéo'));
        self::assertNotNull($venue);
        $venue->setExternalLabels(['gymnase mateo']);
        $this->em->flush();

        // Un domicile qui a perdu son gymnase (VENUE_LOST persisté) et redevient sans salle…
        $this->importMapped([['D2', 'AL5', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'AL5']);
        self::assertNotNull($fixture);
        $fixture->setVenueId(null);
        $fixture->setUnplacedReason(FixtureUnplacedReason::VENUE_LOST);
        $this->em->flush();

        // …retrouve son gymnase au re-dépôt, et la raison de dépointage s'éteint (setVenueId).
        $this->importMapped([['D2', 'AL5', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE MATEO']], null);
        $this->em->refresh($fixture);
        self::assertSame($venue->getId(), $fixture->getVenueId());
        self::assertNull($fixture->getUnplacedReason());
    }

    // ── Écart salle d'un domicile NON PLACÉ (les « 20 » cas) ────────────────

    public function testUnplacedHomeWhoseVenueDivergesFromTheFileIsAReportedDeviationNotSilentlyRewritten(): void
    {
        $jdr = $this->createVenue('GYMNASE JDR');
        $this->createVenueWithAliases('Debarros', ['salle raphael de barros']);
        $this->unplacedHomeWithVenue('UV01', $jdr, 'OLD LABEL');

        // Re-dépôt : la ligue nomme « SALLE RAPHAEL DE BARROS » (alias de Debarros),
        // le gymnase rattaché est JDR → un écart salle, et RIEN n'est écrit en silence.
        $result = $this->importMapped([['D2', 'UV01', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE RAPHAEL DE BARROS']]);

        self::assertCount(1, $result['unresolvedDeviations']);
        self::assertSame(
            ['app' => 'GYMNASE JDR', 'file' => 'SALLE RAPHAEL DE BARROS'],
            $result['unresolvedDeviations'][0]['fields']['venue'],
        );
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'UV01']);
        self::assertSame($jdr, $fixture?->getVenueId(), 'le gymnase reste intact');
        self::assertSame('OLD LABEL', $fixture?->getFbiVenueLabel(), 'le libellé n\'est PAS réécrit en silence');
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $fixture?->getReviewState());
    }

    public function testTheTwentyCasesStoredLabelAlreadyEqualsTheFileButTheGymDiffersIsADeviation(): void
    {
        // L'état exact des 20 rencontres mesurées : le libellé STOCKÉ égale déjà celui
        // du fichier, mais le gymnase (JDR) n'est pas la salle confirmée du libellé
        // (Debarros). La détection compare le GYMNASE au fichier, jamais le libellé
        // stocké (déjà égal) — le prochain dépôt pose donc la question une fois.
        $jdr = $this->createVenue('GYMNASE JDR');
        $this->createVenueWithAliases('Debarros', ['salle raphael de barros']);
        $this->unplacedHomeWithVenue('UV20', $jdr, 'SALLE RAPHAEL DE BARROS');

        $result = $this->importMapped([['D2', 'UV20', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE RAPHAEL DE BARROS']]);

        self::assertCount(1, $result['unresolvedDeviations']);
        self::assertSame(
            ['app' => 'GYMNASE JDR', 'file' => 'SALLE RAPHAEL DE BARROS'],
            $result['unresolvedDeviations'][0]['fields']['venue'],
        );
    }

    public function testANeutralHomeStaysOutsideTheVenueDeviationDetector(): void
    {
        // Nouveau monde — un domicile sans gymnase n'existe plus que par TERRAIN NEUTRE.
        // Un neutre (venueId null) reste HORS du détecteur d'écart salle : un re-dépôt qui
        // nomme une autre salle ne lève aucun écart (le libellé se met à jour en silence),
        // et la rencontre reste un terrain neutre.
        $this->importMapped(
            [['D2', 'UV00', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X']],
            venueMappings: [['label' => 'Gymnase X', 'venueId' => 'NEUTRAL']],
            venueChoices: [],
        );

        $result = $this->importMapped([['D2', 'UV00', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase Y']], null, [], [], []);

        self::assertSame([], $result['unresolvedDeviations']);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'UV00']);
        self::assertNull($fixture?->getVenueId(), 'un terrain neutre n\'a pas de gymnase');
        self::assertSame(FixtureUnplacedReason::NEUTRAL_GROUND, $fixture?->getUnplacedReason(), 'il reste un terrain neutre');
    }

    public function testKeepAppOnAnUnplacedVenueStampsTheKeptLabelAndIsIdempotent(): void
    {
        $jdr = $this->createVenue('GYMNASE JDR');
        $this->createVenueWithAliases('Debarros', ['salle raphael de barros']);
        $fixture = $this->unplacedHomeWithVenue('UV02', $jdr, 'OLD LABEL');
        $id = $fixture->getId();

        // keep_app : on garde JDR, on adopte le libellé BRUT, on mémorise le normalisé.
        $result = $this->importMapped(
            [['D2', 'UV02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE RAPHAEL DE BARROS']],
            null,
            [['fixtureId' => $id, 'field' => 'venue', 'choice' => 'keep_app']],
        );
        self::assertSame([], $result['unresolvedDeviations']);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'UV02']);
        self::assertSame($jdr, $fixture?->getVenueId(), 'le gymnase est gardé');
        self::assertSame('SALLE RAPHAEL DE BARROS', $fixture?->getFbiVenueLabel(), 'le libellé BRUT est adopté');
        self::assertSame('salle raphael de barros', $fixture?->getKeptVenueLabel(), 'le libellé NORMALISÉ est mémorisé');
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());

        // Un re-dépôt du MÊME libellé est désormais muet (idempotence).
        $again = $this->importMapped([['D2', 'UV02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE RAPHAEL DE BARROS']]);
        self::assertSame([], $again['unresolvedDeviations']);

        // Un AUTRE libellé rouvre un écart.
        $other = $this->importMapped([['D2', 'UV02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE AUTRE']]);
        self::assertCount(1, $other['unresolvedDeviations']);
        self::assertSame('SALLE AUTRE', $other['unresolvedDeviations'][0]['fields']['venue']['file']);
    }

    public function testTakeFileOnAnUnplacedVenueFollowsAConfirmedAlias(): void
    {
        $jdr = $this->createVenue('GYMNASE JDR');
        $debarros = $this->createVenueWithAliases('Debarros', ['salle raphael de barros']);
        $fixture = $this->unplacedHomeWithVenue('UV03', $jdr, 'OLD LABEL');
        $id = $fixture->getId();

        $result = $this->importMapped(
            [['D2', 'UV03', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE RAPHAEL DE BARROS']],
            null,
            [['fixtureId' => $id, 'field' => 'venue', 'choice' => 'take_file']],
        );
        self::assertSame(1, $result['updated']);
        self::assertSame([], $result['unresolvedDeviations']);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'UV03']);
        self::assertSame($debarros, $fixture?->getVenueId(), 'l\'alias confirmé repose le bon gymnase (Debarros)');
        self::assertSame(FixtureStatus::UNPLACED, $fixture?->getStatus(), 'le statut reste UNPLACED');
        self::assertSame('SALLE RAPHAEL DE BARROS', $fixture?->getFbiVenueLabel());
        self::assertNull($fixture?->getKeptVenueLabel());
    }

    public function testTakeFileOnAnUnplacedVenueResolvesToTheMappedVenueNeverNull(): void
    {
        // Chantier A PR 2 (D-b) — dans le monde « domicile toujours avec gymnase »,
        // « prendre le fichier » sur l'écart salle résout vers le gymnase APPARIÉ du nouveau
        // libellé (venueMapping), JAMAIS vers venueId null : l'ancienne « salle remonte à
        // rattacher » disparaît.
        $jdr = $this->createVenue('GYMNASE JDR');
        $repris = $this->createVenue('Gymnase Repris');
        $fixture = $this->unplacedHomeWithVenue('UV04', $jdr, 'OLD LABEL');
        $id = $fixture->getId();

        $result = $this->importMapped(
            [['D2', 'UV04', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE REPRISE']],
            null,
            [['fixtureId' => $id, 'field' => 'venue', 'choice' => 'take_file']],
            [['label' => 'SALLE REPRISE', 'venueId' => $repris]],
            [],
        );
        self::assertSame([], $result['unresolvedDeviations']);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'UV04']);
        self::assertSame($repris, $fixture?->getVenueId(), 'take_file résout vers le gymnase apparié, jamais null');
        self::assertSame(FixtureStatus::UNPLACED, $fixture?->getStatus());
        self::assertSame('SALLE REPRISE', $fixture?->getFbiVenueLabel());
    }

    public function testADateChangeAlongsideTheVenueTakesTheCurrentPathNoVenueDeviation(): void
    {
        // Décision C — la date change EN MÊME TEMPS : le chemin actuel s'exécute
        // intégralement (unplace vide le gymnase, le libellé est adopté, l'alias
        // re-rattache), AUCUN arbitrage salle.
        $jdr = $this->createVenue('GYMNASE JDR');
        $debarros = $this->createVenueWithAliases('Debarros', ['salle raphael de barros']);
        $this->unplacedHomeWithVenue('UV05', $jdr, 'OLD LABEL');

        $result = $this->importMapped([['D2', 'UV05', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', 'SALLE RAPHAEL DE BARROS']]);

        self::assertSame([], $result['unresolvedDeviations'], 'un re-datage ne lève pas d\'écart salle');
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'UV05']);
        self::assertSame('2026-10-10', $fixture?->getMatchDate()->format('Y-m-d'));
        self::assertSame($debarros, $fixture?->getVenueId(), 'le libellé adopté re-rattache le gymnase par alias');
        self::assertSame('SALLE RAPHAEL DE BARROS', $fixture?->getFbiVenueLabel());
    }

    public function testTheVenueScopedPurgeKeepsAKickoffAutoAppliedEntryFromTheSameDeposit(): void
    {
        // Piège vérifié — l'écart salle et un auto-apply d'HEURE du MÊME dépôt doivent
        // COEXISTER : la purge est bornée à ['venue'], elle n'efface jamais l'entrée
        // heure. (Une date ne peut pas coexister — unplace y vide le gymnase — d'où
        // l'heure.)
        $jdr = $this->createVenue('GYMNASE JDR');
        $this->createVenueWithAliases('Debarros', ['salle raphael de barros']);
        $fixture = $this->unplacedHomeWithVenue('UV06', $jdr, 'SALLE RAPHAEL DE BARROS');
        // Non-NEW pour que recordAutoApplied dépose l'entrée heure auto-appliquée.
        $fixture->setReviewState(FixtureReviewState::OUT_OF_SYNC);
        $this->em->flush();

        // Même dépôt : l'heure bouge (15:30 → 17:00) ET la salle diverge toujours.
        $result = $this->importMapped([['D2', 'UV06', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '17:00', 'SALLE RAPHAEL DE BARROS']]);

        self::assertCount(1, $result['unresolvedDeviations'], 'l\'écart salle est rapporté');
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'UV06']);
        $kickoff = $fixture?->getPendingDeviation('kickoff');
        self::assertNotNull($kickoff, 'l\'entrée heure du même dépôt survit à la purge scoped venue');
        self::assertTrue($kickoff['autoApplied']);
        self::assertNotNull($fixture?->getPendingDeviation('venue'));
    }

    public function testAPendingVenueDeviationDropsWhenTheLabelBecomesConformAgain(): void
    {
        $jdr = $this->createVenue('GYMNASE JDR');
        $this->unplacedHomeWithVenue('UV07', $jdr, 'OLD LABEL');

        // Premier dépôt divergent → un écart salle naît (OUT_OF_SYNC).
        $first = $this->importMapped([['D2', 'UV07', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'SALLE AUTRE']]);
        self::assertCount(1, $first['unresolvedDeviations']);
        $this->em->clear();
        $born = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'UV07']);
        self::assertNotNull($born?->getPendingDeviation('venue'));
        self::assertSame(FixtureReviewState::OUT_OF_SYNC, $born?->getReviewState());

        // Deuxième dépôt nommant le gymnase lui-même (conforme au fuzzy) → l'écart tombe.
        $second = $this->importMapped([['D2', 'UV07', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', 'GYMNASE JDR']]);
        self::assertSame([], $second['unresolvedDeviations']);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'UV07']);
        self::assertNull($fixture?->getPendingDeviation('venue'), 'l\'entrée salle pendante tombe');
        self::assertSame(FixtureReviewState::REVIEWED, $fixture?->getReviewState());
    }

    // ── Setup & helpers ────────────────────────────────────────────────────

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(FbiFixtureImporter::class);

        $this->club = $this->createClub(self::CLUB_NAME, 'bc-testville');
        $this->scopeGucToClub($this->club->getId());
        $this->team = $this->createTeam('U13-1');

        // Horloge figée par défaut. Sans ancre, un domicile daté d'octobre bascule
        // « dans la semaine ISO en cours » au fil réel du calendrier : treatOnArrival
        // le fait alors naître REVIEWED et « FBI fait foi » applique d'office ses écarts
        // imminents (P4-199, {@see FbiFixtureImporter}) — d'où des tests verts un
        // dimanche puis rouges le lundi suivant. On fixe « aujourd'hui » avant l'octobre
        // de ces scénarios ; tout test qui a besoin d'un autre jour repose son pinClock.
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00'));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        // Release any pinned clock so it never bleeds into another test (Redis is
        // shared, not rolled back).
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    private function pinClock(DateTimeImmutable $at): void
    {
        self::getContainer()->get(DevClockStore::class)->set($at);
    }

    /** A competition of $this->team paired to a FFBB poule (refs + frozen data). */
    private function pairedCompetition(string $name, array $pouleClubs, int $expectedMatchdays, ?string $canonicalName = null): Competition
    {
        $competition = new Competition;
        $competition->setClubId($this->club->getId());
        $competition->setSeasonId($this->team->getSeasonId());
        $competition->setTeamId($this->team->getId());
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $competition->setFfbbCompetitionId('ffbb-' . md5($name));
        $competition->setFfbbPouleId('poule-' . md5($name));
        $competition->setFfbbPouleName('Poule T');
        $competition->setFfbbCompetitionName($canonicalName ?? $name);
        $competition->setExpectedMatchdays($expectedMatchdays);
        $competition->setFfbbPouleOpponents($pouleClubs);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    /**
     * A club named as the REAL export writes it, plus two teams to map onto —
     * the GUC is re-scoped so RLS confines every read/write to this club.
     *
     * @return array{Club, Team, Team}
     */
    private function realClub(): array
    {
        $club = $this->createClub(self::REAL_CLUB_NAME, 'bccl');
        $this->scopeGucToClub($club->getId());
        $this->club = $club;
        $teamA = $this->createTeam('SF3');
        $teamB = $this->createTeam('SM1');

        return [$club, $teamA, $teamB];
    }

    private function createClub(string $name, string $slugPrefix): Club
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName($name);
        $club->setSlug($slugPrefix . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function createTeam(string $name): Team
    {
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $this->club->getId()]);
        if (!$season instanceof Season) {
            $season = new Season;
            $season->setClubId($this->club->getId());
            $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
            $season->setName((string) $year);
            $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
            $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
            $season->setStatus(SeasonStatus::ACTIVE);
            $season->setTransitionData([]);
            $this->em->persist($season);
        }

        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->createCategoryId());
        $team->setPriorityTierId(3);
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createCategoryId(): string
    {
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($this->club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);
        $this->em->flush();

        return $category->getId();
    }

    /**
     * Imports rows with the default mapping (every division of the rows →
     * $this->team) unless explicit mappings are given.
     *
     * Chantier A PR 2 — « pas de salle appariée, pas d'import » : chaque domicile CRÉÉ
     * doit porter un gymnase. Par DÉFAUT ($venueMappings/$venueChoices null), ce helper
     * APPARIE automatiquement chaque salle à domicile du fichier à un gymnase par défaut
     * (sans alias → INERTE tant que le serveur n'enforce pas la règle : le venueMapping
     * est ignoré et le domicile reste venueId=null, comportement d'avant). Un test qui
     * veut piloter la couverture (refus, alias confirmé, écart salle, terrain neutre)
     * passe `venueMappings: []` / `venueChoices: []` (opt-out explicite).
     *
     * @param list<list<string>>                                                            $rows
     * @param list<array{division: string, fbiTeamLabel: string|null, teamId: string}>|null $mappings
     * @param list<array{fixtureId: string, field: string, choice: string}>                 $decisions
     * @param list<array{label: string, venueId: string}>|null                              $venueMappings null = auto-apparier les salles à domicile ; [] = opt-out
     * @param list<array{division: string, venueId: string}>|null                           $venueChoices  null = auto-apparier les domiciles sans salle ; [] = opt-out
     *
     * @return array{created: int, updated: int, unchanged: int, exempted: int, errors: list<string>, warnings: list<array{type: string, division: string, externalRef: string, message: string}>, unmappedDivisions: list<array{name: string, fbiTeamLabel: string|null, rowCount: int}>, unresolvedDeviations: list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}>, depositedAt: string}
     */
    private function importMapped(array $rows, ?array $mappings = null, array $decisions = [], ?array $venueMappings = null, ?array $venueChoices = null): array
    {
        if (null === $mappings) {
            $divisions = array_values(array_unique(array_column($rows, 0)));
            $mappings = array_map(
                fn (string $division): array => ['division' => $division, 'fbiTeamLabel' => null, 'teamId' => $this->team->getId()],
                $divisions,
            );
        }
        if (null === $venueMappings || null === $venueChoices) {
            [$autoMappings, $autoChoices] = $this->autoVenueCoverage($rows);
            $venueMappings ??= $autoMappings;
            $venueChoices ??= $autoChoices;
        }

        return $this->importer->import($this->xlsx($rows), $this->club, $mappings, $decisions, null, $venueMappings, $venueChoices);
    }

    /**
     * Dérive la couverture « salles » des lignes : chaque salle à DOMICILE distincte (le
     * club en Equipe 1) → le gymnase par défaut ; chaque division ayant un domicile SANS
     * salle → le gymnase par défaut. Inerte tant que le serveur n'enforce pas (venueMappings
     * ignoré), couvre les domiciles une fois la règle en vigueur.
     *
     * @param list<list<string>> $rows
     *
     * @return array{0: list<array{label: string, venueId: string}>, 1: list<array{division: string, venueId: string}>}
     */
    private function autoVenueCoverage(array $rows): array
    {
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        \assert($normalizer instanceof VenueLabelNormalizer);
        // Les libellés DÉJÀ confirmés comme alias d'un gymnase : on ne les ré-apparie pas
        // (ce serait une collision 422 avec leur porteur, p. ex. « SALLE RAPHAEL DE BARROS »
        // des cas d'écart salle). Ils sont déjà couverts par leur alias.
        $confirmed = [];
        foreach ($this->em->getRepository(Venue::class)->findAll() as $venue) {
            foreach ($venue->getExternalLabels() as $alias) {
                $confirmed[$alias] = true;
            }
        }

        $needle = mb_strtolower(self::CLUB_NAME);
        $labels = [];
        $divisionsNoSalle = [];
        foreach ($rows as $row) {
            $equipe1 = mb_strtolower(trim((string) ($row[2] ?? '')));
            if (false === mb_stripos($equipe1, $needle)) {
                continue; // extérieur : aucune salle à domicile à couvrir
            }
            $salle = trim((string) ($row[6] ?? ''));
            if ('' === $salle) {
                $divisionsNoSalle[trim((string) ($row[0] ?? ''))] = true;

                continue;
            }
            if (isset($confirmed[$normalizer->normalize($salle)])) {
                continue; // déjà couvert par un alias confirmé
            }
            $labels[$salle] = true;
        }
        if ([] === $labels && [] === $divisionsNoSalle) {
            return [[], []];
        }
        $venueId = $this->ensureDefaultVenue();
        $venueMappings = array_map(static fn (string $label): array => ['label' => $label, 'venueId' => $venueId], array_keys($labels));
        $venueChoices = array_map(static fn (string $division): array => ['division' => $division, 'venueId' => $venueId], array_keys($divisionsNoSalle));

        return [$venueMappings, $venueChoices];
    }

    /** Un gymnase par défaut SANS alias (inerte NOW), cible de l'appariement automatique. */
    private function ensureDefaultVenue(): string
    {
        if (null === $this->defaultVenueId) {
            $this->defaultVenueId = $this->createVenue('Gymnase par défaut (appariement)');
        }

        return $this->defaultVenueId;
    }

    /**
     * Imports a home fixture (no salle → nothing auto-attaches), then pins the exact
     * « 20 »-case state: UNPLACED, a REAL club gym as venueId, a divergent stored FBI
     * label. Returns the managed fixture.
     */
    private function unplacedHomeWithVenue(string $externalRef, string $venueId, string $fbiLabel): Fixture
    {
        $this->importMapped([['D2', $externalRef, 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture);
        $fixture->setVenueId($venueId);
        $fixture->setFbiVenueLabel($fbiLabel);
        $this->em->flush();

        return $fixture;
    }

    /** The id of the imported fixture — the key of a reconciliation decision. */
    private function fixtureId(string $externalRef): string
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture);

        return $fixture->getId();
    }

    /** Marks the fixture as placed by the club: PLACED + a venue id. */
    private function place(string $externalRef): string
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture);
        $venueId = '11111111-1111-4111-8111-111111111111';
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $fixture->setVenueId($venueId);
        $this->em->flush();

        return $venueId;
    }

    /** Places the fixture at a REAL venue (for the fuzzy salle compare). */
    private function placeAt(string $externalRef, string $venueId): void
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture);
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $fixture->setVenueId($venueId);
        $this->em->flush();
    }

    private function setStatus(string $externalRef, FixtureStatus $status): void
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture);
        $fixture->setStatus($status, new DateTimeImmutable);
        $this->em->flush();
    }

    /** Force l'état de traitement : simule un résidu d'avant le rattrapage. */
    private function forceReviewState(string $externalRef, FixtureReviewState $state): void
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture);
        $fixture->setReviewState($state);
        $this->em->flush();
    }

    /**
     * La rencontre importée de $this->team portant ce numéro SOUS cette division (son
     * championnat résolu par le nom) — pour distinguer deux phases qui partagent le numéro.
     */
    private function fixtureUnderDivision(string $externalRef, string $divisionName): ?Fixture
    {
        $competition = $this->em->getRepository(Competition::class)->findOneBy(['teamId' => $this->team->getId(), 'name' => $divisionName]);
        if (!$competition instanceof Competition) {
            return null;
        }

        return $this->em->getRepository(Fixture::class)->findOneBy(['competitionId' => $competition->getId(), 'externalRef' => $externalRef]);
    }

    /** Une compétition brute de $this->team, sans appariement de poule. */
    private function rawCompetition(string $name): Competition
    {
        $competition = new Competition;
        $competition->setClubId($this->club->getId());
        $competition->setSeasonId($this->team->getSeasonId());
        $competition->setTeamId($this->team->getId());
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    /**
     * Une rencontre FBI-référencée brute de $this->team, portant une division (colonne
     * {@see Fixture::setExternalDivision}, D1) — pour éprouver l'index unique
     * (club, saison, équipe, division, ref) au niveau base.
     */
    private function rawReferencedFixture(string $externalRef, string $division, string $opponent, string $dateYmd): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->team->getSeasonId());
        $fixture->setTeamId($this->team->getId());
        $fixture->setMatchDate(new DateTimeImmutable($dateYmd));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel($opponent);
        $fixture->setExternalRef($externalRef);
        $fixture->setExternalDivision($division);

        return $fixture;
    }

    /** A real Venue of the club+season; returns its id. */
    private function createVenue(string $name): string
    {
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $this->club->getId()]);
        self::assertNotNull($season);
        $venue = new Venue;
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    /**
     * A Venue carrying confirmed FBI/FFBB aliases (P4-187a); returns its id.
     *
     * @param list<string> $aliases already-normalized labels
     */
    private function createVenueWithAliases(string $name, array $aliases): string
    {
        $id = $this->createVenue($name);
        $this->em->getRepository(Venue::class)->find($id)?->setExternalLabels($aliases);
        $this->em->flush();

        return $id;
    }

    /** @param list<list<string>> $rows real-format header (« N° de match » with its trailing space) */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'N° de match ', 'Equipe 1', 'Equipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );

        return $this->write($spreadsheet);
    }

    private function write(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }
}
