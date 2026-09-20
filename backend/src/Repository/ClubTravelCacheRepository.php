<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubTravelCache;
use App\Service\Geo\TravelTimeCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubTravelCache>
 */
final class ClubTravelCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubTravelCache::class);
    }

    /**
     * The cached row for an exact (club, profile, origin, dest) key — the coordinates
     * are pre-rounded strings ({@see TravelTimeCache}). Null = a miss
     * (never computed for this club yet). The tenant filter scopes to the club anyway;
     * the explicit `clubId` keeps the lookup unambiguous.
     */
    public function findCached(string $clubId, string $profile, string $originLat, string $originLon, string $destLat, string $destLon): ?ClubTravelCache
    {
        return $this->findOneBy([
            'clubId' => $clubId,
            'profile' => $profile,
            'originLat' => $originLat,
            'originLon' => $originLon,
            'destLat' => $destLat,
            'destLon' => $destLon,
        ]);
    }

    /**
     * All cached rows for one (club, profile, origin) — the whole fan-out from a single
     * origin (the club siège). One query so a projection over many opponent gyms never
     * fans into an N+1.
     *
     * @return list<ClubTravelCache>
     */
    public function findAllFromOrigin(string $clubId, string $profile, string $originLat, string $originLon): array
    {
        return $this->findBy([
            'clubId' => $clubId,
            'profile' => $profile,
            'originLat' => $originLat,
            'originLon' => $originLon,
        ]);
    }
}
