<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\ConstraintPeriodOverrideResource;
use App\Entity\PlanConstraint;
use App\Plans\PlanComposition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Les coches de règle d'une période : les lignes d'un plan de période qui diffèrent du défaut de sa nature (lot 4,
 * PR 4). Une ligne au défaut n'est pas un réglage, la liste ne la rend pas.
 *
 * @extends AbstractStateProvider<PlanConstraint, ConstraintPeriodOverrideResource>
 */
class ConstraintPeriodOverrideStateProvider extends AbstractStateProvider
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

    protected function getEntityClass(): string
    {
        return PlanConstraint::class;
    }

    /**
     * @param PlanConstraint $entity
     */
    protected function mapEntityToOutput(object $entity): ConstraintPeriodOverrideResource
    {
        return ConstraintPeriodOverrideResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, ConstraintPeriodOverrideResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(PlanConstraint::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // Les coches sont toujours consultées par période ; un constraintId affine encore.
            $schedulePlanId = $this->uuidQueryParam($request, 'schedulePlanId');
            if (null !== $schedulePlanId) {
                $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $schedulePlanId);
            }

            $constraintId = $this->uuidQueryParam($request, 'constraintId');
            if (null !== $constraintId) {
                $qb->andWhere('e.constraintId = :constraintId')->setParameter('constraintId', $constraintId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        /** @var list<PlanConstraint> $lines */
        $lines = $qb->getQuery()->getResult();

        return array_map([$this, 'mapEntityToOutput'], $this->planComposition->ruleLinesDifferingFromDefault($lines));
    }
}
