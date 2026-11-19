<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le corps d'écriture d'une occupation. Le motif n'a ici aucune contrainte : le processeur le
 * rogne puis le refuse vide ou trop long, avec les deux messages du contrat (un `NotBlank` ne
 * verrait pas un motif fait d'espaces).
 */
class VenueOccupancyInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{2}:\d{2}$/')]
    #[Groups(['write'])]
    public ?string $startTime = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 15)]
    #[Groups(['write'])]
    public ?int $durationMinutes = null;

    #[Groups(['write'])]
    public ?string $label = null;

    /** null = occupation de saison ; l'identifiant d'un plan de période la pose dans sa grille. Posé à la création seulement. */
    // `NotBlank(allowNull: true)` en plus d'`Uuid` : le validateur Uuid laisse passer la chaîne vide.
    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;
}
