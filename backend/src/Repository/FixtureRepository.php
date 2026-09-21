<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fixture>
 */
final class FixtureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fixture::class);
    }

    /**
     * The season's AWAY fixtures — the ones whose opponent has its own gym to
     * locate (P2-54 RMM-9 opponent directory). Tenant + season Doctrine filters
     * scope the query to the current club.
     *
     * @return list<Fixture>
     */
    public function findAwayBySeason(string $seasonId): array
    {
        return $this->findBy(['seasonId' => $seasonId, 'homeAway' => FixtureHomeAway::AWAY]);
    }

    /**
     * Les libellés FBI BRUTS attestés pour un gymnase, du plus récemment mis à jour au
     * plus ancien (même saison, même gymnase, libellé non nul). Sert à retrouver la
     * GRAPHIE d'origine d'un alias confirmé (les alias sont stockés normalisés, donc
     * illisibles à recopier dans FBI) : l'appelant garde le premier dont la forme
     * normalisée est un alias confirmé. Tenant + saison scopés par les filtres Doctrine.
     *
     * @return list<string>
     */
    public function findRawVenueLabelsBySeasonAndVenue(string $seasonId, string $venueId): array
    {
        /** @var list<string> $labels */
        $labels = $this->createQueryBuilder('f')
            ->select('f.fbiVenueLabel')
            ->andWhere('f.seasonId = :season')
            ->andWhere('f.venueId = :venue')
            ->andWhere('f.fbiVenueLabel IS NOT NULL')
            ->setParameter('season', $seasonId)
            ->setParameter('venue', $venueId)
            ->orderBy('f.updatedAt', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();

        return $labels;
    }
}
