<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\FixtureHomeAway;
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
 * Capacity layer HTTP contract (P1-4 PR B): venue match windows + venue
 * unavailabilities CRUD with their business validations, and the
 * alert-only impact feed.
 */
#[Group('integration')]
final class VenueCapacityApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testMatchWindowCrudAndDayValidation(): void
    {
        [$club, $user, $season] = $this->createClubUser();
        $venue = $this->createVenue($club, $season);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        // Create.
        $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'startTime' => '14:00', 'endTime' => '22:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->responseData()['id'];

        // Filterable by venue.
        $this->client->request('GET', '/api/venue_match_windows?venueId=' . $venue->getId(), [], [], $this->authHeaders($user));
        self::assertCount(1, $this->responseData()['member'] ?? []);

        // Update.
        $this->client->request('PUT', '/api/venue_match_windows/' . $id, [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 7, 'startTime' => '09:00', 'endTime' => '18:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(7, $this->responseData()['dayOfWeek']);

        // A window must end after it starts, same day (P4-61 family).
        $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'startTime' => '22:00', 'endTime' => '22:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // Delete.
        $this->client->request('DELETE', '/api/venue_match_windows/' . $id, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);
    }

    public function testUnavailabilityValidationAndLabelTrim(): void
    {
        [$club, $user, $season] = $this->createClubUser();
        $venue = $this->createVenue($club, $season);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/venue_unavailabilities', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'startDate' => '2027-02-04', 'endDate' => '2027-02-28', 'label' => '  travaux  ',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('travaux', $this->responseData()['label']);

        // End before start refused.
        $this->client->request('POST', '/api/venue_unavailabilities', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'startDate' => '2027-02-28', 'endDate' => '2027-02-04',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testImpactFeedListsAffectedPlacedMatches(): void
    {
        [$club, $user, $season] = $this->createClubUser();
        $venue = $this->createVenue($club, $season);

        // One placed match inside the range, one outside, one on another venue-less date.
        $this->createFixture($club, $season, $venue->getId(), '2027-02-14');
        $this->createFixture($club, $season, $venue->getId(), '2027-03-06');
        $this->createFixture($club, $season, null, '2027-02-14');

        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];
        $this->client->request('POST', '/api/venue_unavailabilities', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'startDate' => '2027-02-04', 'endDate' => '2027-02-28', 'label' => 'travaux',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/venue-unavailability-impact', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $items = $this->responseData()['items'] ?? [];
        self::assertCount(1, $items);
        self::assertSame('travaux', $items[0]['label']);
        self::assertCount(1, $items[0]['affectedFixtures']);
        self::assertSame('2027-02-14', $items[0]['affectedFixtures'][0]['matchDate']);
        // The settled test plan has no slot at this venue → no phantom count.
        self::assertSame(0, $items[0]['trainingOccurrences']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club capa ' . $uid);
        $club->setSlug('club-capa-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 13)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('capa' . $uid . '@test.com');
        $user->setFirstName('Capa');
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
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->settleSeasonPlan($season);

        return [$club, $user, $season];
    }

    private function createVenue(Club $club, Season $season): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Gymnase Armand');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createFixture(Club $club, Season $season, ?string $venueId, string $date): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setVenueId($venueId);
        if (null !== $venueId) {
            $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        }
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
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
