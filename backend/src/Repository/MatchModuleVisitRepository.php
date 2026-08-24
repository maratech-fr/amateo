<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MatchModuleVisit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MatchModuleVisit>
 */
final class MatchModuleVisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatchModuleVisit::class);
    }

    /**
     * La visite du membre courant, dans le club+saison courants. Le `user_id` est
     * le SEUL prédicat explicite : les filtres Doctrine (club_id + season_id)
     * bornent déjà la requête, exactement la clé unique (club, saison, user).
     */
    public function findForUser(string $userId): ?MatchModuleVisit
    {
        return $this->findOneBy(['userId' => $userId]);
    }
}
