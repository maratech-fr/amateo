<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Engagement;
use App\Matches\CompetitionRegistry;
use App\Service\Basketball\VenueLabelNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les engagements de l'équipe supprimée partent (après ses matchs, que la clé du match vers l'engagement
 * attend), puis chaque compétition restée sans engagement ni match ({@see CompetitionRegistry::pruneEmpty}).
 * Le compte annoncé est celui des engagements : une compétition vidée est l'engagement annoncé, pas une
 * ligne de plus.
 */
final readonly class TeamEngagementStep implements CascadeStep
{
    public function __construct(private ImpactLabel $label) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Engagement::class, 'e')
            ->where('e.teamId = :teamId')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $entityManager->createQueryBuilder()
            ->delete(Engagement::class, 'e')
            ->where('e.teamId = :teamId')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('teamId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->execute();

        new CompetitionRegistry($entityManager, new VenueLabelNormalizer)->pruneEmpty($target->seasonId);
    }
}
