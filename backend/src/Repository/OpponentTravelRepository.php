<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OpponentTravel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpponentTravel>
 */
final class OpponentTravelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpponentTravel::class);
    }

    /**
     * The club+season travel rows (tenant + season Doctrine filters already scope
     * the query to the current club).
     *
     * @return list<OpponentTravel>
     */
    public function findBySeason(string $seasonId): array
    {
        return $this->findBy(['seasonId' => $seasonId]);
    }

    public function findOneByCode(string $seasonId, string $opponentOrganismeCode): ?OpponentTravel
    {
        return $this->findOneBy(['seasonId' => $seasonId, 'opponentOrganismeCode' => $opponentOrganismeCode]);
    }

    /**
     * ONE-WAY car travel minutes keyed by opponent organisme code, for the
     * club+season — the map the radar reads to give an AWAY fixture its round
     * trip (2 ×). A row without minutes is omitted (best-effort: no minutes,
     * no spatial conflict).
     *
     * @return array<string, int>
     */
    public function travelMinutesByCode(string $seasonId): array
    {
        $map = [];
        foreach ($this->findBySeason($seasonId) as $row) {
            $minutes = $row->getTravelMinutes();
            if (null !== $minutes) {
                $map[$row->getOpponentOrganismeCode()] = $minutes;
            }
        }

        return $map;
    }
}
