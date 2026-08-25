<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\CompetitionResource;
use App\Entity\Competition;
use App\Repository\SharedCompetitionDeadlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProvider<Competition, CompetitionResource>
 */
class CompetitionStateProvider extends AbstractStateProvider
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly SharedCompetitionDeadlineRepository $sharedDeadlineRepository,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    protected function getEntityClass(): string
    {
        return Competition::class;
    }

    /** Custom provider bypasses the Doctrine SearchFilter → apply by hand. */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        foreach (['seasonId', 'teamId'] as $field) {
            $value = $request?->query->get($field);
            if (\is_string($value) && '' !== $value) {
                $qb->andWhere(\sprintf('e.%s = :%s', $field, $field))->setParameter($field, $value);
            }
        }

        return false;
    }

    /**
     * @param Competition $entity
     */
    protected function mapEntityToOutput(object $entity): CompetitionResource
    {
        // RMM-6 — the community default is joined by the federation competition id.
        // Only a PAIRED competition (ffbbCompetitionId non null) can carry one; the
        // shared table is GLOBAL, so this join is deliberately not tenant-scoped.
        $ffbbCompetitionId = $entity->getFfbbCompetitionId();
        $shared = null !== $ffbbCompetitionId
            ? $this->sharedDeadlineRepository->findOneByFfbbCompetitionId($ffbbCompetitionId)
            : null;

        return CompetitionResource::fromEntity($entity, $shared);
    }
}
