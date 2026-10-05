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

    /**
     * Le club démo CONSERVÉ le plus RÉCENT portant ce code FFBB, ou null (P4-294).
     *
     * `is_demo = true AND demo_retained_until IS NOT NULL` : un club démo détaché de
     * l'animateur, gardé 14 jours. Deux conservés sur le même code (rare) → le PLUS
     * RÉCENT est repris, l'autre expire à son échéance. L'approbation P3-4 tente cette
     * reprise APRÈS {@see self::findRealByFfbbCode} — le club RÉEL garde toujours la priorité.
     */
    public function findRetainedDemoByFfbbCode(string $code): ?Club
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.ffbbClubCode = :code')
            ->andWhere('c.isDemo = true')
            ->andWhere('c.demoRetainedUntil IS NOT NULL')
            ->setParameter('code', $code)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
