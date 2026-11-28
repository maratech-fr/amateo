<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\CoachWishResource;
use App\Entity\CoachWish;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<CoachWish, CoachWishResource>
 */
class CoachWishStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return CoachWish::class;
    }

    /**
     * @param CoachWish $entity
     */
    protected function mapEntityToOutput(object $entity): CoachWishResource
    {
        return CoachWishResource::fromEntity($entity, $this->socleColdChangedAt($entity->getSeasonId()));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, CoachWishResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(CoachWish::class, 'e');

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

        // Groupé par semaine côté UI ; l'id départage pour un ordre déterministe.
        $qb->orderBy('e.weekStart', 'ASC')->addOrderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }

    /** L'instant de la dernière validation du planning de saison qui a changé du froid (lot 5), ou null. */
    private function socleColdChangedAt(string $seasonId): ?DateTimeImmutable
    {
        $changedAt = $this->entityManager->getConnection()->fetchOne(
            'SELECT cold_changed_at FROM schedule_plan WHERE season_id = ? AND type = \'SEASON\'',
            [$seasonId],
        );

        return \is_string($changedAt) ? new DateTimeImmutable($changedAt) : null;
    }
}
