<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenueTravelTimeResource;
use App\Entity\VenueTravelTime;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<VenueTravelTime, VenueTravelTimeResource>
 */
class VenueTravelTimeStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return VenueTravelTime::class;
    }

    /**
     * @param VenueTravelTime $entity
     */
    protected function mapEntityToOutput(object $entity): VenueTravelTimeResource
    {
        return VenueTravelTimeResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, VenueTravelTimeResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(VenueTravelTime::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $seasonId = $this->uuidQueryParam($request, 'seasonId');
            if (null !== $seasonId) {
                $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
            }
            // A travel-time bracket is symmetric: ?venueId= matches EITHER side.
            $venueId = $this->uuidQueryParam($request, 'venueId');
            if (null !== $venueId) {
                $qb->andWhere('e.venueAId = :venueId OR e.venueBId = :venueId')->setParameter('venueId', $venueId);
            }
        }

        // UUID PK tiebreaker for stable offset pagination (TeamLink idiom).
        $qb->orderBy('e.createdAt', 'ASC')->addOrderBy('e.id', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)->setMaxResults($limit);
        }

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
