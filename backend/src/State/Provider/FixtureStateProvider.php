<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\FixtureResource;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Repository\FixtureRepository;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\OpponentTravelProjection;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractStateProvider<Fixture, FixtureResource>
 */
class FixtureStateProvider extends AbstractStateProvider
{
    private VenueAliasResolver $venueAliasResolver;

    private VenueLabelNormalizer $labelNormalizer;

    private OpponentTravelProjection $travelProjection;

    private FixtureRepository $fixtures;

    #[Required]
    public function setVenueAliasResolver(VenueAliasResolver $venueAliasResolver): void
    {
        $this->venueAliasResolver = $venueAliasResolver;
    }

    #[Required]
    public function setLabelNormalizer(VenueLabelNormalizer $labelNormalizer): void
    {
        $this->labelNormalizer = $labelNormalizer;
    }

    #[Required]
    public function setTravelProjection(OpponentTravelProjection $travelProjection): void
    {
        $this->travelProjection = $travelProjection;
    }

    #[Required]
    public function setFixtureRepository(FixtureRepository $fixtures): void
    {
        $this->fixtures = $fixtures;
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
        // Grain équipe (P2-54) : la clé d'équipe = le libellé adverse NORMALISÉ, calculée
        // SERVEUR (foyer unique) et servie, pour que le front joigne le trajet par équipe
        // sans jamais re-normaliser un libellé.
        $teamKey = $this->labelNormalizer->normalize(trim($entity->getOpponentLabel()));
        $output->opponentTeamKey = '' === $teamKey ? null : $teamKey;
        // D6 — proposition floue d'un gymnase pour un domicile importé encore sans
        // salle. Lecture seule, jamais un placement (venue mémoïsé par requête).
        if (FixtureHomeAway::HOME === $entity->getHomeAway() && null === $entity->getVenueId() && null !== $entity->getFbiVenueLabel()) {
            $output->suggestedVenueId = $this->venueAliasResolver->suggest($entity->getFbiVenueLabel());
        }

        return $output;
    }

    /**
     * Le trajet d'une rencontre EXTÉRIEURE (`awayTravel`) est DÉRIVÉ de la rencontre. On le
     * pose EN BATCH : la projection ({@see OpponentTravelProjection}) lit le cache par origine
     * en un seul lot (zéro N+1) et calcule le repli « gymnase le plus fréquent » sur TOUTES les
     * rencontres AWAY de la saison — jamais sur la seule page, sinon le repli varierait d'une
     * page à l'autre. On assigne ensuite le détail à chaque sortie par id.
     *
     * @param array<int, FixtureResource> $outputs
     */
    protected function decorateCollection(array $outputs): void
    {
        if ([] === $outputs) {
            return;
        }
        $seasonId = $outputs[0]->seasonId;
        if ('' === $seasonId) {
            return;
        }
        $details = $this->travelProjection->awayTravelByFixtureId($seasonId, $this->fixtures->findAwayBySeason($seasonId));
        foreach ($outputs as $output) {
            $output->awayTravel = $details[$output->id] ?? null;
        }
    }
}
