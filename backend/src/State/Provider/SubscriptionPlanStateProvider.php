<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\SubscriptionPlanResource;
use App\Entity\SubscriptionPlan;
use App\Entity\SubscriptionPlanTier;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<SubscriptionPlan, SubscriptionPlanResource>
 */
class SubscriptionPlanStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return SubscriptionPlan::class;
    }

    // La bêta est une offre superadmin-only : jamais dans le catalogue public (collection).
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $qb->andWhere('e.code != :betaCode')->setParameter('betaCode', 'beta');

        return false;
    }

    /**
     * … et jamais non plus en GET item (finding revue sécu P1-3, fondateur POUR) : masquer la
     * bêta de la collection tout en la servant par id resterait une invisibilité en trompe-l'œil.
     *
     * @param array<string, mixed> $uriVariables
     */
    protected function provideItem(array $uriVariables, ?string $clubId): ?object
    {
        $output = parent::provideItem($uriVariables, $clubId);
        if ($output instanceof SubscriptionPlanResource && 'beta' === $output->code) {
            return null;
        }

        return $output;
    }

    /**
     * @param SubscriptionPlan $entity
     */
    protected function mapEntityToOutput(object $entity): SubscriptionPlanResource
    {
        /** @var list<SubscriptionPlanTier> $tiers */
        $tiers = $this->entityManager->getRepository(SubscriptionPlanTier::class)->findBy(['planId' => $entity->getId()], ['position' => 'ASC']);

        return SubscriptionPlanResource::fromEntity($entity, $tiers);
    }
}
