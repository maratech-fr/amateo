<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MatchPlacementRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MatchPlacementRun>
 */
final class MatchPlacementRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatchPlacementRun::class);
    }

    /**
     * Le dernier run de placement du club+saison courants — ce que l'écran relit à
     * l'ouverture pour savoir s'il y a un placement en cours et récupérer le résultat
     * d'un run déjà terminé. Les filtres Doctrine (club_id + season_id) et la RLS
     * bornent déjà la requête au tenant : un autre club ne voit jamais ce run.
     */
    public function latestForCurrentScope(): ?MatchPlacementRun
    {
        return $this->findOneBy([], ['createdAt' => 'DESC']);
    }
}
