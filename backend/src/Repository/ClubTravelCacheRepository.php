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

    /**
     * P4-249 — purge every cached row that fans out FROM one origin, ALL profiles, for one club.
     * When the club siège moves, the old origin becomes a dead key no read will ever hit again
     * (the cache is directional): its rows are pruned here so they never accumulate.
     *
     * Coordinates are matched in the canonical 5-decimal form shared with {@see TravelTimeCache}
     * — the exact string the rows were written with (`%.5f`). The `clubId` bound is EXPLICIT (RLS
     * doubles it in base); no profile filter — a moved siège invalidates every profile's fan-out
     * from the old point. Returns the number of rows removed.
     */
    public function deleteAllFromOrigin(string $clubId, float $originLat, float $originLon): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->delete(ClubTravelCache::class, 'c')
            ->where('c.clubId = :clubId')
            ->andWhere('c.originLat = :lat')
            ->andWhere('c.originLon = :lon')
            ->setParameter('clubId', $clubId)
            ->setParameter('lat', \sprintf('%.5f', $originLat))
            ->setParameter('lon', \sprintf('%.5f', $originLon))
            ->getQuery()
            ->execute();
    }
}
