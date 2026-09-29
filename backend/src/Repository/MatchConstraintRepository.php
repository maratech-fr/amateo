<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MatchConstraint;
use App\Enum\ConstraintScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MatchConstraint>
 */
final class MatchConstraintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatchConstraint::class);
    }

    /**
     * The club's CLUB-scoped match rules for a (club, season), ordered for a
     * stable display. Explicit clubId/seasonId so it works in a non-HTTP context
     * (seed, transition) as well as behind the Doctrine tenant/season filters.
     *
     * @return list<MatchConstraint>
     */
    public function findClubScopedForClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.clubId = :clubId')
            ->andWhere('r.seasonId = :seasonId')
            ->andWhere('r.scope = :scope')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('scope', ConstraintScope::CLUB)
            ->orderBy('r.ruleType', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
