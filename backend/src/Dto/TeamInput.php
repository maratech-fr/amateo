<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Gender;
use App\Enum\TeamLevel;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamInput
{
    // Required FK on create (DB is NOT NULL) → 422 instead of a 500; scoped to
    // the 'create' group so partial PUTs that omit it still work (BCK-05).
    #[Assert\NotBlank(groups: ['create'])]
    #[Groups(['write'])]
    public ?string $sportCategoryId = null;

    #[Assert\Positive]
    #[Groups(['write'])]
    public ?int $priorityTierId = null;

    #[Assert\PositiveOrZero]
    #[Groups(['write'])]
    public ?int $tierOrder = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 180, maxMessage: 'Le nom de l’équipe ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $name = null;

    #[Assert\Choice(callback: [Gender::class, 'values'])]
    #[Groups(['write'])]
    public ?string $gender = null;

    #[Assert\Positive]
    #[Groups(['write'])]
    public ?int $sessionsPerWeek = null;

    #[Assert\PositiveOrZero]
    #[Groups(['write'])]
    public ?int $minSessionsOverride = null;

    // Day-of-week 0..6 (0 = Monday). Rejects the "matchDay = 99" the audit flagged.
    #[Assert\Range(min: 0, max: 6)]
    #[Groups(['write'])]
    public ?int $matchDay = null;

    #[Groups(['write'])]
    public ?string $forcedVenueId = null;

    #[Groups(['write'])]
    public ?bool $isActive = null;

    #[Groups(['write'])]
    public ?string $parentTeamId = null;

    #[Groups(['write'])]
    public ?string $ffbbTeamId = null;

    #[Assert\Choice(callback: [TeamLevel::class, 'values'])]
    #[Groups(['write'])]
    public ?string $level = null;
}
