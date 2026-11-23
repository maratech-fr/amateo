<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamPeriodOverrideInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Groups(['write'])]
    public bool $isActive = true;

    /** null = la valeur de la saison (celle du socle au moment de l'écriture). */
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $sessionsPerWeek = null;

    /** null = la valeur de la saison (celle du socle au moment de l'écriture ; remplacement complet : absent = null). */
    #[Assert\Range(notInRangeMessage: 'Le nombre de séances par jour doit être compris entre 1 et 4.', min: 1, max: 4)]
    #[Groups(['write'])]
    public ?int $maxSessionsPerDay = null;
}
