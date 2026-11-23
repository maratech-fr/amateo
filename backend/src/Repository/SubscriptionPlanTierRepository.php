<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SubscriptionPlanTier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionPlanTier>
 */
final class SubscriptionPlanTierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionPlanTier::class);
    }

    /**
     * Le palier qui suit $tier dans son offre (position immédiatement supérieure) ; null au sommet.
     */
    public function nextOf(SubscriptionPlanTier $tier): ?SubscriptionPlanTier
    {
        $next = $this->createQueryBuilder('t')
            ->andWhere('t.planId = :planId')
            ->andWhere('t.position > :position')
            ->setParameter('planId', $tier->getPlanId())
            ->setParameter('position', $tier->getPosition())
            ->orderBy('t.position', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $next instanceof SubscriptionPlanTier ? $next : null;
    }
}
