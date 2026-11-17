<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VenueOccupancy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VenueOccupancy>
 */
final class VenueOccupancyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VenueOccupancy::class);
    }
}
