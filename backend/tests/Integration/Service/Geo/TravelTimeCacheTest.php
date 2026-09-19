<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
use App\Tests\TenantGucTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * C4 — le cache de trajets {@see TravelTimeCache}. Un trajet est une CONSTANTE : la clé
 * est arrondie à 5 décimales (~1 m), une valeur mise en cache n'est jamais recalculée ni
 * écrasée, et un échec (null) n'entre jamais.
 */
#[Group('integration')]
final class TravelTimeCacheTest extends WebTestCase
{
    use TenantGucTrait;

    private TravelTimeCache $cache;

    private string $clubId;

    public function testStoreThenLookupRoundTrips(): void
    {
        $this->cache->store($this->clubId, IgnRoutingClient::PROFILE_CAR, 45.76321, 4.83512, 45.18765, 5.72111, 42);
        self::assertSame(42, $this->cache->lookup($this->clubId, IgnRoutingClient::PROFILE_CAR, 45.76321, 4.83512, 45.18765, 5.72111));
    }

    public function testTheKeyIsRoundedToFiveDecimals(): void
    {
        // Écriture avec des coordonnées à 7 décimales.
        $this->cache->store($this->clubId, IgnRoutingClient::PROFILE_CAR, 45.7632149, 4.8351201, 45.1876500, 5.7211149, 30);

        // Lecture avec des coordonnées qui ARRONDISSENT à la même 5e décimale → HIT.
        self::assertSame(30, $this->cache->lookup($this->clubId, IgnRoutingClient::PROFILE_CAR, 45.76321489, 4.83512013, 45.18765004, 5.72111486), 'un point à ~1 m retrouve sa ligne');

        // Une différence à la 5e décimale = une clé DIFFÉRENTE → MISS.
        self::assertNull($this->cache->lookup($this->clubId, IgnRoutingClient::PROFILE_CAR, 45.76322, 4.83512, 45.18765, 5.72111), 'un point plus loin qu\'un mètre est une autre clé');
    }

    public function testAnExistingValueIsNeverRecomputedNorOverwritten(): void
    {
        $this->cache->store($this->clubId, IgnRoutingClient::PROFILE_CAR, 45.5, 4.5, 46.0, 5.0, 25);
        // Un second calcul de la MÊME clé (valeur différente) est un no-op : la constante tient.
        $this->cache->store($this->clubId, IgnRoutingClient::PROFILE_CAR, 45.5, 4.5, 46.0, 5.0, 999);
        self::assertSame(25, $this->cache->lookup($this->clubId, IgnRoutingClient::PROFILE_CAR, 45.5, 4.5, 46.0, 5.0));
    }

    public function testProfilesAreKeyedSeparately(): void
    {
        $this->cache->store($this->clubId, IgnRoutingClient::PROFILE_CAR, 45.5, 4.5, 46.0, 5.0, 20);
        // Le même trajet à pied est une AUTRE clé — jamais confondu avec la voiture.
        self::assertNull($this->cache->lookup($this->clubId, IgnRoutingClient::PROFILE_PEDESTRIAN, 45.5, 4.5, 46.0, 5.0));
    }

    public function testAMissReturnsNull(): void
    {
        self::assertNull($this->cache->lookup($this->clubId, IgnRoutingClient::PROFILE_CAR, 10.0, 10.0, 11.0, 11.0));
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->cache = self::getContainer()->get(TravelTimeCache::class);
        $this->clubId = Uuid::v4()->toRfc4122();
        $this->scopeGucToClub($this->clubId); // RLS : le GUC autorise INSERT/SELECT sur ce club.
    }
}
