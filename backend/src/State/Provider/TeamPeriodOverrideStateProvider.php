<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\TeamPeriodOverrideResource;
use App\Entity\PlanTeam;
use App\Plans\PlanComposition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Les réglages d'équipes d'une période : les lignes d'un plan de période qui diffèrent du socle ou qu'un geste nommé a
 * posées (lot 4, PR 3a). Une ligne du socle n'est jamais servie par cette route.
 *
 * @extends AbstractStateProvider<PlanTeam, TeamPeriodOverrideResource>
 */
class TeamPeriodOverrideStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly PlanComposition $planComposition,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    protected function provideItem(array $uriVariables, ?string $clubId): ?object
    {
        $line = $this->entityManager->find(PlanTeam::class, $uriVariables['id'] ?? '');
        // Une ligne du socle n'est pas un réglage de période : introuvable par cette route.
        if (!$line instanceof PlanTeam || (null !== $clubId && $line->getClubId() !== $clubId) || $this->soclePlanService()->isSeasonPlan($line->getSchedulePlanId())) {
            return null;
        }

        return $this->mapEntityToOutput($line);
    }

    protected function getEntityClass(): string
    {
        return PlanTeam::class;
    }

    /**
     * @param PlanTeam $entity
     */
    protected function mapEntityToOutput(object $entity): TeamPeriodOverrideResource
    {
        return TeamPeriodOverrideResource::fromEntity($entity, $this->planComposition->socleLinesOf([$entity->getTeamId()])[$entity->getTeamId()]);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, TeamPeriodOverrideResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(PlanTeam::class, 'e')
            // Un réglage de période : une ligne qui diffère du socle, ou qu'un geste nommé a posée (son origine, p.ex. une
            // doléance transférée à la valeur du socle). Jamais la ligne du socle, qui ne porte aucune origine.
            ->andWhere('(e.source IS NOT NULL OR ' . PlanComposition::DIFFERS_FROM_SOCLE_DQL . ')');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // Les réglages se consultent toujours par période ; une équipe resserre encore.
            $schedulePlanId = $this->uuidQueryParam($request, 'schedulePlanId');
            if (null !== $schedulePlanId) {
                $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $schedulePlanId);
            }

            $teamId = $this->uuidQueryParam($request, 'teamId');
            if (null !== $teamId) {
                $qb->andWhere('e.teamId = :teamId')->setParameter('teamId', $teamId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
