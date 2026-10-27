<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueMatchWindowInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    /**
     * Weekday line: ISO 1..7. NULL for the « Jours fériés » line (PR2). Exactly one of
     * dayOfWeek / appliesOnPublicHolidays must be set — enforced in the state processor
     * via `$this->refuse(...)` (a 422 with a violation, never a muet one).
     */
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    /** PR2 (Q1 option c) — TRUE marks the « Jours fériés » line (no weekday). */
    #[Groups(['write'])]
    public ?bool $appliesOnPublicHolidays = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $startTime = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $endTime = null;
}
