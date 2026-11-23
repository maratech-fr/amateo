<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\VenueOccupancy;
use App\Entity\VenueTrainingSlot;
use App\Support\WeeklySlot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La règle de chevauchement d'une grille de gymnase : deux éléments d'une même COUCHE (la saison,
 * ou la grille d'un plan de période) ne partagent jamais de temps le même jour — créneau contre
 * créneau, créneau contre occupation, occupation contre occupation. Bornes jointives acceptées
 * (16:15 touche 14:00–16:15 sans la chevaucher). Deux couches différentes ne se gênent jamais : elles
 * ne sont jamais servies ensemble.
 *
 * Lu sous le filtre de club (Doctrine) : seule la grille du club courant compte.
 */
final readonly class VenueGridOverlap
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Le premier élément heurté, créneaux d'abord, puis occupations ; null si la plage est libre.
     * `$exceptSlotId` / `$exceptOccupancyId` : l'élément qu'on modifie ne se heurte pas lui-même.
     */
    public function firstConflict(string $venueId, int $dayOfWeek, int $startMinutes, int $durationMinutes, string $schedulePlanId, ?string $exceptSlotId = null, ?string $exceptOccupancyId = null): ?VenueGridConflict
    {
        $end = $startMinutes + $durationMinutes;
        $criteria = ['venueId' => $venueId, 'dayOfWeek' => $dayOfWeek, 'schedulePlanId' => $schedulePlanId];

        /** @var list<VenueTrainingSlot> $slots */
        $slots = $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy($criteria, ['startTime' => 'ASC']);
        foreach ($slots as $slot) {
            if ($slot->getId() === $exceptSlotId) {
                continue;
            }
            $conflict = $this->conflict(VenueGridConflict::SLOT, $dayOfWeek, $slot->getStartTime(), $slot->getDurationMinutes(), null, $startMinutes, $end);
            if ($conflict instanceof VenueGridConflict) {
                return $conflict;
            }
        }

        /** @var list<VenueOccupancy> $occupancies */
        $occupancies = $this->entityManager->getRepository(VenueOccupancy::class)->findBy($criteria, ['startTime' => 'ASC']);
        foreach ($occupancies as $occupancy) {
            if ($occupancy->getId() === $exceptOccupancyId) {
                continue;
            }
            $conflict = $this->conflict(VenueGridConflict::OCCUPANCY, $dayOfWeek, $occupancy->getStartTime(), $occupancy->getDurationMinutes(), $occupancy->getLabel(), $startMinutes, $end);
            if ($conflict instanceof VenueGridConflict) {
                return $conflict;
            }
        }

        return null;
    }

    /** @param VenueGridConflict::SLOT|VenueGridConflict::OCCUPANCY $kind */
    private function conflict(string $kind, int $dayOfWeek, DateTimeImmutable $otherStartTime, int $otherDuration, ?string $label, int $start, int $end): ?VenueGridConflict
    {
        $otherStart = WeeklySlot::minutesOf($otherStartTime);
        $otherEnd = $otherStart + $otherDuration;
        if (!WeeklySlot::intervalsOverlap($start, $end, $otherStart, $otherEnd)) {
            return null;
        }

        return new VenueGridConflict($kind, $dayOfWeek, WeeklySlot::clock($otherStart), WeeklySlot::clock($otherEnd), $label);
    }
}
