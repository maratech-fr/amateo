<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\CompetitionType;
use App\Enum\FbiIngestionSource;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * RMM-4 PR-3 — the FFBB-API reconciliation channel end-to-end (GET
 * /api/ffbb/rencontres + POST /api/ffbb/rencontres/apply), on the deterministic
 * FFBB stub (services_test.yaml). Focus: FBI reste la vérité, l'API est un
 * confort qui CROISE les deux sources.
 *
 * Covers the 3-tier matching (date / moved-date-by-opponent), a fixture with NO
 * API hit producing no signal, the creatable amicaux (noise excluded), idempotent
 * creation, the SERVER re-fetch (forged/foreign creations ignored), the deviation
 * diff + take_file, the FFBB_API ingestion that is NOT the xlsx freshness and
 * never touches a trace, the partial-unique backstop, and the gardes.
 */
#[Group('integration')]
final class FfbbRencontresApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testListReturnsCreatableAmicauxAndExcludesForeignNoise(): void
    {
        [$token, , $clubId] = $this->register('FRA');
        $this->useStubClubCode($clubId);
        $this->createTeam($clubId);

        $this->client->request('GET', '/api/ffbb/rencontres', [], [], $this->auth($token));
        self::assertResponseIsSuccessful();
        $body = $this->json();

        self::assertArrayHasKey('fetchedAt', $body);
        self::assertSame([], $body['deviations'], 'no fixture yet → no deviation');
        $rencontreIds = array_column($body['creatable'], 'rencontreId');
        sort($rencontreIds);
        self::assertSame([FfbbHttpClientStub::RENCONTRE_AMICAL_ID, FfbbHttpClientStub::RENCONTRE_CHAMP_ID], $rencontreIds, 'both club rencontres are creatable; the foreign noise hit is excluded');
        self::assertNotContains(FfbbHttpClientStub::RENCONTRE_NOISE_ID, $rencontreIds);

        $amical = $this->creatable($body, FfbbHttpClientStub::RENCONTRE_AMICAL_ID);
        self::assertSame(FfbbHttpClientStub::AMICAL_OPPONENT, $amical['opponentLabel']);
        self::assertSame('HOME', $amical['homeAway']);
        self::assertSame(FfbbHttpClientStub::AMICAL_KICKOFF, $amical['kickoff']);
    }

    public function testMatchingResolvesByDateAndCatchesAMovedDateByOpponent(): void
    {
        [$token, , $clubId] = $this->register('FRB');
        $this->useStubClubCode($clubId);
        $team = $this->createTeam($clubId);
        $season = $this->seasonOf($clubId);
        $this->pairTeamToStubCompetition($clubId, $season->getId(), $team->getId());
        $champDate = $this->rencontreDate();

        // Tier 2 — a fixture at the rencontre's exact date → the champ rencontre
        // is MATCHED (not proposed as creatable).
        $this->createFixture($clubId, $season->getId(), $team->getId(), $champDate, 'PLACED VS SOMEONE', true, FixtureStatus::PLACED, FfbbHttpClientStub::CHAMP_KICKOFF);
        $ids = $this->listCreatableIds($token);
        self::assertNotContains(FfbbHttpClientStub::RENCONTRE_CHAMP_ID, $ids, 'tier 2: exact (team, date) matches → not creatable');

        // Move the fixture's date; keep the opponent = the rencontre's opponent →
        // tier 3 catches it (a moved date), still not creatable.
        $this->replaceTeamFixtures($clubId, $team->getId());
        $this->createFixture($clubId, $season->getId(), $team->getId(), $champDate->modify('+7 days'), FfbbHttpClientStub::CHAMP_OPPONENT, true, FixtureStatus::PLACED, FfbbHttpClientStub::CHAMP_KICKOFF);
        $ids = $this->listCreatableIds($token);
        self::assertNotContains(FfbbHttpClientStub::RENCONTRE_CHAMP_ID, $ids, 'tier 3: (team, normalized opponent) catches a moved date → not creatable');
    }

    public function testAFixtureWithNoApiHitProducesNoSignal(): void
    {
        [$token, , $clubId] = $this->register('FRC');
        $this->useStubClubCode($clubId);
        $team = $this->createTeam($clubId);
        $season = $this->seasonOf($clubId);
        // A placed home fixture the API never mentions (an opponent absent of the
        // stub) — it must never appear as a deviation nor as anything else.
        $this->createFixture($clubId, $season->getId(), $team->getId(), $this->rencontreDate()->modify('+3 days'), 'CLUB INCONNU DE LA FFBB', true, FixtureStatus::PLACED, '19:00');

        $this->client->request('GET', '/api/ffbb/rencontres', [], [], $this->auth($token));
        $body = $this->json();
        self::assertSame([], $body['deviations'], 'a fixture with no API hit is never an absence signal');
    }

    public function testApplyCreatesUnplacedAmicalAndIsIdempotent(): void
    {
        [$token, , $clubId] = $this->register('FRD');
        $this->useStubClubCode($clubId);
        $team = $this->createTeam($clubId);

        $created = $this->apply($token, [], [['rencontreId' => FfbbHttpClientStub::RENCONTRE_AMICAL_ID, 'teamId' => $team->getId()]]);
        self::assertSame(1, $created['created']);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['ffbbRencontreId' => FfbbHttpClientStub::RENCONTRE_AMICAL_ID]);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame($team->getId(), $fixture->getTeamId());
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus());
        self::assertSame(FixtureHomeAway::HOME, $fixture->getHomeAway());
        self::assertSame(FfbbHttpClientStub::AMICAL_OPPONENT, $fixture->getOpponentLabel());
        self::assertNull($fixture->getCompetitionId(), 'an unpaired competition = a friendly (null competitionId)');
        self::assertSame(FfbbHttpClientStub::AMICAL_KICKOFF, $fixture->getKickoffTime()?->format('H:i'));
        self::assertSame('GYMNASE STUB', $fixture->getFbiVenueLabel());

        // Re-GET no longer proposes it (tier-0 idempotence), and re-apply creates nothing.
        self::assertNotContains(FfbbHttpClientStub::RENCONTRE_AMICAL_ID, $this->listCreatableIds($token));
        $again = $this->apply($token, [], [['rencontreId' => FfbbHttpClientStub::RENCONTRE_AMICAL_ID, 'teamId' => $team->getId()]]);
        self::assertSame(0, $again['created'], 'a rencontre already created is never re-created');
    }

    public function testApplyReFetchesServerAndIgnoresForgedOrForeignCreations(): void
    {
        [$token, , $clubId] = $this->register('FRE');
        $this->useStubClubCode($clubId);
        $team = $this->createTeam($clubId);
        [, , $otherClubId] = $this->register('FRE2');
        $foreignTeam = $this->createTeam($otherClubId);

        $result = $this->apply($token, [], [
            // A rencontre id absent of the SERVER re-fetch: created from nothing.
            ['rencontreId' => 'forged-does-not-exist', 'teamId' => $team->getId()],
            // A real rencontre but a FOREIGN team (invisible through the filters).
            ['rencontreId' => FfbbHttpClientStub::RENCONTRE_AMICAL_ID, 'teamId' => $foreignTeam->getId()],
        ]);
        self::assertSame(0, $result['created'], 'the server re-fetch ignores forged rencontres and foreign teams');

        $this->scopeGucToClub($clubId);
        self::assertCount(0, $this->em->getRepository(Fixture::class)->findAll(), 'nothing written');
    }

    public function testDeviationOnAPlacedHomeIsDetectedAndTakeFileApplies(): void
    {
        [$token, , $clubId] = $this->register('FRF');
        $this->useStubClubCode($clubId);
        $team = $this->createTeam($clubId);

        // Create the amical, then place it at a DIFFERENT kickoff than the API's.
        $this->apply($token, [], [['rencontreId' => FfbbHttpClientStub::RENCONTRE_AMICAL_ID, 'teamId' => $team->getId()]]);
        $this->scopeGucToClub($clubId);
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['ffbbRencontreId' => FfbbHttpClientStub::RENCONTRE_AMICAL_ID]);
        self::assertInstanceOf(Fixture::class, $fixture);
        $fixture->setStatus(FixtureStatus::PLACED);
        $fixture->setKickoffTime(new DateTimeImmutable('2000-01-01')->setTime(18, 0));
        $this->em->flush();
        $fixtureId = $fixture->getId();

        // GET now reports a kickoff deviation (app 18:00 vs file 20:00).
        $this->client->request('GET', '/api/ffbb/rencontres', [], [], $this->auth($token));
        $deviations = $this->json()['deviations'];
        self::assertCount(1, $deviations);
        self::assertSame($fixtureId, $deviations[0]['fixtureId']);
        self::assertSame(['app' => '18:00', 'file' => FfbbHttpClientStub::AMICAL_KICKOFF], $deviations[0]['fields']['kickoff']);
        self::assertFalse($deviations[0]['persisting'], 'the API never carries a trace → never persisting');

        // take_file adopts the API kickoff, in place (venue kept, PLACED stays).
        $result = $this->apply($token, [['fixtureId' => $fixtureId, 'field' => 'kickoff', 'choice' => 'take_file']], []);
        self::assertSame(1, $result['updated']);
        self::assertSame([], $result['unresolvedDeviations']);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $reloaded = $this->em->getRepository(Fixture::class)->find($fixtureId);
        self::assertSame(FfbbHttpClientStub::AMICAL_KICKOFF, $reloaded?->getKickoffTime()?->format('H:i'));
    }

    public function testFfbbApiIngestionIsNotTheXlsxFreshnessAndDoesNotTouchATrace(): void
    {
        [$token, , $clubId] = $this->register('FRG');
        $this->useStubClubCode($clubId);
        $team = $this->createTeam($clubId);
        $season = $this->seasonOf($clubId);

        // A prior xlsx deposit with a live trace.
        $this->scopeGucToClub($clubId);
        $xlsx = new FbiIngestion($clubId, $season->getId(), FbiIngestionSource::FBI_XLSX, new DateTimeImmutable('-2 days'), 3, 0, 0, 1, [
            ['fixtureId' => '11111111-1111-4111-8111-111111111111', 'field' => 'venue', 'appValue' => 'A', 'fileValue' => 'B', 'decidedAt' => '2026-08-20T10:00:00+00:00'],
        ]);
        $this->em->persist($xlsx);
        $this->em->flush();
        $xlsxId = $xlsx->getId();

        // An API apply that creates a rencontre.
        $this->apply($token, [], [['rencontreId' => FfbbHttpClientStub::RENCONTRE_AMICAL_ID, 'teamId' => $team->getId()]]);

        // Freshness still reads the xlsx deposit (latestXlsx ignores FFBB_API).
        $this->client->request('GET', '/api/fbi-ingestions/latest', [], [], $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('FBI_XLSX', $this->json()['latest']['source'] ?? null, 'the FFBB_API ingestion is not the freshness deposit');

        // The xlsx trace is untouched, and the FFBB_API ingestion carries none.
        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $reloaded = $this->em->getRepository(FbiIngestion::class)->find($xlsxId);
        self::assertNotEmpty($reloaded?->getPendingDeviations(), 'an API apply never kills an xlsx trace');
        $apiIngestion = $this->em->getRepository(FbiIngestion::class)->findOneBy(['source' => FbiIngestionSource::FFBB_API]);
        self::assertSame([], $apiIngestion?->getPendingDeviations(), 'FFBB_API leaves no trace');
    }

    public function testThePartialUniqueIndexRejectsADuplicateRencontre(): void
    {
        [, , $clubId] = $this->register('FRH');
        $team = $this->createTeam($clubId);
        $season = $this->seasonOf($clubId);
        $this->scopeGucToClub($clubId);

        $first = $this->buildFixture($clubId, $season->getId(), $team->getId(), $this->rencontreDate(), 'X', FixtureHomeAway::HOME);
        $first->setFfbbRencontreId('renc-dup');
        $this->em->persist($first);
        $this->em->flush();

        // A concurrent apply materialising the same (team, rencontre) → the DB
        // backstop the controller maps to a clean 409.
        $dup = $this->buildFixture($clubId, $season->getId(), $team->getId(), $this->rencontreDate(), 'Y', FixtureHomeAway::HOME);
        $dup->setFfbbRencontreId('renc-dup');
        $this->em->persist($dup);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testListAndApplyAreManagementGated(): void
    {
        [, , $clubId] = $this->register('FRI');
        $this->useStubClubCode($clubId);
        $this->createTeam($clubId);
        $editorToken = $this->addActiveMember($clubId, 'editor');

        $this->client->request('GET', '/api/ffbb/rencontres', [], [], $this->auth($editorToken));
        self::assertResponseStatusCodeSame(403, 'the rencontre list leaks the club calendar — management only');

        $this->client->request('POST', '/api/ffbb/rencontres/apply', [], [], $this->auth($editorToken) + ['CONTENT_TYPE' => 'application/json'], '{"decisions":[],"creations":[]}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testApplyOnArchivedSeasonReturns409(): void
    {
        [$token, , $clubId] = $this->register('FRJ');
        $this->useStubClubCode($clubId);
        $this->scopeGucToClub($clubId);
        $currentYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->makeSeason($clubId, $currentYear);
        $past = $this->makeSeason($clubId, $currentYear - 1);

        $this->client->request('POST', '/api/ffbb/rencontres/apply', [], [], $this->auth($token) + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], '{"decisions":[],"creations":[]}');
        self::assertResponseStatusCodeSame(409, 'archived-season writes must be refused');
    }

    public function testListWithoutAChosenSocleReturns409(): void
    {
        [$token, , $clubId] = $this->register('FRK');
        $this->useStubClubCode($clubId);
        $this->scopeGucToClub($clubId);
        // A season with NO chosen plan version (socle not in force).
        $unsettled = $this->makeSeason($clubId, SeasonResolver::seasonYear(new DateTimeImmutable('today')));

        $this->client->request('GET', '/api/ffbb/rencontres', [], [], $this->auth($token) + ['HTTP_X-Season-Id' => $unsettled->getId()]);
        self::assertResponseStatusCodeSame(409, 'no match module without a chosen socle');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function creatable(array $body, string $rencontreId): array
    {
        foreach ($body['creatable'] as $entry) {
            if ($entry['rencontreId'] === $rencontreId) {
                return $entry;
            }
        }
        self::fail('creatable ' . $rencontreId . ' not found');
    }

    /** @return list<string> */
    private function listCreatableIds(string $token): array
    {
        $this->client->request('GET', '/api/ffbb/rencontres', [], [], $this->auth($token));
        self::assertResponseIsSuccessful();

        return array_column($this->json()['creatable'], 'rencontreId');
    }

    /**
     * @param list<array{fixtureId: string, field: string, choice: string}> $decisions
     * @param list<array{rencontreId: string, teamId: string}>              $creations
     *
     * @return array<string, mixed>
     */
    private function apply(string $token, array $decisions, array $creations): array
    {
        $this->client->request('POST', '/api/ffbb/rencontres/apply', [], [], $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], json_encode(['decisions' => $decisions, 'creations' => $creations], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** The stub's championship/amical rencontre date: today + 30 days. */
    private function rencontreDate(): DateTimeImmutable
    {
        return new DateTimeImmutable('today')->modify('+30 days');
    }

    private function useStubClubCode(string $clubId): void
    {
        $club = $this->em->getRepository(Club::class)->find($clubId);
        \assert($club instanceof Club);
        $club->setFfbbClubCode(FfbbHttpClientStub::CLUB_CODE);
        $this->em->flush();
    }

    private function seasonOf(string $clubId): Season
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        \assert($season instanceof Season);

        return $season;
    }

    private function pairTeamToStubCompetition(string $clubId, string $seasonId, string $teamId): void
    {
        $this->scopeGucToClub($clubId);
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName('Pré test masculine');
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $competition->setFfbbCompetitionId(FfbbHttpClientStub::COMPETITION_ID);
        $this->em->persist($competition);
        $this->em->flush();
    }

    private function replaceTeamFixtures(string $clubId, string $teamId): void
    {
        $this->scopeGucToClub($clubId);
        foreach ($this->em->getRepository(Fixture::class)->findBy(['teamId' => $teamId]) as $fixture) {
            $this->em->remove($fixture);
        }
        $this->em->flush();
    }

    private function createFixture(string $clubId, string $seasonId, string $teamId, DateTimeImmutable $date, string $opponent, bool $home, FixtureStatus $status, ?string $kickoff): Fixture
    {
        $this->scopeGucToClub($clubId);
        $fixture = $this->buildFixture($clubId, $seasonId, $teamId, $date, $opponent, $home ? FixtureHomeAway::HOME : FixtureHomeAway::AWAY);
        $fixture->setStatus($status);
        if (null !== $kickoff) {
            [$h, $m] = array_map('intval', explode(':', $kickoff));
            $fixture->setKickoffTime(new DateTimeImmutable('2000-01-01')->setTime($h, $m));
        }
        if (FixtureStatus::UNPLACED !== $status && $home) {
            $fixture->setVenueId($this->aVenue($clubId, $seasonId));
        }
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function buildFixture(string $clubId, string $seasonId, string $teamId, DateTimeImmutable $date, string $opponent, FixtureHomeAway $homeAway): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate($date->setTime(0, 0));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel($opponent);

        return $fixture;
    }

    private function aVenue(string $clubId, string $seasonId): string
    {
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName('GYMNASE APP');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    private function makeSeason(string $clubId, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function createTeam(string $clubId): Team
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        if (!$season instanceof Season) {
            $season = $this->makeSeason($clubId, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        }
        if (null === $this->chosenPlanVersion($season)) {
            $this->settleSeasonPlan($season);
        }

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
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('Seniors-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SM-Test');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @return array{0: string, 1: string, 2: string} [token, userId, clubId] */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Rencontres', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        /** @var array{id: string, club: array{id: string}} $me */
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
