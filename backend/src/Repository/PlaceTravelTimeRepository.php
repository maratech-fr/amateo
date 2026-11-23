<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlaceTravelTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaceTravelTime>
 */
final class PlaceTravelTimeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaceTravelTime::class);
    }

    /**
     * Les temps de trajet du club servis pour une saison : seuls les couples dont les DEUX lieux ont un
     * gymnase dans cette saison, nommés par ces gymnases et rangés selon eux (`venueAId < venueBId`, quel
     * que soit le rangement des lieux en base), triés par couple de gymnases.
     *
     * Les gymnases sont lus en SQL, club et saison en clause explicite (et RLS) : le `season_filter`
     * épinglerait la lecture ORM à la saison de la requête, or la saison demandée peut en différer (le
     * recalcul après un déplacement vise chaque saison du lieu). Un lieu a au plus un gymnase par saison
     * (`uniq_venue_season_place`).
     *
     * @return list<array{row: PlaceTravelTime, venueAId: string, venueBId: string}>
     */
    public function forSeason(string $clubId, string $seasonId): array
    {
        /** @var list<array{id: string, venue_a: string, venue_b: string}> $couples */
        $couples = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.id::text AS id, va.id::text AS venue_a, vb.id::text AS venue_b'
            . ' FROM place_travel_time t'
            . ' JOIN venue va ON va.place_id = t.place_a_id AND va.club_id = t.club_id AND va.season_id = :s'
            . ' JOIN venue vb ON vb.place_id = t.place_b_id AND vb.club_id = t.club_id AND vb.season_id = :s'
            . ' WHERE t.club_id = :c',
            ['c' => $clubId, 's' => $seasonId],
        );
        if ([] === $couples) {
            return [];
        }

        $rows = [];
        foreach ($this->createQueryBuilder('t')->where('t.id IN (:ids)')->andWhere('t.clubId = :c')
            ->setParameter('ids', array_column($couples, 'id'))->setParameter('c', $clubId)
            ->getQuery()->getResult() as $row) {
            \assert($row instanceof PlaceTravelTime);
            $rows[$row->getId()] = $row;
        }

        $served = [];
        foreach ($couples as $couple) {
            $row = $rows[$couple['id']] ?? null;
            if (!$row instanceof PlaceTravelTime) {
                continue;
            }
            [$venueAId, $venueBId] = strcmp($couple['venue_a'], $couple['venue_b']) > 0
                ? [$couple['venue_b'], $couple['venue_a']]
                : [$couple['venue_a'], $couple['venue_b']];
            $served[] = ['row' => $row, 'venueAId' => $venueAId, 'venueBId' => $venueBId];
        }
        usort($served, static fn (array $x, array $y): int => [$x['venueAId'], $x['venueBId']] <=> [$y['venueAId'], $y['venueBId']]);

        return $served;
    }
}
