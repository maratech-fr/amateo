<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CalendarEntryInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [CalendarEntryKind::class, 'values'])]
    #[Groups(['write'])]
    public ?string $kind = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[Groups(['write'])]
    public ?string $title = null;

    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $startDate = null;

    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $endDate = null;

    #[Groups(['write'])]
    public ?bool $isDisruptive = null;

    #[Assert\Choice(callback: [CalendarEntryPeriodType::class, 'values'])]
    #[Groups(['write'])]
    public ?string $periodType = null;

    #[Groups(['write'])]
    public ?string $schoolHolidayId = null;

    /** Semaine enfant d'une période mère. POST uniquement — l'identité
     *  d'une entrée à plan est gelée, le processor refuse tout changement au PUT. */
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $parentEntryId = null;

    #[Assert\Choice(callback: [CalendarEntryStatus::class, 'values'])]
    #[Groups(['write'])]
    public ?string $status = null;

    #[Groups(['write'])]
    public ?string $createdBy = null;

    /** D3 v2 — jeton d'aperçu du re-datage d'une indisponibilité DÉCOUPÉE. Le PUT d'une mère
     *  découpée l'exige (sans lui, 422 « demandez l'aperçu ») et le confronte au recalcul sous
     *  verrous (différent → 409). Ignoré pour toute autre entrée. */
    #[Groups(['write'])]
    public ?string $previewToken = null;

    #[Assert\Callback]
    public function validateShape(ExecutionContextInterface $context): void
    {
        if (null !== $this->startDate && null !== $this->endDate && $this->endDate < $this->startDate) {
            $context->buildViolation('endDate must be on or after startDate.')
                ->atPath('endDate')
                ->addViolation();
        }

        if ('period' === $this->kind && null === $this->periodType) {
            $context->buildViolation('periodType is required for a period entry.')
                ->atPath('periodType')
                ->addViolation();
        }

        if ('event' === $this->kind && null !== $this->parentEntryId) {
            $context->buildViolation('parentEntryId is only allowed on a period entry.')
                ->atPath('parentEntryId')
                ->addViolation();
        }

        if ('event' === $this->kind) {
            if (null !== $this->periodType) {
                $context->buildViolation('periodType is only allowed on a period entry.')
                    ->atPath('periodType')
                    ->addViolation();
            }
            if (null !== $this->schoolHolidayId) {
                $context->buildViolation('schoolHolidayId is only allowed on a period entry.')
                    ->atPath('schoolHolidayId')
                    ->addViolation();
            }
        }

        // Only reject a *truthy* isDisruptive on a period; false is the benign
        // checkbox default a form may always serialise.
        if ('period' === $this->kind && true === $this->isDisruptive) {
            $context->buildViolation('isDisruptive is only allowed on an event entry.')
                ->atPath('isDisruptive')
                ->addViolation();
        }
    }
}
