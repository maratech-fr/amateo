<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenueOccupancyResource;
use App\Entity\Venue;
use App\Entity\VenueOccupancy;
use Symfony\Component\HttpFoundation\Request;

/**
 * Les occupations d'une couche de grille (saison quand `schedulePlanId` est absent, sinon celle du
 * plan de période), bornées au club par la requête elle-même, en plus du filtre Doctrine et de la
 * sécurité par ligne.
 *
 * Seules les occupations dont le gymnase EXISTE sont listées : celles d'un gymnase absent de la
 * version chargée dorment en base et reviennent avec lui. La grille, le compte du menu d'export et
 * l'export n'ont ainsi aucun code propre pour les écarter.
 *
 * @extends AbstractStateProvider<VenueOccupancy, VenueOccupancyResource>
 */
class VenueOccupancyStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return VenueOccupancy::class;
    }

    /**
     * @param VenueOccupancy $entity
     */
    protected function mapEntityToOutput(object $entity): VenueOccupancyResource
    {
        return VenueOccupancyResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, VenueOccupancyResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(VenueOccupancy::class, 'e')
            ->innerJoin(Venue::class, 'v', 'WITH', 'v.id = e.venueId');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        $schedulePlanId = null;
        if ($request instanceof Request) {
            $venueId = $this->uuidQueryParam($request, 'venueId');
            if (null !== $venueId) {
                $qb->andWhere('e.venueId = :venueId')->setParameter('venueId', $venueId);
            }
            $schedulePlanId = $this->uuidQueryParam($request, 'schedulePlanId');
        }

        // Absent ⇒ la grille de SAISON seule : l'écran de saison ne montre jamais les copies d'une période.
        if (null !== $schedulePlanId) {
            $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $schedulePlanId);
        } else {
            $qb->andWhere('e.schedulePlanId IS NULL');
        }

        // Ordre total déterministe (l'identifiant départage) : sans lui, la pagination par décalage est instable.
        $qb->orderBy('e.dayOfWeek', 'ASC')->addOrderBy('e.startTime', 'ASC')->addOrderBy('e.id', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)->setMaxResults($limit);
        }

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
