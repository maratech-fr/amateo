<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\CompetitionResource;
use App\Entity\Competition;
use App\Entity\Engagement;
use App\Matches\CompetitionRegistry;
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
        private readonly CompetitionRegistry $competitionRegistry,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    protected function getEntityClass(): string
    {
        return Competition::class;
    }

    /**
     * Custom provider bypasses the Doctrine SearchFilter → apply by hand. `?teamId=` gives the
     * competitions where the team is engaged (the team lives on the engagement).
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $seasonId = $request?->query->get('seasonId');
        if (\is_string($seasonId) && '' !== $seasonId) {
            $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
        }
        $teamId = $request?->query->get('teamId');
        if (\is_string($teamId) && '' !== $teamId) {
            $qb->andWhere(\sprintf('EXISTS (SELECT eng.id FROM %s eng WHERE eng.competitionId = e.id AND eng.teamId = :teamId)', Engagement::class))
                ->setParameter('teamId', $teamId);
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

        return CompetitionResource::fromEntity($entity, $this->competitionRegistry->engagementsOf($entity), $shared);
    }
}
