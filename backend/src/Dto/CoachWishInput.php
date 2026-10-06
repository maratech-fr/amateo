<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Écriture d'une doléance coach (feature #10, lot C1). L'ancre (calendarEntryId, teamId,
 * weekStart) identifie la ligne : au PUT elle n'est pas remappée, seuls le coach, le
 * volume, les jours, le commentaire et la coche bougent (cf. le processor).
 */
class CoachWishInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $calendarEntryId = null;

    /** Le lundi de la semaine visée, format Y-m-d (validé lundi + fenêtre côté processor). */
    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $weekStart = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    /**
     * Requis à la CRÉATION (« au nom d'un coach », imposé par le processor), mais NULLABLE
     * en écriture : une doléance dé-attribuée (le coach a été supprimé, coachId=null)
     * doit pouvoir être re-cochée « traité » ou éditée sans qu'un NotBlank la bloque
     * (revue #10 C1). NotBlank retiré, Uuid ignore null.
     */
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: 7)]
    #[Groups(['write'])]
    public ?int $slotsWanted = 0;

    /**
     * Jours indisponibles, jours ISO 1–7 (1 = lundi), sans doublon.
     *
     * @var list<int>
     */
    #[Assert\All([new Assert\Type('integer'), new Assert\Range(min: 1, max: 7)])]
    #[Assert\Unique]
    #[Assert\Count(max: 7)]
    #[Groups(['write'])]
    public array $unavailableDays = [];

    /**
     * Jours souhaités, jours ISO 1–7 (1 = lundi), sans doublon. Informatif : aucun effet
     * solveur. Un jour ne peut pas être à la fois souhaité ET indisponible.
     *
     * @var list<int>
     */
    #[Assert\All([new Assert\Type('integer'), new Assert\Range(min: 1, max: 7)])]
    #[Assert\Unique]
    #[Assert\Count(max: 7)]
    #[Groups(['write'])]
    public array $wishedDays = [];

    #[Assert\Length(max: 1000)]
    #[Groups(['write'])]
    public ?string $comment = null;

    #[Groups(['write'])]
    public bool $done = false;
}
