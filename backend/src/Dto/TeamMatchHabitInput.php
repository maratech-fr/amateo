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
    // passer la chaîne VIDE, qui atteindrait la colonne uuid en base (22P02). `null`
    // est ACCEPTÉ et signifie « aucun gymnase » — sur un PUT il RETIRE le gymnase du
    // créneau idéal (idiome full-replace, P4-271) ; le processor traite null comme ''.
    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    // Semaine d'alternance du créneau idéal (A ou B — P4-271). Omise ⇒ `A`
    // (le processor pose le défaut), pour qu'un ancien payload reste valide.
    #[Assert\Choice(callback: [MatchWeek::class, 'values'])]
    #[Groups(['write'])]
    public ?string $week = null;
}
