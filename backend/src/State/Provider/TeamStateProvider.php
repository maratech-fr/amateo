<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\TeamResource;
use App\Entity\Team;
use App\Plans\PlanComposition;
use App\Service\TeamEngagementGuard;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProvider<Team, TeamResource>
 */
class TeamStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly TeamEngagementGuard $teamEngagementGuard,
        private readonly PlanComposition $planComposition,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    /**
     * Enrichit le(s) DTO avec `isEngaged` en UNE requête groupée — un EXISTS par DTO
     * N+1-erait la collection des équipes (patron de ScheduleStateProvider). La règle
     * elle-même vit dans TeamEngagementGuard, celui qui refuse les écritures : le
     * contrat de lecture ne la redéfinit pas, il la consulte.
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = parent::provide($operation, $uriVariables, $context);
        if (null === $result) {
            return null;
        }

        /** @var iterable<TeamResource> $dtos */
        $dtos = $result instanceof TeamResource ? [$result] : $result;
        $ids = [];
        foreach ($dtos as $dto) {
            $ids[] = $dto->id;
        }
        if ([] === $ids) {
            return $result;
        }

        $engaged = $this->teamEngagementGuard->engagedTeamIds($ids);
        foreach ($dtos as $dto) {
            $dto->isEngaged = isset($engaged[$dto->id]);
        }

        return $result;
    }

    protected function getEntityClass(): string
    {
        return Team::class;
    }

    /**
     * Honors the ?seasonId= and ?isActive= query params documented by the
     * #[ApiFilter] attributes on TeamResource (the custom provider bypasses API
     * Platform's built-in Doctrine filters, so they are applied here — BCK-05).
     * These narrow the set but do not bound it to a single parent → return false
     * so the default pagination still applies.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        $seasonId = $request instanceof Request ? $this->uuidQueryParam($request, 'seasonId') : null;
        if (null !== $seasonId) {
            $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
        }

        // Only a present, recognized boolean ('true'/'false'/'1'/'0'/…) filters.
        // Guard the raw string first: an absent param must skip the filter — NOT
        // fall through to filter_var(null, …), which returns false and would
        // silently apply "isActive = false" to every unfiltered listing.
        $isActiveRaw = $request?->query->get('isActive');
        if (\is_string($isActiveRaw) && '' !== $isActiveRaw) {
            $isActive = filter_var($isActiveRaw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
            if (null !== $isActive) {
                $qb->andWhere('e.isActive = :isActive')->setParameter('isActive', $isActive);
            }
        }

        return false;
    }

    /**
     * @param Team $entity
     */
    protected function mapEntityToOutput(object $entity): TeamResource
    {
        return TeamResource::fromEntity($entity, $this->planComposition->socleLinesOf([$entity->getId()])[$entity->getId()]);
    }
}
