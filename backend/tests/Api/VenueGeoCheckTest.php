<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Service\Geo\VenueGeoCheck;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/venues/geo-check (FFBB + BAN stubbés, jamais le live) : le contrôle de cohérence
 * de position sert au gestionnaire les gymnases dont le point stocké paraît incohérent avec
 * l'adresse fédérale — il se ferme au non-management (403) et n'expose JAMAIS le gymnase d'un
 * autre club (isolation tenant : le tableau d'un club ne cite que les siens).
 */
#[Group('integration')]
final class VenueGeoCheckTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAManagerGetsTheAlertForAMisplacedGym(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        // Salle FFBB 900000001 (stub _geoRadius) → adresse « 1 rue Près », commune « Testville ».
        // Le point stocké est à la salle, mais le forward BAN stubbé place l'adresse exacte
        // (housenumber) très loin → une alerte FAR_FROM_ADDRESS.
        $venue = $this->ffbbVenue($club, $season, 'Gymnase A', '900000001', '45.0010000', '4.0010000');

        $this->client->request('GET', '/api/venues/geo-check', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();

        $alerts = $this->responseArray();
        self::assertCount(1, $alerts, 'un gymnase mal placé remonte une alerte');
        $alert = $alerts[0];
        self::assertSame($venue->getId(), $alert['venueId']);
        self::assertSame(VenueGeoCheck::REASON_FAR_FROM_ADDRESS, $alert['reason']);
        self::assertSame('1 rue Près', $alert['ffbbAddress']);
        self::assertArrayHasKey('pointStreet', $alert);
        self::assertNull($alert['pointStreet']);
        self::assertIsInt($alert['distanceM']);
        self::assertGreaterThan(300, $alert['distanceM']);
    }

    public function testAGymWithoutFfbbAnchorOrCoordinatesRaisesNothing(): void
    {
        [$club, $user, $season] = $this->createClubUser('nil');
        // Ancré FFBB mais sans coordonnées → jamais vérifié ; géolocalisé mais sans externalRef → idem.
        $this->ffbbVenue($club, $season, 'Sans coord', '900000001', null, null);
        $this->ffbbVenue($club, $season, 'Sans ancre', null, '45.0010000', '4.0010000');

        $this->client->request('GET', '/api/venues/geo-check', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->responseArray(), 'sans ancre FFBB ou sans coordonnées, aucune alerte');
    }

    public function testAnotherClubNeverSeesTheGym(): void
    {
        // Club A a le gymnase mal placé ; le gestionnaire du club B ne doit voir que les siens (aucun).
        [$clubA, , $seasonA] = $this->createClubUser('owner');
        $this->ffbbVenue($clubA, $seasonA, 'Gymnase A', '900000001', '45.0010000', '4.0010000');

        [, $userB] = $this->createClubUser('other');

        $this->client->request('GET', '/api/venues/geo-check', [], [], $this->authHeaders($userB));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->responseArray(), 'un club ne voit jamais le gymnase d\'un autre club');
    }

    public function testItRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/venues/geo-check');
        self::assertResponseStatusCodeSame(401, 'sans session, aucune donnée');
    }

    public function testItIsManagementOnly(): void
    {
        [$club] = $this->createClubUser('gate');
        $member = $this->createMember($club, 'member');

        $this->client->request('GET', '/api/venues/geo-check', [], [], $this->authHeaders($member));
        self::assertResponseStatusCodeSame(403, 'le contrôle de position est management-only');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function ffbbVenue(Club $club, Season $season, string $name, ?string $externalRef, ?string $latitude, ?string $longitude): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $venue->setExternalRef($externalRef);
        $venue->setLatitude($latitude);
        $venue->setLongitude($longitude);
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
        $club->setName('Club geocheck ' . $suffix);
        $club->setSlug('club-geocheck-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('geocheck' . $uid . '@test.com');
        $user->setFirstName('Geo');
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

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
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

    /**
     * @return list<array<string, mixed>>
     */
    private function responseArray(): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
