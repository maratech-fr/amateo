<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\TeamCoachRole;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamCoachInput
{
    // BCK-39 — les deux identifiants sont des UUID (précédent : CalendarEntryInput::$parentEntryId,
    // CoachWishInput) : un identifiant mal formé est refusé 422 à la validation, jamais porté
    // tel quel jusqu'à une requête Doctrine.
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Assert\Choice(callback: [TeamCoachRole::class, 'values'])]
    #[Groups(['write'])]
    public ?string $role = null;

    #[Groups(['write'])]
    public ?bool $isRequired = null;
}
