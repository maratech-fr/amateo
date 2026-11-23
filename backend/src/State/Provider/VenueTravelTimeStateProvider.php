<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenueTravelTimeResource;
use App\Entity\PlaceTravelTime;
use App\Repository\PlaceTravelTimeRepository;
use LogicException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Les temps de trajet du club servis pour une saison : le temps vit au club entre deux lieux, chaque
 * saison le sert nommé par SES gymnases, rangé selon eux ({@see PlaceTravelTimeRepository::forSeason}).
 * Un temps dont un lieu n'a pas de gymnase dans la saison n'y paraît pas (ni en liste, ni en lecture).
 * La saison servie est celle de la requête (`X-Season-Id`, ou la saison courante), ou `?seasonId=`.
 *
 * @extends AbstractStateProvider<PlaceTravelTime, VenueTravelTimeResource>
 */
class VenueTravelTimeStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return PlaceTravelTime::class;
    }

    /**
     * @param PlaceTravelTime $entity
     */
    protected function mapEntityToOutput(object $entity): VenueTravelTimeResource
    {
        foreach ($this->served($entity->getClubId()) as $served) {
            if ($served['row']->getId() === $entity->getId()) {
                return VenueTravelTimeResource::fromEntity($entity, $served['venueAId'], $served['venueBId']);
            }
        }

        throw new LogicException('Ce temps de trajet n\'est pas servi dans la saison demandée.');
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, VenueTravelTimeResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        if (null === $clubId) {
            return [];
        }

        $request = $this->requestStack->getCurrentRequest();
        // A travel time is symmetric: ?venueId= matches EITHER side.
        $venueId = $request instanceof Request ? $this->uuidQueryParam($request, 'venueId') : null;
        $outputs = [];
        foreach ($this->served($clubId) as $served) {
            if (null !== $venueId && $venueId !== $served['venueAId'] && $venueId !== $served['venueBId']) {
                continue;
            }
            $outputs[] = VenueTravelTimeResource::fromEntity($served['row'], $served['venueAId'], $served['venueBId']);
        }

        if ($this->pagination->isEnabled($operation, $context)) {
            return \array_slice($outputs, $this->pagination->getOffset($operation, $context), $this->pagination->getLimit($operation, $context));
        }

        return $outputs;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    protected function provideItem(array $uriVariables, ?string $clubId): ?VenueTravelTimeResource
    {
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || '' === $id || null === $clubId) {
            return null;
        }

        foreach ($this->served($clubId) as $served) {
            if ($served['row']->getId() === $id) {
                return VenueTravelTimeResource::fromEntity($served['row'], $served['venueAId'], $served['venueBId']);
            }
        }

        return null;
    }

    /** @return list<array{row: PlaceTravelTime, venueAId: string, venueBId: string}> */
    private function served(string $clubId): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $seasonId = $request instanceof Request ? ($this->uuidQueryParam($request, 'seasonId') ?? $request->attributes->get('_season_id')) : null;
        if (!\is_string($seasonId) || '' === $seasonId) {
            return [];
        }
        $repository = $this->entityManager->getRepository(PlaceTravelTime::class);

        return $repository->forSeason($clubId, $seasonId);
    }
}
