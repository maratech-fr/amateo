<?php

declare(strict_types=1);

namespace App\ApiResource;

use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Lot 9 — une séance de groupe DÉJÀ PLACÉE d'un bloc de mutualisation : la ligne que la suppression
 * du bloc emporterait du planning (cascade `schedule_slot_template.shared_training_block_id`).
 * Lecture seule, dérivée des créneaux liés au bloc ; jamais persistée telle quelle. Sert à NOMMER à
 * l'écran ce que « supprimer le groupe » retire (équipe, jour, horaire, gymnase).
 */
class SharedTrainingBlockSession
{
    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public string $teamName = '';

    /** Jour ISO 1..7 (1 = lundi … 7 = dimanche). */
    #[Groups(['read'])]
    public int $dayOfWeek = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $startTime;

    /** Fin = début + durée du créneau (dérivée côté serveur). */
    #[Groups(['read'])]
    public DateTimeImmutable $endTime;

    #[Groups(['read'])]
    public string $venueName = '';

    public static function of(
        string $teamId,
        string $teamName,
        int $dayOfWeek,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        string $venueName,
    ): self {
        $session = new self;
        $session->teamId = $teamId;
        $session->teamName = $teamName;
        $session->dayOfWeek = $dayOfWeek;
        $session->startTime = $startTime;
        $session->endTime = $endTime;
        $session->venueName = $venueName;

        return $session;
    }
}
