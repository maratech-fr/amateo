<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Fixture;
use App\Service\FixtureVenueLossMarker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-52 (RMM-10) — le pas de cascade « le gymnase supprimé emporte la salle de ses matchs ».
 *
 * Remplace le {@see ClearFieldStep} générique sur `Fixture.venueId` : au lieu de seulement
 * vider `venueId`, il DÉLÈGUE à {@see FixtureVenueLossMarker} — le match repasse « à placer »
 * avec la raison persistante `venue_lost` et son statut remis à UNPLACED, MÊME état final que
 * la gâchette de validation. `count()` reste identique (les matchs de ce gymnase), donc
 * l'annonce (`venue_fixture`) ne bouge pas d'un pixel.
 *
 * ⚠ Le match SURVIT (sa salle est optionnelle) : c'est pourquoi la décision fondateur laisse la
 * suppression du gymnase passer au lieu de la refuser — mais un match déjà déclaré à la
 * fédération devra être re-soumis, ce que `DeletionImpactCounter` annonce à part (`declaredFixtures`).
 */
final readonly class FixtureVenueLossStep implements CascadeStep
{
    public function __construct(private ImpactLabel $label) {}

    public function label(): ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        return \count($this->fixtureIdsOnVenue($entityManager, $target));
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        FixtureVenueLossMarker::markWith($entityManager, $this->fixtureIdsOnVenue($entityManager, $target));
    }

    /**
     * @return list<string>
     */
    private function fixtureIdsOnVenue(EntityManagerInterface $entityManager, DeletionTarget $target): array
    {
        /** @var list<string> $ids */
        $ids = $entityManager->createQueryBuilder()
            ->select('f.id')
            ->from(Fixture::class, 'f')
            ->where('f.venueId = :venueId')
            ->andWhere('f.clubId = :clubId')
            ->andWhere('f.seasonId = :seasonId')
            ->setParameter('venueId', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId)
            ->getQuery()
            ->getSingleColumnResult();

        return $ids;
    }
}
