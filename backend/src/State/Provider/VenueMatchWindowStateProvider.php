<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenueMatchWindowResource;
use App\Entity\VenueMatchWindow;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<VenueMatchWindow, VenueMatchWindowResource>
 */
class VenueMatchWindowStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return VenueMatchWindow::class;
    }

    /**
     * @param VenueMatchWindow $entity
     */
    protected function mapEntityToOutput(object $entity): VenueMatchWindowResource
    {
        return VenueMatchWindowResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, VenueMatchWindowResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(VenueMatchWindow::class, 'e');

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

        // PR2 — les lignes de jour de semaine (appliesOnPublicHolidays=false) d'abord, la
        // ligne « Jours fériés » (dayOfWeek NULL) ensuite : trier d'abord sur le drapeau évite
        // toute ambiguïté NULL sur dayOfWeek (les lignes de semaine ont toutes un dayOfWeek).
        // UUID PK tiebreaker: dayOfWeek/startTime are not unique — without it,
        // offset pagination reshuffles rows across pages (VenueTrainingSlot idiom).
        $qb->orderBy('e.appliesOnPublicHolidays', 'ASC')
            ->addOrderBy('e.dayOfWeek', 'ASC')
            ->addOrderBy('e.openingDate', 'ASC')
            ->addOrderBy('e.startTime', 'ASC')
            ->addOrderBy('e.id', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)->setMaxResults($limit);
        }

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
