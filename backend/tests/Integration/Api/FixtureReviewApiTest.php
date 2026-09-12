<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PR-3a — the « Importer » review endpoints: mark rencontres treated (by line or
 * in bulk) and resolve one pending deviation. Tested end-to-end over HTTP.
 */
#[Group('integration')]
final class FixtureReviewApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testLineReviewClearsPendingAndMarksReviewed(): void
    {
        [$club, $user, $season] = $this->createClubUser('rl');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->deviatedFixture($club, $season, $team, '2026-10-04', '2026-10-11');

        $this->post($user, '/api/fixtures/review', ['fixtureIds' => [$fixture->getId()]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->responseData()['reviewed']);
        self::assertSame([], $this->responseData()['skipped']);

        $row = $this->reload($club, $fixture->getId());
        self::assertSame('REVIEWED', $row['review_state']);
        self::assertSame('[]', (string) $row['pending_deviations'], 'the line gesture clears the écart (keep-app implicit)');
        self::assertSame('2026-10-04', substr((string) $row['match_date'], 0, 10), 'keeping the app value never writes the source');
    }

    public function testLineReviewAcknowledgesAnAutoAppliedDeviationOnAnAlreadyReviewedFixture(): void
    {
        // P4-199 — une rencontre DÉJÀ traitée (REVIEWED) portant une entrée
        // `autoApplied` (la source a imposé une valeur hors périmètre : le bandeau
        // « Pris en compte ») : le geste ligne vide l'écart, elle reste traitée.
        [$club, $user, $season] = $this->createClubUser('ra');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->autoAppliedReviewedFixture($club, $season, $team, '2026-10-04', '2026-10-11');

        $this->post($user, '/api/fixtures/review', ['fixtureIds' => [$fixture->getId()]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->responseData()['reviewed']);

        $row = $this->reload($club, $fixture->getId());
        self::assertSame('REVIEWED', $row['review_state'], 'elle reste traitée');
        self::assertSame('[]', (string) $row['pending_deviations'], '« Pris en compte » vide l\'entrée autoApplied');
    }

    public function testBulkReviewSkipsFixturesStillCarryingPendingDeviations(): void
    {
        [$club, $user, $season] = $this->createClubUser('rb');
        $team = $this->createTeam($club, $season, 'SF3');
        $clean = $this->plainFixture($club, $season, $team, '2026-10-04');
        $deviated = $this->deviatedFixture($club, $season, $team, '2026-10-05', '2026-10-12');

        $this->post($user, '/api/fixtures/review', ['teamId' => $team->getId()]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame(1, $data['reviewed'], 'only the clean fixture is treated in bulk');
        self::assertSame([['fixtureId' => $deviated->getId(), 'reason' => 'pending_deviations']], $data['skipped']);

        self::assertSame('REVIEWED', $this->reload($club, $clean->getId())['review_state']);
        self::assertSame('OUT_OF_SYNC', $this->reload($club, $deviated->getId())['review_state'], 'the deviated one is left untouched');
    }

    public function testReviewRequiresExactlyOneGesture(): void
    {
        [$club, $user, $season] = $this->createClubUser('rx');
        $team = $this->createTeam($club, $season, 'SF3');

        $this->post($user, '/api/fixtures/review', []);
        self::assertResponseStatusCodeSame(422);

        $this->post($user, '/api/fixtures/review', ['fixtureIds' => ['x'], 'teamId' => $team->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMoreThanFiveHundredFixtureIdsIsRefusedAndNamesTheTeamGesture(): void
    {
        [, $user] = $this->createClubUser('rcap');

        $ids = array_map(static fn (int $i): string => \sprintf('forged-%d', $i), range(1, 501));
        $this->post($user, '/api/fixtures/review', ['fixtureIds' => $ids]);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertStringContainsString('geste par équipe', (string) $body['error']);
    }

    public function testTakeSourceOnDateUnplacesAndReviews(): void
    {
        [$club, $user, $season] = $this->createClubUser('rt');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->deviatedFixture($club, $season, $team, '2026-10-04', '2026-10-11');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'date',
            'choice' => 'take_source',
        ]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame('REVIEWED', $data['reviewState']);
        self::assertSame([], $data['pendingDeviations']);

        $row = $this->reload($club, $fixture->getId());
        self::assertSame('2026-10-11', substr((string) $row['match_date'], 0, 10), 'take_source adopted the persisted source date');
        self::assertSame('UNPLACED', $row['status'], 'a date take_source un-places the match');
        self::assertSame('REVIEWED', $row['review_state']);
    }

    public function testKeepAppRemovesTheDeviationWithoutWritingTheSource(): void
    {
        [$club, $user, $season] = $this->createClubUser('rk');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->deviatedFixture($club, $season, $team, '2026-10-04', '2026-10-11');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'date',
            'choice' => 'keep_app',
        ]);
        self::assertResponseStatusCodeSame(200);

        $row = $this->reload($club, $fixture->getId());
        self::assertSame('2026-10-04', substr((string) $row['match_date'], 0, 10));
        self::assertSame('REVIEWED', $row['review_state']);
    }

    public function testResolvingAFieldWithoutAPendingDeviationIs422(): void
    {
        [$club, $user, $season] = $this->createClubUser('rn');
        $team = $this->createTeam($club, $season, 'SF3');
        $fixture = $this->plainFixture($club, $season, $team, '2026-10-04');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => $fixture->getId(),
            'field' => 'date',
            'choice' => 'keep_app',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownFixtureIs404(): void
    {
        [, $user] = $this->createClubUser('ru');

        $this->post($user, '/api/fixtures/review/deviations', [
            'fixtureId' => '11111111-1111-4111-8111-111111111111',
            'field' => 'date',
            'choice' => 'keep_app',
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function post(User $user, string $url, mixed $body): void
    {
        $this->client->request('POST', $url, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    private function plainFixture(Club $club, Season $season, Team $team, string $date): Fixture
    {
        $this->scopeGucToClub($club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setVenueId('22222222-2222-4222-8222-222222222222');
        $fixture->setKickoffTime(new DateTimeImmutable('15:30'));
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function deviatedFixture(Club $club, Season $season, Team $team, string $appDate, string $sourceDate): Fixture
    {
        $fixture = $this->plainFixture($club, $season, $team, $appDate);
        $this->scopeGucToClub($club->getId());
        $fixture->putPendingDeviation([
            'field' => 'date',
            'appValue' => $appDate,
            'sourceValue' => $sourceDate,
            'channel' => 'FBI_XLSX',
            'seenAt' => '2026-09-01T10:00:00+00:00',
            'autoApplied' => false,
        ]);
        $fixture->setReviewState(FixtureReviewState::OUT_OF_SYNC);
        $this->em->flush();

        return $fixture;
    }

    private function autoAppliedReviewedFixture(Club $club, Season $season, Team $team, string $appDate, string $sourceDate): Fixture
    {
        // Placée → REVIEWED (setStatus PLACED sans écart traite la rencontre), puis
        // une entrée autoApplied déposée SANS repasser OUT_OF_SYNC — l'état exact
        // d'un extérieur / domicile in-window dont la source a fait foi (P4-199).
        $fixture = $this->plainFixture($club, $season, $team, $appDate);
        $this->scopeGucToClub($club->getId());
        $fixture->putPendingDeviation([
            'field' => 'date',
            'appValue' => $appDate,
            'sourceValue' => $sourceDate,
            'channel' => 'FBI_XLSX',
            'seenAt' => '2026-09-01T10:00:00+00:00',
            'autoApplied' => true,
        ]);
        $fixture->markReviewed(new DateTimeImmutable);
        $this->em->flush();

        return $fixture;
    }

    /** @return array<string, mixed> */
    private function reload(Club $club, string $fixtureId): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($club->getId());
        $row = $em->getConnection()->fetchAssociative(
            'SELECT status, review_state, match_date, pending_deviations FROM fixture WHERE id = ?',
            [$fixtureId],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function createTeam(Club $club, Season $season, string $name): Team
    {
        $this->scopeGucToClub($club->getId());
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
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club review ' . $suffix);
        $club->setSlug('club-review-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('review' . $uid . '@test.com');
        $user->setFirstName('Review');
        $user->setLastName('User');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        // Matches require a settled season plan (cockpit state 3 — SocleGuard).
        $this->settleSeasonPlan($season);

        return [$club, $user, $season];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
