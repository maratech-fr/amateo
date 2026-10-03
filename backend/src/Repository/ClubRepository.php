<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Club;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Club>
 */
final class ClubRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Club::class);
    }

    /**
     * Le club RÉEL (non démo) portant ce code FFBB, ou null.
     *
     * Un code FFBB n'est plus unique qu'entre clubs RÉELS (l'index unique est
     * partiel, `WHERE NOT is_demo`) : un club de démonstration peut squatter le
     * code d'un vrai club. Tous les chemins d'inscription et d'approbation
     * résolvent « le club de ce code » PAR CETTE MÉTHODE — jamais par un
     * `findOneBy(['ffbbClubCode' => …])` nu qui happerait une démo et ferait
     * entrer un vrai inscrit dans un club de démonstration.
     */
    public function findRealByFfbbCode(string $code): ?Club
    {
        return $this->findOneBy(['ffbbClubCode' => $code, 'isDemo' => false]);
    }
}
