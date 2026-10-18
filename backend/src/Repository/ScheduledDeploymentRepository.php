<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScheduledDeployment;
use App\Enum\DeploymentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledDeployment>
 */
final class ScheduledDeploymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledDeployment::class);
    }

    /**
     * Le déploiement ACTIF (`scheduled`|`dispatched`), ou null. Au plus un par l'index unique
     * partiel — on prend le plus récent par prudence.
     */
    public function findActive(): ?ScheduledDeployment
    {
        // Paramètre = instances d'enum (le champ est mappé `enumType`) : Doctrine les convertit
        // en valeurs stockées ; passer des chaînes brutes ne matcherait pas.
        return $this->createQueryBuilder('d')
            ->andWhere('d.status IN (:active)')
            ->setParameter('active', [DeploymentStatus::SCHEDULED, DeploymentStatus::DISPATCHED])
            ->orderBy('d.scheduledAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Les déploiements récents (actifs et terminés), le plus récent d'abord — la liste de la
     * console.
     *
     * @return list<ScheduledDeployment>
     */
    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
