<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

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
 * Dissocier un gymnase de sa salle FFBB (`externalRef: ""`) le ramène à l'état d'un gymnase qu'on vient
 * de créer : seul le nom reste, numéro fédéral, adresse et GPS sont effacés. Une valeur fournie dans
 * le même PUT l'emporte, ce qui permet au toast « Annuler » d'une association de rendre la position
 * d'avant. Un PUT qui ne mentionne pas `externalRef` ne touche à rien.
 *
 * Le geste d'écran est prouvé par `frontend/src/features/wizard/steps/VenuesStep.ffbbAssociation.test.tsx`.
 */
#[Group('integration')]
final class VenueDetachFfbbApiTest extends WebTestCase
{
    use TenantGucTrait;

    private const array ANCHOR = ['externalRef' => '166900042', 'address' => '10 rue du Stade', 'latitude' => '45.7000000', 'longitude' => '4.8000000'];

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testEmptyExternalRefLeavesOnlyTheName(): void
    {
        [$user, $club] = $this->seed();
        $venueId = $this->createAnchoredVenue($user);

        $this->put($user, $venueId, ['name' => 'Gymnase G', 'externalRef' => '']);

        $venue = $this->reload($club, $venueId);
        self::assertSame('Gymnase G', $venue->getName());
        self::assertNull($venue->getExternalRef());
        self::assertNull($venue->getAddress());
        self::assertNull($venue->getLatitude());
        self::assertNull($venue->getLongitude());
    }

    public function testValuesProvidedInTheSamePutWinOverTheClearing(): void
    {
        [$user, $club] = $this->seed();
        $venueId = $this->createAnchoredVenue($user);

        $this->put($user, $venueId, ['name' => 'Gymnase G', 'externalRef' => '', 'address' => '2 rue Basse', 'latitude' => '45.1000000', 'longitude' => '4.1000000']);

        $venue = $this->reload($club, $venueId);
        self::assertNull($venue->getExternalRef());
        self::assertSame('2 rue Basse', $venue->getAddress());
        self::assertSame('45.1000000', $venue->getLatitude());
        self::assertSame('4.1000000', $venue->getLongitude());
    }

    public function testUndoRestoresTheAnchorAfterADetach(): void
    {
        [$user, $club] = $this->seed();
        $venueId = $this->createAnchoredVenue($user);

        $this->put($user, $venueId, ['name' => 'Gymnase G', 'externalRef' => '']);
        $this->put($user, $venueId, ['name' => 'Gymnase G'] + self::ANCHOR);

        $venue = $this->reload($club, $venueId);
        self::assertSame(self::ANCHOR['externalRef'], $venue->getExternalRef());
        self::assertSame(self::ANCHOR['address'], $venue->getAddress());
        self::assertSame(self::ANCHOR['latitude'], $venue->getLatitude());
        self::assertSame(self::ANCHOR['longitude'], $venue->getLongitude());
    }

    public function testPutWithoutExternalRefKeepsTheAnchor(): void
    {
        [$user, $club] = $this->seed();
        $venueId = $this->createAnchoredVenue($user);

        $this->put($user, $venueId, ['name' => 'Gymnase renommé']);

        $venue = $this->reload($club, $venueId);
        self::assertSame('Gymnase renommé', $venue->getName());
        self::assertSame(self::ANCHOR['externalRef'], $venue->getExternalRef());
        self::assertSame(self::ANCHOR['address'], $venue->getAddress());
        self::assertSame(self::ANCHOR['latitude'], $venue->getLatitude());
        self::assertSame(self::ANCHOR['longitude'], $venue->getLongitude());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createAnchoredVenue(User $user): string
    {
        $this->client->request('POST', '/api/venues', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Gymnase G', 'source' => 'manual'] + self::ANCHOR, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        /** @var array{id: string} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['id'];
    }

    /**
     * @param array<string, string> $payload
     */
    private function put(User $user, string $venueId, array $payload): void
    {
        $this->client->request('PUT', '/api/venues/' . $venueId, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    private function reload(Club $club, string $venueId): Venue
    {
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $venue = $this->em->getRepository(Venue::class)->find($venueId);
        self::assertInstanceOf(Venue::class, $venue);

        return $venue;
    }

    /** @return array{0: User, 1: Club} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Detach Club');
        $club->setSlug('detach-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('DTC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('detach-' . $uid . '@test.com');
        $user->setFirstName('D');
        $user->setLastName('T');
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
        $season->setName($year . '-' . ($year + 1));
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club];
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
