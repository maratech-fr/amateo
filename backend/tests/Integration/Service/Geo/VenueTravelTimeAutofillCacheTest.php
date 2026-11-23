<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Entity\Club;
use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
use App\Service\Geo\VenueTravelTimeAutofillService;
use App\Structure\PlaceDirectory;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * C4, cache-first : un trajet entre deux gymnases calculé par l'autofill de la matrice entre dans le
 * cache de trajets du club (indexé par coordonnées, lues sur le lieu de chaque gymnase), si bien qu'un
 * second calcul de la même paire ne repart jamais vers IGN.
 *
 * Garde VIVANTE de la règle qu'amorçait le seed one-shot de `Version20260920120000` (le cache connaît
 * les trajets de la matrice des gymnases) : ce seed, appliqué en production, ne rejoue plus jamais ; son
 * test, qui relisait `venue.latitude/longitude` supprimées au lot 3 (PR 4), est retiré au profit de
 * celui-ci (charte docs/testing/tests-par-parcours.md, patron « NR de migration de données »).
 */
#[Group('integration')]
final class VenueTravelTimeAutofillCacheTest extends WebTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testAComputedPairEntersTheClubCacheAndIsNeverAskedToIgnTwice(): void
    {
        [$clubId, $seasonId] = $this->seedTwoGeolocatedVenues();
        // `distance` requise : le mode vélo dérive son temps de la DISTANCE de l'itinéraire.
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => 600, 'distance' => 3000])));
        $autofill = new VenueTravelTimeAutofillService(
            $this->em,
            new IgnRoutingClient($http, new MockClock),
            self::getContainer()->get(TravelTimeCache::class),
            self::getContainer()->get(PlaceDirectory::class),
        );

        self::assertSame(1, $autofill->autofill($clubId, $seasonId)['filled'], 'témoin : la paire est calculée');
        $asked = $http->getRequestsCount();
        self::assertGreaterThan(0, $asked, 'témoin : le premier calcul interroge IGN');

        $cache = self::getContainer()->get(TravelTimeCache::class);
        // La paire est rangée par identifiant de gymnase (tiré au hasard) : le sens du trajet mis en cache suit.
        $driving = $cache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, 45.10, 4.10, 45.20, 4.20)
            ?? $cache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, 45.20, 4.20, 45.10, 4.10);
        self::assertNotNull($driving, 'la conduite de la paire est dans le cache du club, aux coordonnées des lieux');

        $autofill->autofill($clubId, $seasonId);
        self::assertSame($asked, $http->getRequestsCount(), 'le second calcul se sert du cache, sans un appel IGN de plus');
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: string, 1: string} clubId, seasonId */
    private function seedTwoGeolocatedVenues(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Club cache ' . $uid)->setSlug('club-cache-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('today'))->setEndDate(new DateTimeImmutable('+300 days'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        foreach ([['Gymnase A', '45.1000000', '4.1000000'], ['Gymnase B', '45.2000000', '4.2000000']] as [$name, $lat, $lon]) {
            $venue = $this->newVenue($club->getId(), $season->getId(), $name);
            $venue->placeGivenAtCreation()?->setLatitude($lat)->setLongitude($lon);
        }
        $this->em->flush();
        $this->em->clear();

        return [$club->getId(), $season->getId()];
    }
}
