<?php

declare(strict_types=1);

namespace App\Dto;

use App\Service\SchoolZoneResolver;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ClubInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 180, maxMessage: 'Le nom du club ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 180, maxMessage: 'L’identifiant d’URL du club ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $slug = null;

    // SEC-15: plan / billing / quota fields are NOT client-writable — a club admin could
    // otherwise self-assign a plan, extend planExpiresAt, or reset generationCountSeason
    // (quota bypass) via PUT /api/clubs/{id}. They are server-managed (out-of-band billing
    // / future super-admin surface) and deliberately absent from this input DTO.

    #[Assert\Choice(choices: SchoolZoneResolver::ZONES, message: 'School zone is not a valid zone code.')]
    #[Groups(['write'])]
    public ?string $schoolZone = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 64, maxMessage: 'Le fuseau horaire ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $timezone = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 10, maxMessage: 'La langue ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $locale = null;

    #[Groups(['write'])]
    public ?bool $onboardingCompleted = null;

    #[Groups(['write'])]
    #[Assert\Length(max: 64, maxMessage: 'Le code club FFBB ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $ffbbClubCode = null;

    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'accentColor must be a #RRGGBB hex colour')]
    #[Groups(['write'])]
    public ?string $accentColor = null;

    /**
     * @var list<string>|null
     */
    #[Assert\All([new Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/')])]
    #[Groups(['write'])]
    public ?array $accentPalette = null;
}
