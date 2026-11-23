<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Place;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-53 RMM-8 (PR-1) — CRUD du barème de trajet entre deux gymnases : le couple est
 * NORMALISÉ (venueAId < venueBId), UNIQUE, une valeur saisie pose MANUAL, la lecture
 * est ouverte au Membre, et les refus de saisie sont NOMMÉS (422 gymnase
 * étranger/identique/hors bornes, doublon de paire). Lot 3, PR 5 : la valeur vit au club, entre deux lieux ;
 * chaque saison la sert rangée selon ses propres gymnases, et une saison archivée ne l'écrit pas.
 */
#[Group('integration')]
final class VenueTravelTimeApiTest extends WebTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCreatesNormalizesTheCoupleAndPosesManualSource(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $a = $this->createVenue($club, $season, 'Coubertin');
        $b = $this->createVenue($club, $season, 'Debarros');
        // On envoie le couple À L'ENVERS (le plus grand id en A) : le processor doit normaliser.
        [$hi, $lo] = strcasecmp($a->getId(), $b->getId()) > 0 ? [$a->getId(), $b->getId()] : [$b->getId(), $a->getId()];

        $this->client->request('POST', '/api/venue_travel_times', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueAId' => $hi, 'venueBId' => $lo, 'drivingMinutes' => 15,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $data = $this->responseData();

        self::assertSame($lo, $data['venueAId'], 'venueAId < venueBId (normalisé)');
        self::assertSame($hi, $data['venueBId']);
        self::assertSame(15, $data['drivingMinutes']);
        self::assertSame('MANUAL', $data['drivingSource'], 'une valeur saisie ICI pose MANUAL');
        self::assertNull($data['walkingMinutes'], 'le mode non saisi reste null');
        self::assertNull($data['walkingSource']);
    }

    public function testDuplicateCoupleIsRefused(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $a = $this->createVenue($club, $season, 'Coubertin');
        $b = $this->createVenue($club, $season, 'Debarros');
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/venue_travel_times', [], [], $headers, json_encode(['venueAId' => $a->getId(), 'venueBId' => $b->getId(), 'drivingMinutes' => 12], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Même couple dans l'AUTRE sens → 422 (le doublon normalisé).
        $this->client->request('POST', '/api/venue_travel_times', [], [], $headers, json_encode(['venueAId' => $b->getId(), 'venueBId' => $a->getId(), 'drivingMinutes' => 20], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testIdenticalVenuesAreRefused(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $a = $this->createVenue($club, $season, 'Coubertin');

        $this->client->request('POST', '/api/venue_travel_times', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueAId' => $a->getId(), 'venueBId' => $a->getId(), 'drivingMinutes' => 10,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testForeignVenueIsRefused(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $a = $this->createVenue($club, $season, 'Coubertin');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $foreign = $this->createVenue($clubB, $seasonB, 'Étranger');

        $this->client->request('POST', '/api/venue_travel_times', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueAId' => $a->getId(), 'venueBId' => $foreign->getId(), 'drivingMinutes' => 10,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'un gymnase d\'un autre club est invisible → 422');
    }

    public function testOutOfBoundsMinutesAreRefused(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $a = $this->createVenue($club, $season, 'Coubertin');
        $b = $this->createVenue($club, $season, 'Debarros');
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/venue_travel_times', [], [], $headers, json_encode(['venueAId' => $a->getId(), 'venueBId' => $b->getId(), 'drivingMinutes' => 0], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, '0 minute est hors bornes (1..240)');

        $this->client->request('POST', '/api/venue_travel_times', [], [], $headers, json_encode(['venueAId' => $a->getId(), 'venueBId' => $b->getId(), 'walkingMinutes' => 999], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, '999 minutes est hors bornes (1..240)');
    }

    public function testReadIsOpenToANonManagementMember(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $a = $this->createVenue($club, $season, 'Coubertin');
        $b = $this->createVenue($club, $season, 'Debarros');
        $this->client->request('POST', '/api/venue_travel_times', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueAId' => $a->getId(), 'venueBId' => $b->getId(), 'drivingMinutes' => 15,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $member = $this->createMember($club, 'member');
        $this->client->request('GET', '/api/venue_travel_times', [], [], $this->authHeaders($member));
        self::assertResponseIsSuccessful();
        $rows = $this->responseData()['member'] ?? [];
        self::assertCount(1, $rows, 'la lecture reste ouverte au Membre non-management');
    }

    /**
     * Lot 3, PR 5 : un temps de trajet vit une fois, au club, entre deux LIEUX ; chaque saison le sert rangé selon
     * SES gymnases (plus petit identifiant d'abord), même quand ce rangement s'inverse d'une saison à l'autre. Un
     * temps dont un lieu n'a pas de gymnase dans la saison demandée n'y est pas servi.
     */
    public function testEachSeasonServesTheClubTravelTimeOrderedByItsOwnVenues(): void
    {
        [$club, $user, $current] = $this->createClubUser('a');
        $next = $this->addSeason($club, 1);
        // Lieu de Nord < lieu de Sud, fixés : en base le couple se range Nord d'abord, quoi qu'il arrive.
        $placeNord = $this->place($club, '00000000-0000-4000-8000-' . substr(bin2hex(random_bytes(6)), 0, 12));
        $placeSud = $this->place($club, 'ffffffff-0000-4000-8000-' . substr(bin2hex(random_bytes(6)), 0, 12));
        $placeEst = $this->place($club);
        // Saison en cours : Nord < Sud. Saison suivante : mêmes lieux, mais Sud < Nord (le couple s'y range à l'envers
        // de son rangement en base : seul un rangement par les gymnases de la saison le sert Sud d'abord).
        [$lo, $hi] = $this->orderedIds();
        $nord = $this->venueOn($placeNord, $club, $current, $lo, 'Nord');
        $sud = $this->venueOn($placeSud, $club, $current, $hi, 'Sud');
        $est = $this->venueOn($placeEst, $club, $current, $this->uuid(), 'Est');
        [$nextLo, $nextHi] = $this->orderedIds();
        $nextSud = $this->venueOn($placeSud, $club, $next, $nextLo, 'Sud');
        $nextNord = $this->venueOn($placeNord, $club, $next, $nextHi, 'Nord');

        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Season-Id' => $current->getId()];
        $this->client->request('POST', '/api/venue_travel_times', [], [], $headers, json_encode(['venueAId' => $nord->getId(), 'venueBId' => $sud->getId(), 'drivingMinutes' => 15], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = $this->responseData();
        // Est n'a de gymnase que dans la saison en cours : ce couple-là ne doit pas paraître dans la suivante.
        $this->client->request('POST', '/api/venue_travel_times', [], [], $headers, json_encode(['venueAId' => $nord->getId(), 'venueBId' => $est->getId(), 'drivingMinutes' => 8], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/venue_travel_times', [], [], $this->authHeaders($user) + ['HTTP_X-Season-Id' => $next->getId()]);
        self::assertResponseIsSuccessful();
        $rows = $this->responseData()['member'] ?? [];
        self::assertIsArray($rows);
        self::assertCount(1, $rows, 'la saison suivante ne sert que le couple dont les deux lieux y ont un gymnase');
        self::assertSame(
            ['id' => $created['id'], 'venueAId' => $nextSud->getId(), 'venueBId' => $nextNord->getId(), 'drivingMinutes' => 15],
            ['id' => $rows[0]['id'] ?? null, 'venueAId' => $rows[0]['venueAId'] ?? null, 'venueBId' => $rows[0]['venueBId'] ?? null, 'drivingMinutes' => $rows[0]['drivingMinutes'] ?? null],
            'la même valeur, sans recopie, rangée selon les gymnases de la saison suivante (Sud d\'abord)',
        );
        self::assertSame([$nord->getId(), $sud->getId()], [$created['venueAId'] ?? null, $created['venueBId'] ?? null], 'témoin : la saison en cours la range Nord d\'abord');
    }

    /**
     * Lot 3, PR 5 (abus) : un temps de trajet ne s'écrit jamais depuis une saison archivée, ni en création ni en
     * correction, même si les deux lieux ont un gymnase dans une saison ouverte. Témoin : la même écriture depuis
     * la saison en cours passe.
     */
    public function testATravelTimeIsNeverWrittenFromAnArchivedSeason(): void
    {
        [$club, $user, $current] = $this->createClubUser('a');
        $past = $this->addSeason($club, -1);
        [$placeA, $placeB] = [$this->place($club), $this->place($club)];
        $a = $this->venueOn($placeA, $club, $current, $this->uuid(), 'Coubertin');
        $b = $this->venueOn($placeB, $club, $current, $this->uuid(), 'Debarros');
        $pastA = $this->venueOn($placeA, $club, $past, $this->uuid(), 'Coubertin');
        $pastB = $this->venueOn($placeB, $club, $past, $this->uuid(), 'Debarros');
        $json = ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/venue_travel_times', [], [], $this->authHeaders($user) + $json + ['HTTP_X-Season-Id' => $past->getId()], json_encode(['venueAId' => $pastA->getId(), 'venueBId' => $pastB->getId(), 'drivingMinutes' => 10], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409, 'créer un temps de trajet depuis une saison archivée est refusé');

        $this->client->request('POST', '/api/venue_travel_times', [], [], $this->authHeaders($user) + $json + ['HTTP_X-Season-Id' => $current->getId()], json_encode(['venueAId' => $a->getId(), 'venueBId' => $b->getId(), 'drivingMinutes' => 12], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, 'témoin : la même création depuis la saison en cours passe');
        $id = $this->responseData()['id'] ?? null;
        self::assertIsString($id);

        $this->client->request('PUT', '/api/venue_travel_times/' . $id, [], [], $this->authHeaders($user) + $json + ['HTTP_X-Season-Id' => $past->getId()], json_encode(['venueAId' => $pastA->getId(), 'venueBId' => $pastB->getId(), 'drivingMinutes' => 40], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409, 'corriger un temps de trajet depuis une saison archivée est refusé');

        $this->client->request('GET', '/api/venue_travel_times/' . $id, [], [], $this->authHeaders($user) + ['HTTP_X-Season-Id' => $current->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(12, $this->responseData()['drivingMinutes'] ?? null, 'la valeur n\'a pas bougé');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Une saison de plus du club, décalée de `$offset` ans de la saison en cours (−1 : archivée, +1 : la suivante). */
    private function addSeason(Club $club, int $offset): Season
    {
        $this->scopeGucToClub($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today')) + $offset;
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus($offset > 0 ? SeasonStatus::DRAFT : SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    /** Un lieu du club, à l'identifiant choisi quand le rangement du couple en base doit être fixé. */
    private function place(Club $club, ?string $id = null): Place
    {
        $this->scopeGucToClub($club->getId());
        $place = (new Place)->setClubId($club->getId());
        if (null !== $id) {
            new ReflectionProperty(Place::class, 'id')->setValue($place, $id);
        }
        $this->em->persist($place);
        $this->em->flush();

        return $place;
    }

    /** Le gymnase d'une saison sur un lieu donné, à l'identifiant choisi (pour fixer le rangement du couple). */
    private function venueOn(Place $place, Club $club, Season $season, string $id, string $name): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue($place)->setId($id)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /** @return array{0: string, 1: string} deux identifiants neufs, le plus petit d'abord */
    private function orderedIds(): array
    {
        $ids = [$this->uuid(), $this->uuid()];
        sort($ids, \SORT_STRING);

        return [$ids[0], $ids[1]];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function createVenue(Club $club, Season $season, string $name): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = $this->newVenue($club->getId(), $season->getId(), $name);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createMember(Club $club, string $role): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User;
        $user->setEmail($role . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club trajet ' . $suffix);
        $club->setSlug('club-trajet-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('trajet' . $uid . '@test.com');
        $user->setFirstName('Trajet');
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
        $season->setStartDate(new DateTimeImmutable(SeasonResolver::seasonYear(new DateTimeImmutable('today')) . '-08-01'));
        $season->setEndDate(new DateTimeImmutable((SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

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
