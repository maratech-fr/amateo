<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\FixtureResource;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Service\Basketball\VenueAliasResolver;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractStateProvider<Fixture, FixtureResource>
 */
class FixtureStateProvider extends AbstractStateProvider
{
    private VenueAliasResolver $venueAliasResolver;

    #[Required]
    public function setVenueAliasResolver(VenueAliasResolver $venueAliasResolver): void
    {
        $this->venueAliasResolver = $venueAliasResolver;
    }

    protected function getEntityClass(): string
    {
        return Fixture::class;
    }

    /**
     * A custom provider bypasses API Platform's Doctrine SearchFilter, so the
     * declared filters are applied by hand (same as TeamStateProvider). Returns
     * false: partial filters, the result stays paginated.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        foreach (['seasonId', 'teamId', 'competitionId', 'homeAway', 'status'] as $field) {
            $value = $request?->query->get($field);
            if (\is_string($value) && '' !== $value) {
                $qb->andWhere(\sprintf('e.%s = :%s', $field, $field))->setParameter($field, $value);
            }
        }

        return false;
    }

    /**
     * @param Fixture $entity
     */
    protected function mapEntityToOutput(object $entity): FixtureResource
    {
        $output = FixtureResource::fromEntity($entity);
        // D6 — proposition floue d'un gymnase pour un domicile importé encore sans
        // salle. Lecture seule, jamais un placement (venue mémoïsé par requête).
        if (FixtureHomeAway::HOME === $entity->getHomeAway() && null === $entity->getVenueId() && null !== $entity->getFbiVenueLabel()) {
            $output->suggestedVenueId = $this->venueAliasResolver->suggest($entity->getFbiVenueLabel());
        }

        return $output;
    }
}
