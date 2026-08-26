<?php

declare(strict_types=1);

namespace App\Service;

use App\Deletion\FixtureVenueLossStep;
use App\Entity\Fixture;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-52 (RMM-10) — LE foyer unique du DÉPOINTAGE « le gymnase n'est plus affilié au club ».
 *
 * Un match qui perd sa salle repasse « à placer » : `venueId` NULL, `status` UNPLACED,
 * `placementSource` NULL (plus aucune ancre — il redevient plaçable), et la raison
 * PERSISTANTE `unplacedReason = venue_lost` (distincte de la raison volatile d'auto-placement).
 * La DATE et l'HEURE de coup d'envoi sont CONSERVÉES — repère modifiable pour le gestionnaire
 * (décision fondateur), jamais une salle réelle.
 *
 * ⚠ UPDATE DQL de masse : il CONTOURNE délibérément les listeners de cycle de vie et le verrou
 * optimiste (`version`), comportement HÉRITÉ du dépointage historique et assumé — le dépointage
 * n'est pas une édition métier, c'est une conséquence d'une salle disparue.
 *
 * Deux gâchettes le consomment, à parité stricte (MÊME état final) : la VALIDATION d'un planning
 * (les matchs pointant une salle disparue) et la SUPPRESSION d'un gymnase (via
 * {@see FixtureVenueLossStep}). L'effacement de la raison, lui, vit dans
 * {@see Fixture::setVenueId} — reposer une salle éteint `venue_lost`.
 */
final class FixtureVenueLossMarker
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * L'UNIQUE endroit où vit ce DQL. Statique pour être atteignable par le pas de cascade
     * {@see FixtureVenueLossStep}, qui ne reçoit qu'un EntityManager (il n'est
     * pas un service injectable) — sans dupliquer la requête ailleurs.
     *
     * @param list<string> $fixtureIds
     */
    public static function markWith(EntityManagerInterface $entityManager, array $fixtureIds): int
    {
        if ([] === $fixtureIds) {
            return 0;
        }

        return (int) $entityManager->createQuery(
            'UPDATE App\Entity\Fixture f SET f.venueId = NULL, f.status = :unplaced, '
            . 'f.placementSource = NULL, f.unplacedReason = :reason WHERE f.id IN (:ids)',
        )
            ->setParameter('unplaced', FixtureStatus::UNPLACED)
            ->setParameter('reason', FixtureUnplacedReason::VENUE_LOST)
            ->setParameter('ids', $fixtureIds)
            ->execute();
    }

    /**
     * Dépointe les matchs nommés. Rend le nombre de lignes touchées.
     *
     * @param list<string> $fixtureIds déjà résolus dans la portée tenant par l'appelant
     */
    public function mark(array $fixtureIds): int
    {
        return self::markWith($this->entityManager, $fixtureIds);
    }
}
