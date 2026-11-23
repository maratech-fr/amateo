<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenueTrainingSlotResource;
use App\Entity\VenueTrainingSlot;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<VenueTrainingSlot, VenueTrainingSlotResource>
 */
class VenueTrainingSlotStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return VenueTrainingSlot::class;
    }

    /**
     * @param VenueTrainingSlot $entity
     */
    protected function mapEntityToOutput(object $entity): VenueTrainingSlotResource
    {
        return VenueTrainingSlotResource::fromEntity($entity, $this->planToApi($entity->getSchedulePlanId(), $entity->getSeasonId()));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, VenueTrainingSlotResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(VenueTrainingSlot::class, 'e');

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

            // Period-editable structure: ?schedulePlanId=<id> lists that period's own
            // slots; ABSENT ⇒ SEASONAL only (the season plan, lot 4) so the wizard's
            // seasonal editor never shows a period's borrowed slots.
            $readPlanId = $this->uuidQueryParam($request, 'schedulePlanId') ?? $this->soclePlanOfRead($clubId, $seasonId);
            if (null === $readPlanId) {
                $qb->andWhere('1 = 0'); // aucune saison : aucun socle à lire
            } else {
                $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $readPlanId);
            }
        }

        // Deterministic total order: dayOfWeek/startTime are not unique, so add the
        // UUID PK as a tiebreaker — without it, offset pagination is unstable (rows
        // in the same dayOfWeek reshuffle between page requests), and collectionAll's
        // dedupe drops the reshuffled rows straddling a page boundary entirely.
        $qb->orderBy('e.dayOfWeek', 'ASC')->addOrderBy('e.startTime', 'ASC')->addOrderBy('e.id', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)->setMaxResults($limit);
        }

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
