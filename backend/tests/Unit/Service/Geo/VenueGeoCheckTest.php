<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Geo;

use App\Entity\Venue;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbSalleAddressResolver;
use App\Service\Geo\BanGeocodingClient;
use App\Service\Geo\VenueGeoCheck;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Contrôle de cohérence de la position stockée d'un gymnase FFBB (étude BCCL 2026-09-30),
 * falsifié avec les deux clients (FFBB + BAN) stubbés en mémoire — jamais le live :
 *  - la comparaison de rue est normalisée : « 251cours Émile Zola » ≡ « Cours Emile Zola »,
 *    « Rue Sèverine » ≡ « Rue Séverine » (aucune alerte) ;
 *  - le point tombe dans une AUTRE rue (type housenumber) → alerte OTHER_STREET ;
 *  - le point exact de l'adresse FFBB (type housenumber) est à > 300 m → alerte FAR_FROM_ADDRESS ;
 *  - forward de type `street` sur la même rue → aucune alerte ;
 *  - salle FFBB introuvable, ou BAN qui échoue en transport → aucune alerte (best-effort).
 */
#[Group('unit')]
final class VenueGeoCheckTest extends TestCase
{
    private const string VENUE_LAT = '45.76672';
    private const string VENUE_LON = '4.90760';

    public function testAGluedNumberAndAccentsDoNotCountAsAnotherStreet(): void
    {
        // « 251cours Émile Zola » (FFBB, numéro collé) ≡ « Cours Emile Zola » (reverse) : même rue.
        $alerts = $this->runCheck(
            ffbbHit: $this->salle('251cours Émile Zola'),
            reverseFeature: $this->reverseFeature('Cours Emile Zola', 'street'),
            forwardFeature: $this->forwardFeature('street', 45.76672, 4.90760),
        );

        self::assertSame([], $alerts, 'un numéro collé et des accents ne font pas une autre rue');
    }

    public function testAccentDifferencesDoNotCountAsAnotherStreet(): void
    {
        // « Rue Sèverine » (FFBB) ≡ « Rue Séverine » (reverse) : accent seul, même rue.
        $alerts = $this->runCheck(
            ffbbHit: $this->salle('3 Rue Sèverine'),
            reverseFeature: $this->reverseFeature('Rue Séverine', 'housenumber'),
            forwardFeature: $this->forwardFeature('street', 45.76672, 4.90760),
        );

        self::assertSame([], $alerts, 'une différence d\'accent ne fait pas une autre rue');
    }

    public function testAPointFallingInAnotherStreetRaisesOtherStreet(): void
    {
        $alerts = $this->runCheck(
            ffbbHit: $this->salle('253 cours Emile Zola'),
            reverseFeature: $this->reverseFeature('Rue Léon Blum', 'housenumber'),
            forwardFeature: $this->forwardFeature('street', 45.76672, 4.90760),
        );

        self::assertCount(1, $alerts);
        self::assertSame(VenueGeoCheck::REASON_OTHER_STREET, $alerts[0]['reason']);
        self::assertSame('253 cours Emile Zola', $alerts[0]['ffbbAddress']);
        self::assertSame('Rue Léon Blum', $alerts[0]['pointStreet']);
        self::assertNull($alerts[0]['distanceM']);
    }

    public function testAHousenumberFartherThan300mRaisesFarFromAddress(): void
    {
        // Pas de reverse exploitable (skip (a)) ; le point exact de l'adresse FFBB est ailleurs.
        $alerts = $this->runCheck(
            ffbbHit: $this->salle('253 cours Emile Zola'),
            reverseFeature: null,
            forwardFeature: $this->forwardFeature('housenumber', 45.75000, 4.85000),
        );

        self::assertCount(1, $alerts);
        self::assertSame(VenueGeoCheck::REASON_FAR_FROM_ADDRESS, $alerts[0]['reason']);
        self::assertSame('253 cours Emile Zola', $alerts[0]['ffbbAddress']);
        self::assertNull($alerts[0]['pointStreet']);
        self::assertIsInt($alerts[0]['distanceM']);
        self::assertGreaterThan(300, $alerts[0]['distanceM']);
    }

    public function testAStreetForwardOnTheSameStreetRaisesNothing(): void
    {
        // Reverse sur la même rue (skip (a)) ET forward de type `street` (skip (b)) → rien.
        $alerts = $this->runCheck(
            ffbbHit: $this->salle('253 cours Emile Zola'),
            reverseFeature: $this->reverseFeature('Cours Émile Zola', 'housenumber'),
            forwardFeature: $this->forwardFeature('street', 45.10000, 4.10000),
        );

        self::assertSame([], $alerts, 'un forward imprécis (rue entière) ne déclenche jamais la distance');
    }

    public function testAnUnknownFfbbSalleRaisesNothing(): void
    {
        $alerts = $this->runCheck(
            ffbbHit: null,
            reverseFeature: $this->reverseFeature('Rue Léon Blum', 'housenumber'),
            forwardFeature: $this->forwardFeature('housenumber', 45.75000, 4.85000),
        );

        self::assertSame([], $alerts, 'salle FFBB introuvable → aucune alerte (best-effort)');
    }

    public function testABanTransportFailureRaisesNothing(): void
    {
        // La salle résout, pas de reverse (skip (a)), mais le forward BAN échoue en transport.
        $alerts = $this->runCheck(
            ffbbHit: $this->salle('253 cours Emile Zola'),
            reverseFeature: null,
            forwardFeature: null,
            forwardThrows: true,
        );

        self::assertSame([], $alerts, 'un échec réseau de la BAN n\'est jamais une alerte');
    }

    /**
     * @param array<string, mixed>|null $ffbbHit
     * @param array<string, mixed>|null $reverseFeature
     * @param array<string, mixed>|null $forwardFeature
     *
     * @return list<array{venueId: string, reason: string, ffbbAddress: string, pointStreet: string|null, distanceM: int|null}>
     */
    private function runCheck(
        ?array $ffbbHit,
        ?array $reverseFeature,
        ?array $forwardFeature,
        bool $forwardThrows = false,
    ): array {
        $venue = (new Venue)
            ->setClubId('club-1')
            ->setSeasonId('season-1')
            ->setName('Zola')
            ->setSource('manual')
            ->setExternalRef('166900001')
            ->setLatitude(self::VENUE_LAT)
            ->setLongitude(self::VENUE_LON);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$venue]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $ffbbHttp = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'results' => [['hits' => null === $ffbbHit ? [] : [$ffbbHit]]],
        ])));
        $salleResolver = new FfbbSalleAddressResolver(new FfbbApiClient($ffbbHttp, 'test-token'));

        $banHttp = new MockHttpClient(static function (string $method, string $url) use ($reverseFeature, $forwardFeature, $forwardThrows): MockResponse {
            if (str_contains($url, '/reverse/')) {
                return new MockResponse((string) json_encode(['features' => null === $reverseFeature ? [] : [$reverseFeature]]));
            }
            if ($forwardThrows) {
                throw new TransportException('BAN indisponible');
            }

            return new MockResponse((string) json_encode(['features' => null === $forwardFeature ? [] : [$forwardFeature]]));
        });

        return new VenueGeoCheck($em, $salleResolver, new BanGeocodingClient($banHttp))->check('club-1', 'season-1');
    }

    /**
     * @return array<string, mixed>
     */
    private function salle(string $address): array
    {
        return [
            'numero' => '166900001',
            'adresse' => $address,
            'cartographie' => ['ville' => 'Villeurbanne', 'latitude' => 45.76672, 'longitude' => 4.9076],
            'commune' => ['codePostal' => '69100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reverseFeature(string $street, string $type): array
    {
        return ['type' => 'Feature', 'properties' => ['street' => $street, 'type' => $type]];
    }

    /**
     * @return array<string, mixed>
     */
    private function forwardFeature(string $type, float $latitude, float $longitude): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude]],
            'properties' => ['label' => 'Point exact', 'type' => $type, 'score' => 0.9],
        ];
    }
}
