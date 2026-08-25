<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SharedCompetitionDeadline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SharedCompetitionDeadline>
 */
final class SharedCompetitionDeadlineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedCompetitionDeadline::class);
    }

    public function findOneByFfbbCompetitionId(string $ffbbCompetitionId): ?SharedCompetitionDeadline
    {
        return $this->findOneBy(['ffbbCompetitionId' => $ffbbCompetitionId]);
    }

    /**
     * The community defaults for a set of FFBB competition ids, keyed by id — so
     * the read provider joins the club's paired competitions to their shared
     * default in one query (never N+1). This table is GLOBAL (no tenant filter),
     * so the lookup is intentionally NOT club-scoped: the default is community-wide.
     *
     * @param list<string> $ffbbCompetitionIds
     *
     * @return array<string, SharedCompetitionDeadline>
     */
    public function mapByFfbbCompetitionIds(array $ffbbCompetitionIds): array
    {
        if ([] === $ffbbCompetitionIds) {
            return [];
        }

        $map = [];
        foreach ($this->findBy(['ffbbCompetitionId' => array_values(array_unique($ffbbCompetitionIds))]) as $row) {
            $map[$row->getFfbbCompetitionId()] = $row;
        }

        return $map;
    }
}
