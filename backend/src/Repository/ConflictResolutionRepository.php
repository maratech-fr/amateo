<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConflictResolution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConflictResolution>
 */
final class ConflictResolutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConflictResolution::class);
    }

    /**
     * The club+season resolution rows (tenant + season Doctrine filters already
     * scope the query to the current club).
     *
     * @return list<ConflictResolution>
     */
    public function findBySeason(string $seasonId): array
    {
        return $this->findBy(['seasonId' => $seasonId]);
    }

    /**
     * The resolution rows of a season keyed by conflict fingerprint — the map the
     * radar joins onto its live conflicts (an empreinte absent from the flow is
     * simply never looked up, so orphan rows stay invisible).
     *
     * @return array<string, ConflictResolution>
     */
    public function mapByFingerprint(string $seasonId): array
    {
        $map = [];
        foreach ($this->findBySeason($seasonId) as $row) {
            $map[$row->getFingerprint()] = $row;
        }

        return $map;
    }

    public function findOneByFingerprint(string $seasonId, string $fingerprint): ?ConflictResolution
    {
        return $this->findOneBy(['seasonId' => $seasonId, 'fingerprint' => $fingerprint]);
    }
}
