<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ScheduleDiagnosticSeverity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduleDiagnosticInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $scheduleId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 50, maxMessage: 'Le type de diagnostic ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $type = null;

    #[Assert\Choice(callback: [ScheduleDiagnosticSeverity::class, 'values'])]
    #[Groups(['write'])]
    public ?string $severity = null;

    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $message = null;

    /** @var array<string, mixed>|null */
    #[Groups(['write'])]
    public ?array $suggestions = null;
}
