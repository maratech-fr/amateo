<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\SchedulePlanResource;
use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\Service\SchedulePlanStalenessResolver;
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
        private readonly SchedulePlanStalenessResolver $staleness,
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
        // La péremption est lue UNE fois par requête (ensemble mémoïsé des versions pointées du
        // club) : sur la collection, chaque plan est un O(1), pas une requête par ligne — anti-N+1.
        return SchedulePlanResource::fromEntity($entity, $this->staleness->stalenessFor($entity));
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
