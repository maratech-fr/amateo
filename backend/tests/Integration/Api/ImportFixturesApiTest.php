<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\FixtureStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * End-to-end FBI one-pass import over HTTP (cadrage P1-4): analyze (dry-run
 * mapping table) → import with mappings → diff/update on re-import. Also the
 * NR of the « périmètre engagé » axis: the import IS the engagement.
 */
#[Group('integration')]
final class ImportFixturesApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    public function testAnalyzeThenOnePassImportThenDiffUpdate(): void
    {
        [$token, $clubName, $teamId] = $this->registerWithTeam();
        $needle = strtoupper($clubName);

        // 1. ANALYZE — dry-run: the division shows up unmapped, nothing written.
        $this->upload('/api/fixtures/import/analyze', $token, $this->xlsx([
            ['D2 Poule A', 'A9001', $needle . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
            ['D2 Poule A', 'A9002', 'AS Voisins', $needle . ' - 1', '10/10/2026', '00:00', 'Salle Y'],
        ]));
        self::assertResponseStatusCodeSame(200);
        $analysis = $this->responseData();
        self::assertSame(2, $analysis['totalRows']);
        self::assertSame([[
            'name' => 'D2 Poule A',
            'fbiTeamLabel' => null,
            'rowCount' => 2,
            'teamId' => null,
            'competitionId' => null,
            // P1-4 PR F2 — suggestion + poule-guard verdicts of the dry-run.
            'suggestedTeamId' => null,
            'suggestedCompetitionId' => null,
            'pouleError' => null,
            'pouleUnknownOpponents' => [],
        ]], $analysis['divisions']);

        $this->client->request('GET', '/api/fixtures', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertCount(0, $this->responseData()['member'] ?? [], 'analyze must write nothing');

        // 2. IMPORT — same file + the manager's mapping: ONE pass creates all.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2 Poule A', 'A9001', $needle . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
            ['D2 Poule A', 'A9002', 'AS Voisins', $needle . ' - 1', '10/10/2026', '00:00', 'Salle Y'],
        ]), ['mappings' => json_encode([['division' => 'D2 Poule A', 'teamId' => $teamId]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);
        $report = $this->responseData();
        self::assertSame(2, $report['created']);
        self::assertSame(0, $report['updated']);
        self::assertSame([], $report['errors']);
        self::assertSame([], $report['unmappedDivisions']);

        // The fixtures surface on the collection; the FBI « Salle » label is
        // exposed (fact F3) and the 00:00 sentinel produced a null kickoff.
        $this->client->request('GET', '/api/fixtures', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $members = $this->responseData()['member'] ?? [];
        self::assertCount(2, $members);
        $byRef = array_column($members, null, 'externalRef');
        self::assertSame('Gymnase X', $byRef['A9001']['fbiVenueLabel'] ?? null);
        self::assertTrue(\array_key_exists('kickoffTime', $byRef['A9002']), 'kickoffTime must be exposed');
        self::assertNull($byRef['A9002']['kickoffTime']);

        // 3. RE-IMPORT with a rescheduled date — diff/update, not skip: the
        // mapping persisted, no « mappings » field needed anymore.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2 Poule A', 'A9001', $needle . ' - 1', 'AS Voisins', '17/10/2026', '15:30', 'Gymnase X'],
            ['D2 Poule A', 'A9002', 'AS Voisins', $needle . ' - 1', '10/10/2026', '00:00', 'Salle Y'],
        ]));
        self::assertResponseStatusCodeSame(200);
        $second = $this->responseData();
        self::assertSame(0, $second['created']);
        self::assertSame(1, $second['updated']);
        self::assertSame(1, $second['unchanged']);
        self::assertSame('RESCHEDULED', $second['warnings'][0]['type'] ?? null);
    }

    public function testReconciliationDeviationsOverHttp(): void
    {
        [$token, $clubName, $teamId] = $this->registerWithTeam();
        $needle = strtoupper($clubName);

        // Import a home match, then place it (PLACED — inside the perimeter).
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9300', $needle . ' - 1', 'AS Voisins', '03/10/2026', '15:30', 'Gymnase X'],
        ]), ['mappings' => json_encode([['division' => 'D2', 'teamId' => $teamId]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);

        $fixtureId = $this->placeFixture('A9300');

        // ANALYZE the same file with a rescheduled date → the deviation surfaces.
        $this->upload('/api/fixtures/import/analyze', $token, $this->xlsx([
            ['D2', 'A9300', $needle . ' - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X'],
        ]));
        self::assertResponseStatusCodeSame(200);
        $analysis = $this->responseData();
        self::assertCount(1, $analysis['deviations']);
        self::assertSame($fixtureId, $analysis['deviations'][0]['fixtureId']);
        self::assertSame(['app' => '2026-10-03', 'file' => '2026-10-10'], $analysis['deviations'][0]['fields']['date']);

        // IMPORT WITHOUT a decision → intact + reported in unresolvedDeviations.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9300', $needle . ' - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X'],
        ]));
        self::assertResponseStatusCodeSame(200);
        $noDecision = $this->responseData();
        self::assertCount(1, $noDecision['unresolvedDeviations']);
        self::assertArrayHasKey('depositedAt', $noDecision);
        $this->assertFixtureDate($fixtureId, '2026-10-03');

        // IMPORT WITH a take_file decision → applied, nothing left unresolved.
        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9300', $needle . ' - 1', 'AS Voisins', '10/10/2026', '15:30', 'Gymnase X'],
        ]), ['decisions' => json_encode([['fixtureId' => $fixtureId, 'field' => 'date', 'choice' => 'take_file']], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseData()['unresolvedDeviations']);
        $this->assertFixtureDate($fixtureId, '2026-10-10');

        // Freshness read (open to member, tenant+season auto): the last deposit.
        $this->client->request('GET', '/api/fbi-ingestions/latest', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $freshness = $this->responseData()['latest'] ?? null;
        self::assertIsArray($freshness);
        self::assertSame('FBI_XLSX', $freshness['source']);
    }

    public function testMalformedDecisionsFieldIsRejected(): void
    {
        [$token] = $this->registerWithTeam();

        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9400', 'X - 1', 'AS Voisins', '03/10/2026', '', ''],
        ]), ['decisions' => '{ not a list }']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testImportEngagesTheTeam(): void
    {
        // NR « périmètre engagé » (§7.1) : the import IS the engagement — even
        // all-UNPLACED, the team can no longer be deleted (its matches exist
        // at the federation).
        [$token, $clubName, $teamId] = $this->registerWithTeam();

        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9200', strtoupper($clubName) . ' - 1', 'AS Voisins', '03/10/2026', '', ''],
        ]), ['mappings' => json_encode([['division' => 'D2', 'teamId' => $teamId]], \JSON_THROW_ON_ERROR)]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->responseData()['created']);

        $this->client->request('DELETE', '/api/teams/' . $teamId, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(409, 'an engaged team must refuse deletion');
    }

    public function testImportRefusedWhileSocleNotValidated(): void
    {
        // SocleGuard path on BOTH import endpoints: without a validated main
        // plan the write must 409 — the other tests pre-stamp the socle.
        [$token, $clubName] = $this->registerWithTeam(validateSocle: false);

        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9100', strtoupper($clubName) . ' - 1', 'AS Voisins', '03/10/2026', '15:30', ''],
        ]));
        self::assertResponseStatusCodeSame(409);
    }

    public function testNonXlsxUploadIsRejected(): void
    {
        [$token] = $this->registerWithTeam();

        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.csv';
        file_put_contents($path, 'not;an;xlsx');
        $this->tempFiles[] = $path;

        $this->client->request('POST', '/api/fixtures/import', [], [
            'file' => new UploadedFile($path, 'fbi.csv', 'text/csv', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testMalformedMappingsFieldIsRejected(): void
    {
        [$token, $clubName] = $this->registerWithTeam();

        $this->upload('/api/fixtures/import', $token, $this->xlsx([
            ['D2', 'A9300', strtoupper($clubName) . ' - 1', 'AS Voisins', '03/10/2026', '', ''],
        ]), ['mappings' => '{"not":"a list"}']);
        self::assertResponseStatusCodeSame(400);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** Places the imported fixture (PLACED + a venue id) and returns its id. */
    private function placeFixture(string $externalRef): string
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertNotNull($fixture, 'imported fixture must exist');
        $fixture->setStatus(FixtureStatus::PLACED);
        $fixture->setVenueId('11111111-1111-4111-8111-111111111111');
        $this->em->flush();

        return $fixture->getId();
    }

    private function assertFixtureDate(string $id, string $expected): void
    {
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->find($id);
        self::assertNotNull($fixture);
        self::assertSame($expected, $fixture->getMatchDate()->format('Y-m-d'));
    }

    private function upload(string $uri, string $token, string $filePath, array $parameters = []): void
    {
        $this->client->request('POST', $uri, $parameters, [
            'file' => new UploadedFile($filePath, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /**
     * Register a club (its name is the HOME/AWAY needle) + create a team in the
     * register-seeded season.
     *
     * @return array{0: string, 1: string, 2: string} [token, clubName, teamId]
     */
    private function registerWithTeam(bool $validateSocle = true): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'fbi' . substr(md5(uniqid('', true)), 0, 6);
        $clubName = 'BC ' . ucfirst($suffix);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Bi', 'ara' => strtoupper($suffix), 'club_name' => $clubName, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        $clubId = $me['club']['id'];

        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertNotNull($season);
        // SocleGuard: fixture import is a match-module write, refused (409) until
        // the season's plan points at a version — settle it like the real flow
        // would (opt-out for the test covering the 409 branch itself).
        if ($validateSocle) {
            $this->settleSeasonPlan($season);
        }

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        self::assertNotNull($sport, 'register seeds the basketball sport');
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('U13-1');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return [$token, $clubName, $team->getId()];
    }

    /** @param list<list<string>> $rows real-format header (fact F1/F8) */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'N° de match ', 'Equipe 1', 'Equipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );
        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
