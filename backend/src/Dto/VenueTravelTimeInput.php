<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueTravelTimeInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueAId = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueBId = null;

    /**
     * Minutes ACCEPTABLES en voiture. Bornes raisonnables (1..240) : un
     * enchaînement de gymnases n'a pas de sens au-delà de quatre heures. null =
     * mode non renseigné. Une écriture d'une valeur pose la source MANUAL
     * (VenueTravelTimeStateProcessor).
     */
    #[Assert\Range(min: 1, max: 240)]
    #[Groups(['write'])]
    public ?int $drivingMinutes = null;

    /** Minutes ACCEPTABLES à pied (mêmes bornes). */
    #[Assert\Range(min: 1, max: 240)]
    #[Groups(['write'])]
    public ?int $walkingMinutes = null;
}
