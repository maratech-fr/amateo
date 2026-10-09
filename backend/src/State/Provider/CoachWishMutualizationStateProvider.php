<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\CoachWishMutualizationResource;
use App\Entity\CoachWishMutualization;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<CoachWishMutualization, CoachWishMutualizationResource>
 */
class CoachWishMutualizationStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return CoachWishMutualization::class;
    }

    /**
     * @param CoachWishMutualization $entity
     */
    protected function mapEntityToOutput(object $entity): CoachWishMutualizationResource
    {
        return CoachWishMutualizationResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, CoachWishMutualizationResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(CoachWishMutualization::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // La todo-list se consulte toujours par période ; teamId affine.
            $calendarEntryId = $this->uuidQueryParam($request, 'calendarEntryId');
            if (null !== $calendarEntryId) {
                $qb->andWhere('e.calendarEntryId = :calendarEntryId')->setParameter('calendarEntryId', $calendarEntryId);
            }

            $teamId = $this->uuidQueryParam($request, 'teamId');
            if (null !== $teamId) {
                $qb->andWhere('e.teamId = :teamId')->setParameter('teamId', $teamId);
            }
        }

        $qb->orderBy('e.teamId', 'ASC')->addOrderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
