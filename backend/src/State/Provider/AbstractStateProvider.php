<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\TenantOwnedInterface;
use ArrayIterator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @template TEntity of object
 * @template TOutput of object
 *
 * @implements ProviderInterface<TOutput>
 */
abstract class AbstractStateProvider implements ProviderInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly RequestStack $requestStack,
        protected readonly Pagination $pagination,
    ) {}

    /**
     * @return TOutput|iterable<int, TOutput>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');

        if ($operation instanceof GetCollection) {
            return $this->provideCollection($operation, $context, $clubId);
        }

        return $this->provideItem($uriVariables, $clubId);
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
}
