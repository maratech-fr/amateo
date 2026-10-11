<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Constraint>
 */
final class ConstraintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Constraint::class);
    }

    /**
     * @return list<Constraint>
     */
    public function findByClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.clubId = :clubId')
            ->andWhere('c.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Permanent (non-dated) constraints only — the base plan. Dated constraints
     * (calendarEntryId set) belong to a CalendarEntry period and must NOT feed
     * base-plan generation. See specs/courantes/calendar-entry-modele-donnees.md §c.
     *
     * @return list<Constraint>
     */
    public function findPermanentByClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.clubId = :clubId')
            ->andWhere('c.seasonId = :seasonId')
            ->andWhere('c.calendarEntryId IS NULL')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->getResult();
    }

    /**
     * P2-38 — TOUTES les fermetures datées (`FACILITY`, portées par une entrée de calendrier)
     * du club+saison, quelle que soit l'entrée porteuse. C'est la source de la transversalité
     * des fermetures (service `PlanVenueClosures`) : une fermeture déclarée sur une entrée
     * s'applique à tout plan de période dont la fenêtre la recoupe. Le tri déterministe
     * (createdAt, id) fixe l'ordre des résumés quand plusieurs fermetures se recoupent.
     *
     * @return list<Constraint>
     */
    public function findDatedFacilityByClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.clubId = :clubId')
            ->andWhere('c.seasonId = :seasonId')
            ->andWhere('c.calendarEntryId IS NOT NULL')
            ->andWhere('c.family = :family')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('family', ConstraintFamily::FACILITY)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Constraint>
     */
    public function findByScope(string $scope, string $targetId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.scope = :scope')
            ->andWhere('c.scopeTargetId = :targetId')
            ->setParameter('scope', $scope)
            ->setParameter('targetId', $targetId)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Constraint>
     */
    public function findActiveByClub(string $clubId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.clubId = :clubId')
            ->andWhere('c.isActive = true')
            ->setParameter('clubId', $clubId)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Constraint>
     */
    public function findByFamily(string $family): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.family = :family')
            ->setParameter('family', $family)
            ->getQuery()
            ->getResult();
    }
}
