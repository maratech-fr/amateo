<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\MatchSlotRotationResource;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<MatchSlotRotation, MatchSlotRotationResource>
 */
class MatchSlotRotationStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return MatchSlotRotation::class;
    }

    /**
     * @param MatchSlotRotation $entity
     */
    protected function mapEntityToOutput(object $entity): MatchSlotRotationResource
    {
        return MatchSlotRotationResource::fromEntity($entity, $this->teamIdsOf($entity->getId()));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, MatchSlotRotationResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(MatchSlotRotation::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $venueId = $this->uuidQueryParam($request, 'venueId');
            if (null !== $venueId) {
                $qb->andWhere('e.venueId = :venueId')->setParameter('venueId', $venueId);
            }

            $seasonId = $this->uuidQueryParam($request, 'seasonId');
            if (null !== $seasonId) {
                $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
            }
        }

        // UUID PK tiebreaker: (day, kickoff) is not unique — without it the id-dedupe
        // of the base provider could drop rows sharing the sort key (VenueMatchWindow idiom).
        $qb->orderBy('e.dayOfWeek', 'ASC')->addOrderBy('e.kickoffTime', 'ASC')->addOrderBy('e.id', 'ASC');

        /** @var list<MatchSlotRotation> $rotations */
        $rotations = $qb->getQuery()->getResult();

        // Members of all rotations in ONE query, ordered by position (no N+1).
        $teamIdsByRotation = $this->teamIdsOfMany(array_map(static fn (MatchSlotRotation $r): string => $r->getId(), $rotations));

        return array_map(
            static fn (MatchSlotRotation $rotation): MatchSlotRotationResource => MatchSlotRotationResource::fromEntity(
                $rotation,
                $teamIdsByRotation[$rotation->getId()] ?? [],
            ),
            $rotations,
        );
    }

    /**
     * The team ids of SEVERAL rotations, ordered by position, in one query.
     *
     * Same order — position ASC, id ASC as tiebreaker — as {@see teamIdsOf}, so the
     * collection and the item render EXACTLY the same list.
     *
     * @param list<string> $rotationIds
     *
     * @return array<string, list<string>>
     */
    private function teamIdsOfMany(array $rotationIds): array
    {
        if ([] === $rotationIds) {
            return [];
        }

        /** @var list<array{rotationId: string, teamId: string}> $rows */
        $rows = $this->entityManager->getRepository(MatchSlotRotationTeam::class)
            ->createQueryBuilder('t')
            ->select('t.rotationId', 't.teamId')
            ->where('t.rotationId IN (:rotationIds)')
            ->setParameter('rotationIds', $rotationIds)
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $byRotation = [];
        foreach ($rows as $row) {
            $byRotation[$row['rotationId']][] = $row['teamId'];
        }

        return $byRotation;
    }

    /**
     * The team ids of a rotation, in display order (position ASC, id ASC as tiebreaker).
     *
     * @return list<string>
     */
    private function teamIdsOf(string $rotationId): array
    {
        /** @var list<array{teamId: string}> $rows */
        $rows = $this->entityManager->getRepository(MatchSlotRotationTeam::class)
            ->createQueryBuilder('t')
            ->select('t.teamId')
            ->where('t.rotationId = :rotationId')
            ->setParameter('rotationId', $rotationId)
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => $row['teamId'], $rows);
    }
}
