<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class SportCategoryInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $sportId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Groups(['write'])]
    public ?bool $isCustom = null;

    #[Groups(['write'])]
    public ?int $ageMin = null;

    #[Groups(['write'])]
    public ?int $ageMax = null;

    #[Groups(['write'])]
    public ?int $sortOrder = null;

    // P2-54 RMM-9 — durée de match par catégorie. NULL = revient au défaut de
    // famille (MatchDurationResolver). Bornes déclaratives : une valeur hors
    // borne → 422 porté par Assert\Range (violations pleines, le message atteint
    // l'écran) ; null accepté (Range saute null).
    #[Assert\Range(min: 30, max: 240)]
    #[Groups(['write'])]
    public ?int $matchMinutes = null;

    #[Assert\Range(min: 0, max: 120)]
    #[Groups(['write'])]
    public ?int $warmupMinutes = null;
}
