<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class CoachPlayerMembershipInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Groups(['write'])]
    #[Assert\Length(max: 120, maxMessage: 'Le poste ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $position = null;

    #[Groups(['write'])]
    public ?bool $isActive = null;
}
