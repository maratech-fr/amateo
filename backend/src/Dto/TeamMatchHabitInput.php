<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\MatchWeek;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamMatchHabitInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $kickoffTime = null;

    // `NotBlank(allowNull: true)` en plus d'`Uuid` : le validateur Uuid laisse
    // passer la chaîne VIDE, qui atteindrait la colonne uuid en base (22P02).
    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    // Semaine d'alternance du créneau idéal (A/B/toutes — P4-271). Omise ⇒ `ALL`
    // (le processor pose le défaut), pour qu'un ancien payload reste valide.
    #[Assert\Choice(callback: [MatchWeek::class, 'values'])]
    #[Groups(['write'])]
    public ?string $week = null;
}
