<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\VenueTravelTime;
use App\Enum\SeasonStatus;
use App\Enum\TravelComputeScope;
use App\Message\ComputeTravelTimesMessage;
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
 * Lot G — déplacer un gymnase (changer sa position) relance le calcul ASYNCHRONE de la matrice de
 * trajet, MAIS seulement si le club a DÉJÀ une matrice (opt-in). Coordonnées inchangées, aucun
 * champ géo touché, ou aucune matrice ⇒ rien n'est dispatché. La matrice d'un AUTRE club ne compte
 * pas. Le message est inspectable sur le transport `travel_in_memory` (config test).
 */
#[Group('integration')]
final class VenueGeoChangeTravelRecomputeTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testMovingAVenueWithAMatrixDispatchesARecompute(): void
    {
        [$user, $club, $season] = $this->seed();
        $venueId = $this->createVenue($user, '45.7000000', '4.8000000');
        $this->matrixRow($club, $season, $venueId, 'bbbbbbbb-0000-4000-8000-000000000001');
        $this->reset();

        $this->put($user, $venueId, '45.7654321', '4.8765432');

        $messages = $this->dispatched();
        self::assertCount(1, $messages, 'une position changée + une matrice existante relance le calcul');
        self::assertInstanceOf(ComputeTravelTimesMessage::class, $messages[0]);
        self::assertSame(TravelComputeScope::VENUE_MATRIX, $messages[0]->getScope());
        self::assertSame($club->getId(), $messages[0]->getClubId());
    }

    public function testIdenticalCoordinatesDispatchNothing(): void
    {
        [$user, $club, $season] = $this->seed();
        $venueId = $this->createVenue($user, '45.7000000', '4.8000000');
        $this->matrixRow($club, $season, $venueId, 'bbbbbbbb-0000-4000-8000-000000000002');
        $this->reset();

        // Mêmes coordonnées (seul le nom change) : aucune vraie position modifiée.
        $this->put($user, $venueId, '45.7000000', '4.8000000', name: 'Gymnase renommé');

        self::assertCount(0, $this->dispatched(), 'coordonnées inchangées ⇒ aucun recalcul');
    }

    public function testNoMatrixDispatchesNothing(): void
    {
        [$user] = $this->seed();
        $venueId = $this->createVenue($user, '45.7000000', '4.8000000');
        $this->reset();

        // Le club n'a AUCUNE ligne de matrice : on ne fait pas naître une matrice sur un déplacement.
        $this->put($user, $venueId, '45.7654321', '4.8765432');

        self::assertCount(0, $this->dispatched(), 'aucune matrice ⇒ aucun recalcul (opt-in respecté)');
    }

    public function testAnotherClubsMatrixDoesNotCount(): void
    {
        [$user, $club, $season] = $this->seed();
        $venueId = $this->createVenue($user, '45.7000000', '4.8000000');
        // Une matrice existe, mais pour un AUTRE club/saison : elle ne doit pas déclencher le calcul
        // du club observé (le comptage est scopé club+saison).
        [, $otherClub, $otherSeason] = $this->seed();
        $this->matrixRow($otherClub, $otherSeason, 'cccccccc-0000-4000-8000-000000000003', 'dddddddd-0000-4000-8000-000000000003');
        $this->scopeGucToClub($club->getId());
        $this->reset();

        $this->put($user, $venueId, '45.7654321', '4.8765432');

        self::assertCount(0, $this->dispatched(), 'la matrice d’un AUTRE club ne compte pas');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createVenue(User $user, string $lat, string $lon): string
    {
        $this->client->request('POST', '/api/venues', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Gymnase G', 'source' => 'manual', 'latitude' => $lat, 'longitude' => $lon], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        /** @var array{id: string} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['id'];
    }

    private function put(User $user, string $venueId, string $lat, string $lon, string $name = 'Gymnase G'): void
    {
        $this->client->request('PUT', '/api/venues/' . $venueId, [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => $name, 'source' => 'manual', 'latitude' => $lat, 'longitude' => $lon], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    private function matrixRow(Club $club, Season $season, string $vA, string $vB): void
    {
        if (strcasecmp($vA, $vB) > 0) {
            [$vA, $vB] = [$vB, $vA];
        }
        $row = new VenueTravelTime;
        $row->setClubId($club->getId())->setSeasonId($season->getId())->setVenueAId($vA)->setVenueBId($vB);
        $this->em->persist($row);
        $this->em->flush();
    }

    /** @return list<ComputeTravelTimesMessage> */
    private function dispatched(): array
    {
        $transport = self::getContainer()->get('messenger.transport.travel_in_memory');
        \assert($transport instanceof InMemoryTransport);

        return array_values(array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()));
    }

    private function reset(): void
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
        $club->setName('Geo Recompute Club');
        $club->setSlug('geo-recompute-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('GRC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('geo-recompute-' . $uid . '@test.com');
        $user->setFirstName('G');
        $user->setLastName('R');
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
