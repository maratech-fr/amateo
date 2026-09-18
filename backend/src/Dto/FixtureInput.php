<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class FixtureInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $competitionId = null;

    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $matchDate = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [FixtureHomeAway::class, 'values'])]
    #[Groups(['write'])]
    public ?string $homeAway = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 180, maxMessage: 'Le nom de l’adversaire ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $opponentLabel = null;

    #[Assert\Choice(callback: [FixtureStatus::class, 'values'])]
    #[Groups(['write'])]
    public ?string $status = null;

    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    /** HH:MM kickoff (24h, strict range). */
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'kickoffTime must be a valid HH:MM (00:00–23:59).')]
    #[Groups(['write'])]
    public ?string $kickoffTime = null;

    /**
     * "rendre au solveur": SOLVER is accepted on an update ONLY when
     * the placement (venue/kickoff/date) is untouched and the match stays PLACED;
     * any other manual write keeps stamping MANUAL. MANUAL is accepted as a
     * no-op echo (the stamp rule produces it anyway).
     */
    #[Assert\Choice(callback: [FixturePlacementSource::class, 'values'])]
    #[Groups(['write'])]
    public ?string $placementSource = null;
}
