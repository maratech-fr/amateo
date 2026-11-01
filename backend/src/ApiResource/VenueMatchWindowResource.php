<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\VenueMatchWindowInput;
use App\Entity\VenueMatchWindow;
use App\State\Processor\VenueMatchWindowStateProcessor;
use App\State\Provider\VenueMatchWindowStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/** Match access window of a venue — ≥ 1 window makes it a MATCH venue (derived flag). */
// PUT retiré (nettoyage API) : non appelé par le front (une fenêtre se crée/se retire).
#[ApiResource(shortName: 'VenueMatchWindow', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Delete,
], input: VenueMatchWindowInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: VenueMatchWindowStateProvider::class, processor: VenueMatchWindowStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['venueId' => 'exact', 'seasonId' => 'exact'])]
class VenueMatchWindowResource
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
    public string $venueId = '';

    /** Weekday line: ISO 1..7. NULL for the « Jours fériés » line and for a dated opening. */
    #[Groups(['read'])]
    public ?int $dayOfWeek = null;

    /** PR2 — TRUE for the « Jours fériés » line (no weekday). */
    #[Groups(['read'])]
    public bool $appliesOnPublicHolidays = false;

    /** Dated opening: Y-m-d. NULL for a weekday or « Jours fériés » line. */
    #[Groups(['read'])]
    public ?string $openingDate = null;

    /** Reason of a dated opening. */
    #[Groups(['read'])]
    public ?string $label = null;

    /** HH:MM */
    #[Groups(['read'])]
    public string $startTime = '';

    /** HH:MM, same-day, exclusive. */
    #[Groups(['read'])]
    public string $endTime = '';

    public static function fromEntity(VenueMatchWindow $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->venueId = $entity->getVenueId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->appliesOnPublicHolidays = $entity->isAppliesOnPublicHolidays();
        $dto->openingDate = $entity->getOpeningDate()?->format('Y-m-d');
        $dto->label = $entity->getLabel();
        $dto->startTime = $entity->getStartTime()->format('H:i');
        $dto->endTime = $entity->getEndTime()->format('H:i');

        return $dto;
    }
}
