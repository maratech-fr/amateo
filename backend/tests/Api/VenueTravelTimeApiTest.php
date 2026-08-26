<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-53 RMM-8 (PR-1) — CRUD du barème de trajet entre deux gymnases : le couple est
 * NORMALISÉ (venueAId < venueBId), UNIQUE, une valeur saisie pose MANUAL, la lecture
 * est ouverte au Membre, et les refus de saisie sont NOMMÉS (422 gymnase
 * étranger/identique/hors bornes, doublon de paire).
 */
#[Group('integration')]
final class VenueTravelTimeApiTest extends WebTestCase
{
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

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createVenue(Club $club, Season $season, string $name): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
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
