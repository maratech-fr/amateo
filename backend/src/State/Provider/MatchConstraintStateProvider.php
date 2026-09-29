<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\MatchConstraintResource;
use App\Entity\MatchConstraint;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<MatchConstraint, MatchConstraintResource>
 */
class MatchConstraintStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return MatchConstraint::class;
    }

    /**
     * @param MatchConstraint $entity
     */
    protected function mapEntityToOutput(object $entity): MatchConstraintResource
    {
        return MatchConstraintResource::fromEntity($entity);
    }

    /**
     * The whole club rule set is ONE bounded list (a manager edits it all) →
     * ordered, unpaginated.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $qb->orderBy('e.ruleType', 'ASC')
            ->addOrderBy('e.createdAt', 'ASC');

        return true;
    }
}
