<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubLeagueWindow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubLeagueWindow>
 */
final class ClubLeagueWindowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubLeagueWindow::class);
    }

    /**
     * The club's copy for a given (club, season), ordered category → level → day →
     * kickoff for stable display. Bypasses the Doctrine tenant/season filters
     * (explicit clubId/seasonId) so it is usable in a non-HTTP context (seed,
     * transition) as well as behind the filters.
     *
     * @return list<ClubLeagueWindow>
     */
    public function findForClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.clubId = :clubId')
            ->andWhere('w.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->orderBy('w.category', 'ASC')
            ->addOrderBy('w.level', 'ASC')
            ->addOrderBy('w.dayOfWeek', 'ASC')
            ->addOrderBy('w.kickoffMin', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
