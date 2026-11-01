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

        // A window must end after it starts, same day (P4-61 family).
        $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'startTime' => '22:00', 'endTime' => '22:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // Delete.
        $this->client->request('DELETE', '/api/venue_match_windows/' . $id, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);
    }

    public function testHolidayMatchWindowCreateAndExactlyOneOfDayOrHoliday(): void
    {
        // PR2 « jours fériés » (Q1 option c, VALIDÉ fondateur 2026-10-27) — la 8ᵉ ligne
        // « Jours fériés » de l'éditeur : une fenêtre d'accès sans jour de semaine, qui s'applique
        // à tout jour férié officiel du territoire. Contrat API attendu (forme laissée à NEO —
        // reco MORPHEUS : `dayOfWeek` nullable + booléen `appliesOnPublicHolidays`, exactement
        // l'un des deux) :
        //   - une ligne férié valide (ni dayOfWeek, avec appliesOnPublicHolidays) → 201 ;
        //   - jour de semaine ET férié combinés → 422 avec violation (jamais 422 MUET) ;
        //   - ni l'un ni l'autre → 422 avec violation.
        // ROUGE aujourd'hui : `VenueMatchWindowInput.dayOfWeek` est NotBlank + Range 1-7, et
        // `appliesOnPublicHolidays` n'existe pas → la ligne férié est refusée (422), la combinaison
        // passe (201). NEO rend VERT (champ nullable + drapeau + règle « exactement l'un des deux »
        // via `$this->refuse(...)`, jamais `new ValidationException('chaîne')`).
        [$club, $user, $season] = $this->createClubUser();
        $venue = $this->createVenue($club, $season);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        // 1. Ligne férié valide → 201.
        $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'appliesOnPublicHolidays' => true, 'startTime' => '10:00', 'endTime' => '18:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, 'une ligne « Jours fériés » valide est créée');

        // 2. Jour de semaine ET férié combinés → 422 AVEC violation (jamais muet).
        $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'appliesOnPublicHolidays' => true, 'startTime' => '14:00', 'endTime' => '18:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'jour de semaine ET férié combinés sont refusés');
        self::assertNotEmpty($this->responseData()['violations'] ?? [], 'le 422 porte une violation, jamais muet');

        // 3. Ni jour de semaine NI férié → 422 AVEC violation.
        $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'startTime' => '14:00', 'endTime' => '18:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'ni jour de semaine ni férié sont refusés');
        self::assertNotEmpty($this->responseData()['violations'] ?? [], 'le 422 porte une violation, jamais muet');
    }

    /** D1 : une ligne d'accès peut viser une date précise, avec un motif ; c'est exactement l'un des trois. */
    public function testDatedOpeningCreateAndExactlyOneOfThree(): void
    {
        [$club, $user, $season] = $this->createClubUser();
        $venue = $this->createVenue($club, $season);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];
        $base = ['venueId' => $venue->getId(), 'startTime' => '14:00', 'endTime' => '20:00'];

        $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode($base + ['openingDate' => '2026-11-11', 'label' => 'Coupe'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = $this->responseData();
        self::assertSame('2026-11-11', $created['openingDate'] ?? null);
        self::assertSame('Coupe', $created['label'] ?? null);
        self::assertNull($created['dayOfWeek'] ?? null);
        self::assertFalse($created['appliesOnPublicHolidays'] ?? null);

        $this->client->request('GET', '/api/venue_match_windows/' . $created['id'], [], [], $headers);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('2026-11-11', $this->responseData()['openingDate'] ?? null, 'relue telle quelle');

        foreach (['date + jour' => ['openingDate' => '2026-11-11', 'dayOfWeek' => 3], 'date + férié' => ['openingDate' => '2026-11-11', 'appliesOnPublicHolidays' => true]] as $case => $extra) {
            $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode($base + $extra, \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame('Une ligne d\'accès match vise soit un jour de la semaine, soit les jours fériés, soit une date précise — exactement l\'un des trois.', $this->responseData()['violations'][0]['message'] ?? null, $case);
        }

        foreach (['date mal formée' => ['openingDate' => '11/11/2026'], 'motif de 121 caractères' => ['openingDate' => '2026-11-11', 'label' => str_repeat('x', 121)]] as $case => $extra) {
            $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode($base + $extra, \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, $case);
        }

        $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode(['openingDate' => '2026-11-11', 'startTime' => '20:00', 'endTime' => '14:00', 'venueId' => $venue->getId()], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'une plage inversée reste refusée pour une ouverture');
    }

    /** D11 + A4 : passée, chevauchante ou hors saison, une ouverture est acceptée ; l'avertissement hors saison vit à l'écran. */
    public function testPastOverlappingAndOutOfSeasonOpeningsAreAccepted(): void
    {
        [$club, $user, $season] = $this->createClubUser();
        $venue = $this->createVenue($club, $season);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];
        $post = function (string $date, string $start, string $end) use ($headers, $venue): void {
            $this->client->request('POST', '/api/venue_match_windows', [], [], $headers, json_encode(['venueId' => $venue->getId(), 'openingDate' => $date, 'startTime' => $start, 'endTime' => $end], \JSON_THROW_ON_ERROR));
        };

        $post($season->getStartDate()->modify('+1 day')->format('Y-m-d'), '14:00', '20:00');
        self::assertResponseStatusCodeSame(201, 'date passée');
        $post('2026-11-11', '14:00', '20:00');
        self::assertResponseStatusCodeSame(201);
        $post('2026-11-11', '15:00', '21:00');
        self::assertResponseStatusCodeSame(201, 'chevauchement : l\'union suffit');
        $post($season->getEndDate()->modify('+10 days')->format('Y-m-d'), '14:00', '20:00');
        self::assertResponseStatusCodeSame(201, 'hors saison');
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
