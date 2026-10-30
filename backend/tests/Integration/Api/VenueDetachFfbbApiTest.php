<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTravelTime;
use App\Enum\SeasonStatus;
use App\Enum\VenueTravelTimeSource;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Dissocier un gymnase de sa salle FFBB (`externalRef: ""`) le ramène à l'état d'un gymnase qu'on vient
 * de créer : seul le nom reste, numéro fédéral, adresse et GPS sont effacés. Une valeur fournie dans
 * le même PUT l'emporte, ce qui permet au toast « Annuler » d'une association de rendre la position
 * d'avant. Un PUT qui ne mentionne pas `externalRef` ne touche à rien.
 *
 * Décision fondateur : Dissocier efface aussi les temps de trajet du gymnase, saisies à la main
 * comprises, et ceux des autres couples restent. « Annuler » ou une nouvelle adresse les recalculent,
 * même si le gymnase portait les seules lignes du club ; un club sans matrice reste sans matrice.
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

    public function testDetachErasesOnlyTheTravelTimesOfThatVenueWithoutRecomputing(): void
    {
        [$user, $club, $season] = $this->seed();
        $venueId = $this->createAnchoredVenue($user);
        $other = 'bbbbbbbb-0000-4000-8000-000000000001';
        $third = 'bbbbbbbb-0000-4000-8000-000000000002';
        $this->matrixRow($club, $season, $venueId, $other, VenueTravelTimeSource::AUTO);
        $this->matrixRow($club, $season, $venueId, $third, VenueTravelTimeSource::MANUAL);
        $this->matrixRow($club, $season, $other, $third, VenueTravelTimeSource::AUTO);
        $this->resetTransport();

        $this->put($user, $venueId, ['name' => 'Gymnase G', 'externalRef' => '']);

        self::assertCount(0, $this->dispatched(), 'rien à calculer vers une position vide');
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertSame([], $this->timesOf($venueId), 'plus aucun temps pour ce gymnase, saisie manuelle comprise');
        self::assertCount(1, $this->timesOf($other), 'le couple des deux autres gymnases garde son temps');
    }

    public function testRenamingKeepsTheTravelTimes(): void
    {
        [$user, $club, $season] = $this->seed();
        $venueId = $this->createAnchoredVenue($user);
        $this->matrixRow($club, $season, $venueId, 'bbbbbbbb-0000-4000-8000-000000000003', VenueTravelTimeSource::AUTO);

        $this->put($user, $venueId, ['name' => 'Gymnase renommé']);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertCount(1, $this->timesOf($venueId));
    }

    public function testUndoRecomputesEvenWhenTheVenueHeldTheOnlyMatrixRows(): void
    {
        [$user, $club, $season] = $this->seed();
        $venueId = $this->createAnchoredVenue($user);
        $this->matrixRow($club, $season, $venueId, 'bbbbbbbb-0000-4000-8000-000000000004', VenueTravelTimeSource::AUTO);

        $this->put($user, $venueId, ['name' => 'Gymnase G', 'externalRef' => '']);
        $this->resetTransport();
        $this->put($user, $venueId, ['name' => 'Gymnase G'] + self::ANCHOR);

        self::assertCount(1, $this->dispatched(), 'Annuler recalcule les temps effacés');
    }

    public function testNewAddressAfterADetachRecomputes(): void
    {
        [$user, $club, $season] = $this->seed();
        $venueId = $this->createAnchoredVenue($user);
        $this->matrixRow($club, $season, $venueId, 'bbbbbbbb-0000-4000-8000-000000000005', VenueTravelTimeSource::AUTO);

        $this->put($user, $venueId, ['name' => 'Gymnase G', 'externalRef' => '']);
        $this->resetTransport();
        $this->put($user, $venueId, ['name' => 'Gymnase G', 'address' => '2 rue Basse', 'latitude' => '45.1000000', 'longitude' => '4.1000000']);

        self::assertCount(1, $this->dispatched(), 'une nouvelle adresse recalcule les temps effacés');
    }

    public function testClubWithoutMatrixStaysWithoutMatrixAfterDetachAndUndo(): void
    {
        [$user] = $this->seed();
        $venueId = $this->createAnchoredVenue($user);

        $this->put($user, $venueId, ['name' => 'Gymnase G', 'externalRef' => '']);
        $this->resetTransport();
        $this->put($user, $venueId, ['name' => 'Gymnase G'] + self::ANCHOR);

        self::assertCount(0, $this->dispatched(), 'jamais de matrice née d\'un Annuler');
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
        $this->client->request('PUT', '/api/venues/' . $venueId, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['source' => 'manual'] + $payload, \JSON_THROW_ON_ERROR));
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

    private function matrixRow(Club $club, Season $season, string $venueA, string $venueB, VenueTravelTimeSource $source): void
    {
        if (strcasecmp($venueA, $venueB) > 0) {
            [$venueA, $venueB] = [$venueB, $venueA];
        }
        $this->scopeGucToClub($club->getId());
        $row = new VenueTravelTime;
        $row->setClubId($club->getId())->setSeasonId($season->getId())->setVenueAId($venueA)->setVenueBId($venueB);
        $row->setDrivingMinutes(12)->setDrivingSource($source)->setWalkingMinutes(40)->setWalkingSource($source);
        $this->em->persist($row);
        $this->em->flush();
    }

    /**
     * Les lignes de ce gymnase qui portent encore un temps : effacer peut supprimer la ligne ou la
     * vider, seul compte qu'aucun temps ni aucune origine ne survive.
     *
     * @return list<VenueTravelTime>
     */
    private function timesOf(string $venueId): array
    {
        $repository = $this->em->getRepository(VenueTravelTime::class);

        return array_values(array_filter(
            [...$repository->findBy(['venueAId' => $venueId]), ...$repository->findBy(['venueBId' => $venueId])],
            static fn (VenueTravelTime $row): bool => null !== $row->getDrivingMinutes() || null !== $row->getWalkingMinutes() || null !== $row->getDrivingSource() || null !== $row->getWalkingSource(),
        ));
    }

    /** @return list<object> */
    private function dispatched(): array
    {
        $transport = self::getContainer()->get('messenger.transport.travel_in_memory');
        \assert($transport instanceof InMemoryTransport);

        return array_values(array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()));
    }

    private function resetTransport(): void
    {
        $transport = self::getContainer()->get('messenger.transport.travel_in_memory');
        \assert($transport instanceof InMemoryTransport);
        $transport->reset();
    }

    /** @return array{0: User, 1: Club, 2: Season} */
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

        return [$user, $club, $season];
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
