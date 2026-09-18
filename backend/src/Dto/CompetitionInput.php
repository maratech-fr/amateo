<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\CompetitionType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class CompetitionInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 180, maxMessage: 'Le nom de la compétition ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [CompetitionType::class, 'values'])]
    #[Groups(['write'])]
    public ?string $competitionType = null;

    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $startDate = null;

    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $endDate = null;
}
