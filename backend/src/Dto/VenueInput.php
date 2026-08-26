<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Groups(['write'])]
    public ?bool $isExternal = null;

    #[Groups(['write'])]
    public ?string $color = null;

    #[Groups(['write'])]
    public ?string $latitude = null;

    #[Groups(['write'])]
    public ?string $longitude = null;

    /** L'adresse saisie (géocodée en lat/long via GET /api/geocode). */
    #[Assert\Length(max: 255)]
    #[Groups(['write'])]
    public ?string $address = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['manual', 'ffbb', 'import'])]
    #[Groups(['write'])]
    public ?string $source = null;

    #[Groups(['write'])]
    public ?string $externalRef = null;

    #[Groups(['write'])]
    public ?bool $isActive = null;

    #[Groups(['write'])]
    public ?string $parentVenueId = null;

    #[Groups(['write'])]
    public ?bool $canSplit = null;

    /**
     * v2 cohérence canSplit — confirmation EXPLICITE d'une transition « terrain divisible »
     * true→false alors que des créneaux du gymnase accueillent encore 2 équipes ou plus.
     * Sans elle, cette transition est refusée (422) ; avec elle, la cascade ramène ces créneaux
     * à capacité 1, retire leur libellé et vide leurs réservations (VenueStateProcessor).
     * Défaut false : une écriture qui l'ignore garde le comportement de refus, jamais la cascade.
     */
    #[Groups(['write'])]
    public bool $confirmSplitCascade = false;
}
