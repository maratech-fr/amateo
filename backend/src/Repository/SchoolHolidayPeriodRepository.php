<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchoolHolidayPeriod;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolHolidayPeriod>
 */
final class SchoolHolidayPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolHolidayPeriod::class);
    }

    public function findOneByNaturalKey(string $zone, string $holidayType, string $schoolYear): ?SchoolHolidayPeriod
    {
        return $this->findOneBy([
            'zone' => $zone,
            'holidayType' => $holidayType,
            'schoolYear' => $schoolYear,
        ]);
    }

    /**
     * Holidays of a zone overlapping the [from, to] window, chronological.
     *
     * @return list<SchoolHolidayPeriod>
     */
    public function findByZoneAndWindow(string $zone, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.zone = :zone')
            ->andWhere('h.endDate >= :from')
            ->andWhere('h.startDate <= :to')
            ->setParameter('zone', $zone)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('h.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Holidays of a zone STARTING within [$today, $today + $horizonDays], chronological — the
     * school-holiday reminder's horizon (a window, so a missed daily run still catches up).
     *
     * @return list<SchoolHolidayPeriod>
     */
    public function findStartingInWindow(string $zone, DateTimeImmutable $today, int $horizonDays): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.zone = :zone')
            ->andWhere('h.startDate >= :from')
            ->andWhere('h.startDate <= :to')
            ->setParameter('zone', $zone)
            ->setParameter('from', $today->format('Y-m-d'))
            ->setParameter('to', $today->modify(\sprintf('+%d days', $horizonDays))->format('Y-m-d'))
            ->orderBy('h.startDate', 'ASC')
            ->addOrderBy('h.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
