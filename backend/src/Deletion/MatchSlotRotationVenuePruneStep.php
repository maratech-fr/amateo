<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Fixture;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\TeamMatchHabit;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RMM-5 (P2-49) — supprimer un gymnase SUPPRIME les créneaux de match partagés qui s'y
 * tiennent, parent ET lignes membres.
 *
 * ⚠ La rotation EST le créneau (``venueId`` NOT NULL) : sans son gymnase, elle n'a plus
 * d'existence — à la DIFFÉRENCE d'une {@see TeamMatchHabit} (qui survit en
 * jour+heure) ou d'une {@see Fixture} (dépointée, « à replacer »). Les lignes
 * membres ne portent pas de ``venue_id`` : on les purge par ``rotation_id``, sinon elles
 * pendraient sur un parent supprimé.
 */
final readonly class MatchSlotRotationVenuePruneStep implements CascadeStep
{
    public function __construct(private ImpactLabel $label) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return \count($this->rotationIdsOnVenue($entityManager, $target));
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $rotationIds = $this->rotationIdsOnVenue($entityManager, $target);
        if ([] === $rotationIds) {
            return;
        }

        $entityManager->createQueryBuilder()
            ->delete(MatchSlotRotationTeam::class, 't')
            ->where('t.rotationId IN (:ids)')
            ->setParameter('ids', $rotationIds)
            ->getQuery()
            ->execute();
        $entityManager->createQueryBuilder()
            ->delete(MatchSlotRotation::class, 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $rotationIds)
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<string>
     */
    private function rotationIdsOnVenue(EntityManagerInterface $entityManager, DeletionTarget $target): array
    {
        /** @var list<string> $ids */
        $ids = $entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(MatchSlotRotation::class, 'r')
            ->where('r.venueId = :venueId')
            ->andWhere('r.clubId = :clubId')
            ->andWhere('r.seasonId = :seasonId')
            ->setParameter('venueId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->getSingleColumnResult();

        return $ids;
    }
}
