<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ConstraintInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    #[Assert\Length(max: 180, maxMessage: 'Le nom de la contrainte ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $name = null;

    #[Groups(['write'])]
    public ?string $description = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [ConstraintScope::class, 'values'])]
    #[Groups(['write'])]
    public ?string $scope = null;

    #[Groups(['write'])]
    #[Assert\Uuid(message: 'scopeTargetId must be a valid UUID.')]
    public ?string $scopeTargetId = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [ConstraintFamily::class, 'values'])]
    #[Groups(['write'])]
    public ?string $family = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [ConstraintRuleType::class, 'values'])]
    #[Groups(['write'])]
    public ?string $ruleType = null;

    /** @var array<string, mixed>|null */
    #[Groups(['write'])]
    public ?array $config = null;

    #[Groups(['write'])]
    #[Assert\Length(max: 80, maxMessage: 'L’auteur ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $createdBy = null;

    #[Groups(['write'])]
    #[Assert\Length(max: 80, maxMessage: 'La source ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $source = null;

    #[Groups(['write'])]
    #[Assert\Length(max: 180, maxMessage: 'La référence d’occurrence ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $sourceOccurrenceId = null;

    /** Set to attach this constraint to a CalendarEntry (period) — excludes it from base generation. */
    #[Groups(['write'])]
    public ?string $calendarEntryId = null;

    #[Groups(['write'])]
    public ?bool $isActive = null;

    #[Groups(['write'])]
    public ?int $sortOrder = null;
}
