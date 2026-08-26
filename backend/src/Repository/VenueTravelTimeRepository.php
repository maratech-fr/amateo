<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VenueTravelTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VenueTravelTime>
 */
final class VenueTravelTimeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VenueTravelTime::class);
    }
}
