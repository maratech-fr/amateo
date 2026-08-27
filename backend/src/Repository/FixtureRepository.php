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
}
