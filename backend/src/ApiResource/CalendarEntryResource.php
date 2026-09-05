<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\CalendarEntryInput;
use App\Entity\CalendarEntry;
use App\State\Processor\CalendarEntryStateProcessor;
use App\State\Provider\CalendarEntryStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'CalendarEntry', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: CalendarEntryInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: CalendarEntryStateProvider::class, processor: CalendarEntryStateProcessor::class)]
class CalendarEntryResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $kind = '';

    #[Groups(['read'])]
    public string $title = '';

    #[Groups(['read'])]
    public string $startDate = '';

    #[Groups(['read'])]
    public string $endDate = '';

    #[Groups(['read'])]
    public bool $isDisruptive = false;

    #[Groups(['read'])]
    public ?string $periodType = null;

    #[Groups(['read'])]
    public ?string $schoolHolidayId = null;

    /** Semaine enfant d'une période mère ; null = entrée racine. */
    #[Groups(['read'])]
    public ?string $parentEntryId = null;

    #[Groups(['read'])]
    public string $status = '';

    #[Groups(['read'])]
    public ?string $createdBy = null;

    /** Vrai si les dates de cette période peuvent être déplacées ; les autres périodes gardent leur fenêtre figée. */
    #[Groups(['read'])]
    public bool $redatable = false;

    /** Vrai pour une indisponibilité DÉCOUPÉE : re-dater ses dates passe par un aperçu des effets, puis confirmation. Exclusif de `redatable`. */
    #[Groups(['read'])]
    public bool $redateNeedsPreview = false;

    public static function fromEntity(CalendarEntry $entity, bool $redatable = false, bool $redateNeedsPreview = false): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->kind = $entity->getKind()->value;
        $dto->title = $entity->getTitle();
        $dto->startDate = $entity->getStartDate()->format('Y-m-d');
        $dto->endDate = $entity->getEndDate()->format('Y-m-d');
        $dto->isDisruptive = $entity->getIsDisruptive();
        $dto->periodType = $entity->getPeriodType()?->value;
        $dto->schoolHolidayId = $entity->getSchoolHolidayId();
        $dto->parentEntryId = $entity->getParentEntryId();
        $dto->status = $entity->getStatus()->value;
        $dto->createdBy = $entity->getCreatedBy();
        $dto->redatable = $redatable;
        $dto->redateNeedsPreview = $redateNeedsPreview;

        return $dto;
    }
}
