<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\VenueTrainingSlot;
use DateTimeImmutable;

/**
 * La cible d'une suppression de CRÉNEAU de disponibilité.
 *
 * ⚑ Un créneau ne se supprime pas comme une salle, une équipe ou un coach : ses enfants ne
 * le citent JAMAIS par son id. Une réservation et le verrou qu'elle a matérialisé s'y
 * rattachent par le **triplet** (gymnase, jour, heure de début) — et par la COUCHE à laquelle
 * le créneau appartient. C'est pour ça qu'il ne rentrait pas dans le plan de {@see CascadePlan}
 * tel qu'il est né : ce plan ne parlait que de champs portant l'id.
 */
final readonly class SlotDeletionTarget extends DeletionTarget
{
    public function __construct(
        string $id,
        string $clubId,
        string $seasonId,
        public string $venueId,
        public int $dayOfWeek,
        public DateTimeImmutable $startTime,
        /** La COUCHE du créneau : son plan (le plan de saison pour la grille du socle). */
        public string $schedulePlanId,
    ) {
        parent::__construct($id, $clubId, $seasonId);
    }

    public static function of(VenueTrainingSlot $slot): self
    {
        return new self(
            $slot->getId(),
            $slot->getClubId(),
            $slot->getSeasonId(),
            $slot->getVenueId(),
            $slot->getDayOfWeek(),
            $slot->getStartTime(),
            $slot->getSchedulePlanId(),
        );
    }
}
