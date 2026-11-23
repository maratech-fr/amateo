<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\TenantOwnedInterface;
use App\Plans\SoclePlan;
use App\Service\SeasonResolver;
use ArrayIterator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use LogicException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @template TEntity of object
 * @template TOutput of object
 *
 * @implements ProviderInterface<TOutput>
 */
abstract class AbstractStateProvider implements ProviderInterface
{
    /** Le plan de saison qui porte le socle (lot 4) — set via #[Required]. */
    protected ?SoclePlan $soclePlan = null;

    protected ?SeasonResolver $seasonResolver = null;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly RequestStack $requestStack,
        protected readonly Pagination $pagination,
    ) {}

    #[Required]
    public function setSoclePlan(SoclePlan $soclePlan): void
    {
        $this->soclePlan = $soclePlan;
    }

    #[Required]
    public function setSeasonResolver(SeasonResolver $seasonResolver): void
    {
        $this->seasonResolver = $seasonResolver;
    }

    /**
     * @return TOutput|iterable<int, TOutput>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');

        if ($operation instanceof GetCollection) {
            return $this->provideCollection($operation, $context, $clubId);
        }

        return $this->provideItem($uriVariables, $clubId);
    }

    /**
     * Frontière de l'API (lot 4, jusqu'au lot 6) : sans `?schedulePlanId=`, une lecture vise le socle,
     * le plan de saison de la saison demandée (`$seasonId`, à défaut celle de la requête). Null quand
     * le club n'a aucune saison : il n'y a alors aucun socle à lire.
     */
    protected function soclePlanOfRead(?string $clubId, ?string $seasonId = null): ?string
    {
        if (null === $seasonId) {
            $request = $this->requestStack->getCurrentRequest();
            $selected = $request?->attributes->get('_season_id');
            $seasonId = \is_string($selected) && '' !== $selected
                ? $selected
                : (null === $clubId ? null : $this->seasonResolverService()->currentSeason($clubId)?->getId());
        }

        return null === $seasonId ? null : $this->soclePlanService()->idOf($seasonId);
    }

    /** Frontière de l'API : une ligne du socle se lit encore `schedulePlanId: null`. */
    protected function planToApi(string $planId, string $seasonId): ?string
    {
        return $this->soclePlanService()->toApi($planId, $seasonId);
    }

    protected function soclePlanService(): SoclePlan
    {
        return $this->soclePlan ?? throw new LogicException('SoclePlan non câblé : setSoclePlan() est requis.');
    }

    /**
     * @return class-string<TEntity>
     */
    abstract protected function getEntityClass(): string;

    /**
     * @param TEntity $entity
     *
     * @return TOutput
     */
    abstract protected function mapEntityToOutput(object $entity): object;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, TOutput>
     */
    /**
     * @param array<string, mixed> $context
     *
     * @return iterable<int, TOutput>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): iterable
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityClass(), 'e');

        // Subclasses may bound the collection from request query params (e.g. ?scheduleId=).
        // A bounded result set (all rows of one parent) bypasses the default 30-item pagination.
        $bounded = $this->applyRequestFilters($qb);

        // Deterministic order on the UUID PK, APPENDED after any subclass ordering so it is
        // always the final tiebreaker: without it offset pagination is unstable (Postgres
        // reshuffles rows sharing the subclass sort key between page requests) and
        // collectionAll's id-dedupe silently drops rows straddling a page boundary.
        $qb->addOrderBy('e.id', 'ASC');

        if ($bounded || !$this->pagination->isEnabled($operation, $context)) {
            $outputs = array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
            $this->decorateCollection($outputs);

            return $outputs;
        }

        $offset = $this->pagination->getOffset($operation, $context);
        $limit = $this->pagination->getLimit($operation, $context);
        $qb->setFirstResult($offset)->setMaxResults($limit);

        // Return a paginator (not a bare array) so hydra:totalItems reflects the
        // real row count, not just the page size, and hydra:view links appear
        // (BCK-05). DoctrinePaginator issues the COUNT (filters/RLS honored).
        $doctrinePaginator = new DoctrinePaginator($qb->getQuery(), fetchJoinCollection: false);
        $total = \count($doctrinePaginator);
        $items = array_map([$this, 'mapEntityToOutput'], iterator_to_array($doctrinePaginator));
        $this->decorateCollection($items);
        $currentPage = $limit > 0 ? (int) floor($offset / $limit) + 1 : 1;

        return new TraversablePaginator(new ArrayIterator($items), $currentPage, $limit, $total);
    }

    /**
     * Hook: decorate the mapped outputs of a collection IN BATCH (zero N+1), once all
     * items are known — for fields that can't be derived per item without a fan-out. Called
     * on both the bounded and the paginated paths. No-op by default.
     *
     * @param array<int, TOutput> $outputs
     */
    protected function decorateCollection(array $outputs): void
    {
        unset($outputs);
    }

    /**
     * Hook: add WHERE clauses derived from the current request query.
     * Return true when the filter bounds the result to a single parent
     * (so pagination is skipped and every matching row is returned).
     *
     * For entities that own a club_id, tenant scoping is applied by the
     * Doctrine tenant_filter (TenantFilterListener). Entities WITHOUT a
     * club_id (Club, User) are NOT covered by that filter and must bound
     * their own collection here — see ClubStateProvider (SEC-01).
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $uriVariables
     *
     * @return TOutput|null
     */
    protected function provideItem(array $uriVariables, ?string $clubId): ?object
    {
        $id = $uriVariables['id'] ?? null;
        if (!$id) {
            return null;
        }

        $entity = $this->entityManager->find($this->getEntityClass(), $id);
        if (!$entity) {
            return null;
        }

        if (null !== $clubId && $entity instanceof TenantOwnedInterface && $entity->getClubId() !== $clubId) {
            return null;
        }

        return $this->mapEntityToOutput($entity);
    }

    private function seasonResolverService(): SeasonResolver
    {
        return $this->seasonResolver ?? throw new LogicException('SeasonResolver non câblé : setSeasonResolver() est requis.');
    }
}
