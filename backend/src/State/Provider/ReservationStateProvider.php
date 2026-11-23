<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\ReservationResource;
use App\Entity\Reservation;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<Reservation, ReservationResource>
 */
class ReservationStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return Reservation::class;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, ReservationResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityClass(), 'e')
            ->orderBy('e.dayOfWeek', 'ASC')
            ->addOrderBy('e.startTime', 'ASC')
            // UUID PK tiebreaker → stable pagination (see AbstractStateProvider).
            ->addOrderBy('e.id', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $qb->setFirstResult($this->pagination->getOffset($operation, $context))
               ->setMaxResults($this->pagination->getLimit($operation, $context));
        }

        // Base/overlay layering (same as constraints): ?schedulePlanId=<id> lists a
        // period overlay's reservations; without it, the base plan (the season plan,
        // lot 4) so the wizard's Réserver tab shows the permanent reservations.
        $request = $this->requestStack->getCurrentRequest();
        $readPlanId = ($request instanceof Request ? $this->uuidQueryParam($request, 'schedulePlanId') : null) ?? $this->soclePlanOfRead($clubId);
        if (null === $readPlanId) {
            $qb->andWhere('1 = 0'); // aucune saison : aucun socle à lire
        } else {
            $qb->andWhere('e.schedulePlanId = :schedulePlanId')
               ->setParameter('schedulePlanId', $readPlanId);
        }

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }

    /**
     * @param Reservation $entity
     */
    protected function mapEntityToOutput(object $entity): ReservationResource
    {
        return ReservationResource::fromEntity($entity, $this->planToApi($entity->getSchedulePlanId(), $entity->getSeasonId()));
    }
}
