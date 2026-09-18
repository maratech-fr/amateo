<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\SeasonStatus;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class SeasonInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 120, maxMessage: 'Le nom de la saison ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $name = null;

    /** Required at creation; null on update = keep the current value (partial PUT). */
    #[Groups(['write'])]
    public ?DateTimeImmutable $startDate = null;

    /** Required at creation; null on update = keep the current value (partial PUT). */
    #[Groups(['write'])]
    public ?DateTimeImmutable $endDate = null;

    #[Assert\Choice(callback: [SeasonStatus::class, 'values'])]
    #[Groups(['write'])]
    public ?string $status = null;
}
