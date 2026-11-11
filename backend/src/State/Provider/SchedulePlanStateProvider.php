<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\SchedulePlanResource;
use App\Entity\Club;
use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\Service\ClosureWindowPlanner;
use App\Service\ClubDay;
use App\Service\PeriodPlanAlertResolver;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @extends AbstractStateProvider<SchedulePlan, SchedulePlanResource>
 */
class SchedulePlanStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly ClosureWindowPlanner $closureWindowPlanner,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PeriodPlanAlertResolver $alertResolver,
        private readonly ClubDay $clubDay,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    protected function getEntityClass(): string
    {
        return SchedulePlan::class;
    }

    /**
     * @param SchedulePlan $entity
     */
    protected function mapEntityToOutput(object $entity): SchedulePlanResource
    {
        $dto = SchedulePlanResource::fromEntity($entity);

        // Prolonger une fermeture : les prédicats de fenêtre ne concernent QU'un plan CLOSURE.
        if (SchedulePlanType::CLOSURE === $entity->getType()) {
            $dto->windowLockedReason = $this->closureWindowPlanner->windowLockedReason($entity);
            $dto->windowEditable = null === $dto->windowLockedReason;
            $dto->minEndDate = $this->closureWindowPlanner->minEndDate($entity);
            $dto->closureTitle = $this->closureWindowPlanner->closureTitle($entity);
        }

        return $dto;
    }

    protected function provideItem(array $uriVariables, ?string $clubId): ?object
    {
        $dto = parent::provideItem($uriVariables, $clubId);
        if ($dto instanceof SchedulePlanResource) {
            $this->decorateCollection([$dto]);
        }

        return $dto;
    }

    /**
     * Validation automatique d'un planning de période — `autoValidated`, `alert`, `radarDismissed`
     * calculés EN LOT : les faits de tous les plans de période en une requête, puis le résolveur
     * pur, au jour du club (fuseau, horloge simulée d'une démo). L'item passe par ce même chemin.
     *
     * @param array<int, SchedulePlanResource> $outputs
     */
    protected function decorateCollection(array $outputs): void
    {
        $periodPlanIds = [];
        foreach ($outputs as $dto) {
            if (SchedulePlanType::SEASON !== $dto->type) {
                $periodPlanIds[] = $dto->id;
            }
        }
        if ([] === $periodPlanIds) {
            return;
        }

        $clubId = $this->requestStack->getCurrentRequest()?->attributes->get('_club_id');
        $club = \is_string($clubId) && '' !== $clubId ? $this->entityManager->find(Club::class, $clubId) : null;
        if (!$club instanceof Club) {
            return;
        }

        $resolved = $this->alertResolver->forPlans(
            $this->schedulePlanProvisioner->periodPlanFacts($periodPlanIds),
            $this->clubDay->todayFor($club),
        );
        foreach ($outputs as $dto) {
            $verdict = $resolved[$dto->id] ?? null;
            if (null === $verdict) {
                continue;
            }
            $dto->autoValidated = $verdict['autoValidated'];
            $dto->alert = $verdict['alert'];
            $dto->radarDismissed = $verdict['radarDismissed'];
        }
    }

    /**
     * Club scoping = tenant_filter (SchedulePlan owns club_id); season scoping =
     * season_filter (owns season_id), so the collection is already the active
     * season's plans — no seasonId param (it would AND-collide with the filter).
     * Here we only narrow by period and type within that season.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return false;
        }

        $calendarEntryId = $this->uuidQueryParam($request, 'calendarEntryId');
        if (null !== $calendarEntryId) {
            $qb->andWhere('e.calendarEntryId = :calendarEntryId')->setParameter('calendarEntryId', $calendarEntryId);
        }

        $type = $request->query->get('type');
        if (\is_string($type) && '' !== $type) {
            $typeEnum = SchedulePlanType::tryFrom($type);
            if (null === $typeEnum) {
                throw new BadRequestHttpException(\sprintf('Invalid SchedulePlan type "%s".', $type));
            }
            $qb->andWhere('e.type = :type')->setParameter('type', $typeEnum);
        }

        return false;
    }
}
