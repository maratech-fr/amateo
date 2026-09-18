<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class CoachInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 120, maxMessage: 'Le prénom du coach ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $firstName = null;

    #[Groups(['write'])]
    #[Assert\Length(max: 120, maxMessage: 'Le nom du coach ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $lastName = null;

    #[Assert\Email]
    #[Groups(['write'])]
    #[Assert\Length(max: 180, maxMessage: 'L’adresse e-mail ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $email = null;

    #[Groups(['write'])]
    #[Assert\Length(max: 40, maxMessage: 'Le numéro de téléphone ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $phone = null;

    /**
     * Plafond de jours/semaine (1..6). ⚠ `0` signifie « RETIRER le plafond » :
     * le PUT est partiel (null = inchangé), donc null ne peut pas porter le retrait —
     * un champ vidé à l'écran doit pouvoir revenir à « pas de plafond ».
     */
    #[Assert\Range(min: 0, max: 6)]
    #[Groups(['write'])]
    public ?int $maxDaysOverride = null;

    #[Groups(['write'])]
    public ?int $acceptableLateMinutes = null;

    #[Groups(['write'])]
    public ?bool $isActive = null;

    #[Groups(['write'])]
    public ?bool $isEmployee = null;

    /** Véhiculé (barème voiture) ou non (barème à pied). */
    #[Groups(['write'])]
    public ?bool $isVehicled = null;

    #[Groups(['write'])]
    public ?string $parentCoachId = null;
}
