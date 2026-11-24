<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenuePeriodOverrideResource;
use App\Entity\PlanVenue;
use App\Plans\PlanComposition;
use Symfony\Component\HttpFoundation\Request;

/**
 * Les réglages de gymnases d'une période : les lignes d'un plan de période qui diffèrent du défaut (lot 4, PR 3b). Une
 * ligne au défaut n'est pas un réglage, la liste ne la rend pas.
 *
 * @extends AbstractStateProvider<PlanVenue, VenuePeriodOverrideResource>
 */
class VenuePeriodOverrideStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return PlanVenue::class;
    }

    /**
     * @param PlanVenue $entity
     */
    protected function mapEntityToOutput(object $entity): VenuePeriodOverrideResource
    {
        return VenuePeriodOverrideResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, VenuePeriodOverrideResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(PlanVenue::class, 'e')
            ->andWhere(PlanComposition::VENUE_DIFFERS_FROM_DEFAULT_DQL);

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // Les réglages sont toujours consultés par période ; un venueId affine encore.
            $schedulePlanId = $this->uuidQueryParam($request, 'schedulePlanId');
            if (null !== $schedulePlanId) {
                $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $schedulePlanId);
            }

            $venueId = $this->uuidQueryParam($request, 'venueId');
            if (null !== $venueId) {
                $qb->andWhere('e.venueId = :venueId')->setParameter('venueId', $venueId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
