<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CompetitionType;
use App\Enum\FbiIngestionSource;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Exception\ImportRejectedException;
use App\Repository\FbiIngestionRepository;
use App\Service\FbiFixtureImporter;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
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

        $result = $this->importer->import(self::REAL_EXPORT, $club, [
            ['division' => 'DF2', 'fbiTeamLabel' => null, 'teamId' => $teamA->getId()],
            ['division' => 'PNM', 'fbiTeamLabel' => null, 'teamId' => $teamB->getId()],
        ]);

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
        $file = $this->xlsx([
            ['RMU18 Brassage', 'R2001', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '', ''],
        ]);

        $result = $this->importer->import($file, $this->club, [
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
        $file = $this->xlsx([
            ['D2', 'A1', 'BC TESTVILLE - 1', 'AS X', '03/10/2026', '', ''],
            ['D2', 'A2', 'BC TESTVILLE - 2', 'AS Y', '03/10/2026', '', ''],
        ]);

        // Analyze splits the division per club-team label (décision fondateur
        // 2026-08-02 : le suffixe « - 2 » appareille la 2ᵉ équipe).
        $analysis = $this->importer->analyze($file, $this->club);
        $labels = array_column($analysis['divisions'], 'fbiTeamLabel');
        sort($labels);
        self::assertSame(['BC TESTVILLE - 1', 'BC TESTVILLE - 2'], $labels);

        $result = $this->importer->import($file, $this->club, [
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
        $file = $this->xlsx([
            ['D2', 'X1', 'BC TESTVILLE - 1', 'US INCONNU', '03/10/2026', '', ''],
            ['D2', 'X2', 'BC TESTVILLE - 1', 'CS MYSTERE', '10/10/2026', '', ''],
            ['D3', 'Y1', 'BC TESTVILLE - 1', 'AS Libre', '03/10/2026', '', ''],
        ]);
        $teamTwo = $this->createTeam('U15-2');

        $result = $this->importer->import($file, $this->club, [
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
        $file = $this->xlsx([
            ['D2', 'X1', 'BC TESTVILLE - 1', 'AS VOISINS', '03/10/2026', '', ''],
            ['D2', 'X2', 'BC TESTVILLE - 1', 'BC RIVAUX', '10/10/2026', '', ''],
            ['D2', 'X3', 'BC TESTVILLE - 1', 'US INTRUS', '17/10/2026', '', ''],
        ]);

        $result = $this->importer->import($file, $this->club, []);

        self::assertSame(3, $result['created']);
        $mismatch = array_values(array_filter($result['warnings'], static fn (array $w): bool => 'POULE_MISMATCH' === $w['type']));
        self::assertCount(1, $mismatch);
        self::assertStringContainsString('US INTRUS', $mismatch[0]['message']);
    }

    public function testDivisionWithoutPairingIsNeverChecked(): void
    {
        // No FFBB refs on the competition → today's behaviour, zero control.
        $file = $this->xlsx([
            ['D2', 'X1', 'BC TESTVILLE - 1', 'N IMPORTE QUI', '03/10/2026', '', ''],
        ]);
        $result = $this->importer->import($file, $this->club, [
            ['division' => 'D2', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId()],
        ]);

        self::assertSame(1, $result['created']);
        self::assertSame([], array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'poule')));
    }

    public function testCompletenessNamesThePartialFile(): void
    {
        // Expectation frozen at pairing: 22 matchdays; the file brings 2 → named.
        $this->pairedCompetition('D2', ['AS VOISINS', 'BC RIVAUX'], 22);
        $file = $this->xlsx([
            ['D2', 'X1', 'BC TESTVILLE - 1', 'AS VOISINS', '03/10/2026', '', ''],
            ['D2', 'X2', 'BC TESTVILLE - 1', 'BC RIVAUX', '10/10/2026', '', ''],
        ]);

        $result = $this->importer->import($file, $this->club, []);

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

    public function testARemapWithADriftedLabelUpdatesTheStoredOne(): void
    {
        // Multi-label division mapped once with « - 1 »… then the FBI export
        // drifts the club label. Re-mapping with the NEW label must update the
        // stored one — otherwise the division stays unmapped forever (the
        // manager re-maps, the import still says unmapped: a dead loop).
        $teamTwo = $this->createTeam('U15-2');
        $old = $this->xlsx([
            ['D2', 'B1', 'BC TESTVILLE - 1', 'AS X', '03/10/2026', '', ''],
            ['D2', 'B2', 'BC TESTVILLE - 2', 'AS Y', '03/10/2026', '', ''],
        ]);
        $this->importer->import($old, $this->club, [
            ['division' => 'D2', 'fbiTeamLabel' => 'BC TESTVILLE - 1', 'teamId' => $this->team->getId()],
            ['division' => 'D2', 'fbiTeamLabel' => 'BC TESTVILLE - 2', 'teamId' => $teamTwo->getId()],
        ]);

        $drifted = $this->xlsx([
            ['D2', 'B3', 'BC TESTVILLE 1B', 'AS Z', '10/10/2026', '', ''],
            ['D2', 'B4', 'BC TESTVILLE - 2', 'AS W', '10/10/2026', '', ''],
        ]);
        $result = $this->importer->import($drifted, $this->club, [
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

    public function testKeepAppWritesNothingAndBornsATraceThatPersistsThenDies(): void
    {
        // Distinct deposit instants so « the last deposit » is unambiguous (in
        // real life deposits are days apart; the DB timestamp is second-precise).
        $this->pinClock(new DateTimeImmutable('2026-09-01 10:00:00'));
        $this->importMapped([['D2', 'RD02', 'BC TESTVILLE - 1', 'AS Voisins', '03/10/2026', '15:30', '']]);
        $this->place('RD02');
        $id = $this->fixtureId('RD02');

        // keep_app: nothing written, a trace is born (no unresolved — it was decided).
        $this->pinClock(new DateTimeImmutable('2026-09-08 10:00:00'));
        $r1 = $this->importMapped(
            [['D2', 'RD02', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']],
            null,
            [['fixtureId' => $id, 'field' => 'date', 'choice' => 'keep_app']],
        );
        self::assertSame(0, $r1['updated']);
        self::assertSame([], $r1['unresolvedDeviations']);
        self::assertSame('2026-10-03', $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'RD02'])?->getMatchDate()->format('Y-m-d'));

        // Re-deposit the SAME divergent file: the écart re-appears, persisting.
        $again = $this->importer->analyze($this->xlsx([['D2', 'RD02', 'BC TESTVILLE - 1', 'AS Voisins', '10/10/2026', '15:30', '']]), $this->club);
        self::assertCount(1, $again['deviations']);
        self::assertTrue($again['deviations'][0]['persisting']);

        // Re-deposit a CONFORMING file (date back to the app value): the trace dies.
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
        $away?->setStatus(FixtureStatus::PLACED);
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
        $api = new FbiIngestion($this->club->getId(), $this->team->getSeasonId(), FbiIngestionSource::FFBB_API, new DateTimeImmutable('+1 hour'), 0, 0, 0, 0, []);
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

        $result = $this->importer->import($this->write($spreadsheet), $this->club, [
            ['division' => 'D2', 'fbiTeamLabel' => null, 'teamId' => $this->team->getId()],
        ]);

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
        $file = $this->xlsx([
            ['PRE REGIONALE MASCULINE', 'X1', 'BC TESTVILLE - 1', 'AS VOISINS', '03/10/2026', '', ''],
        ]);

        $result = $this->importer->import($file, $this->club, [
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
        $file = $this->xlsx([
            ['D2', 'A1', 'BC TESTVILLE - 1', 'AS X', '03/10/2026', '', ''],
            ['D2', 'A2', 'BC TESTVILLE - 2', 'AS Y', '03/10/2026', '', ''],
        ]);

        $this->importer->import($file, $this->club, [
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

    // ── Setup & helpers ────────────────────────────────────────────────────

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(FbiFixtureImporter::class);

        $this->club = $this->createClub(self::CLUB_NAME, 'bc-testville');
        $this->scopeGucToClub($this->club->getId());
        $this->team = $this->createTeam('U13-1');
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
     * @param list<list<string>>                                                            $rows
     * @param list<array{division: string, fbiTeamLabel: string|null, teamId: string}>|null $mappings
     * @param list<array{fixtureId: string, field: string, choice: string}>                 $decisions
     *
     * @return array{created: int, updated: int, unchanged: int, exempted: int, errors: list<string>, warnings: list<array{type: string, division: string, externalRef: string, message: string}>, unmappedDivisions: list<array{name: string, fbiTeamLabel: string|null, rowCount: int}>, unresolvedDeviations: list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}>, depositedAt: string}
     */
    private function importMapped(array $rows, ?array $mappings = null, array $decisions = []): array
    {
        if (null === $mappings) {
            $divisions = array_values(array_unique(array_column($rows, 0)));
            $mappings = array_map(
                fn (string $division): array => ['division' => $division, 'fbiTeamLabel' => null, 'teamId' => $this->team->getId()],
                $divisions,
            );
        }

        return $this->importer->import($this->xlsx($rows), $this->club, $mappings, $decisions);
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
        $fixture->setStatus(FixtureStatus::PLACED);
        $fixture->setVenueId($venueId);
        $this->em->flush();

        return $venueId;
    }

    /** Places the fixture at a REAL venue (for the fuzzy salle compare). */
    private function placeAt(string $externalRef, string $venueId): void
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture);
        $fixture->setStatus(FixtureStatus::PLACED);
        $fixture->setVenueId($venueId);
        $this->em->flush();
    }

    private function setStatus(string $externalRef, FixtureStatus $status): void
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture);
        $fixture->setStatus($status);
        $this->em->flush();
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
