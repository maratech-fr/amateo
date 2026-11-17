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
use ApiPlatform\Metadata\Put;
use App\Dto\VenueOccupancyInput;
use App\Entity\VenueOccupancy;
use App\State\Processor\VenueOccupancyStateProcessor;
use App\State\Provider\VenueOccupancyStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Une occupation de gymnase : un moment où le gymnase n'est pas au club, nommé par son motif.
 * Information seulement : elle ne change rien au planning.
 */
#[ApiResource(shortName: 'VenueOccupancy', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: VenueOccupancyInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: VenueOccupancyStateProvider::class, processor: VenueOccupancyStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['venueId' => 'exact', 'schedulePlanId' => 'exact'])]
class VenueOccupancyResource
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

    #[Groups(['read'])]
    public int $dayOfWeek = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $startTime;

    #[Groups(['read'])]
    public int $durationMinutes = 60;

    /** Le motif de l'occupation (« École »), 40 caractères au plus. */
    #[Groups(['read'])]
    public string $label = '';

    /** null = occupation de la grille de saison ; posé = celle de la grille de ce plan de période. */
    #[Groups(['read'])]
    public ?string $schedulePlanId = null;

    public static function fromEntity(VenueOccupancy $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->venueId = $entity->getVenueId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->startTime = $entity->getStartTime();
        $dto->durationMinutes = $entity->getDurationMinutes();
        $dto->label = $entity->getLabel();
        $dto->schedulePlanId = $entity->getSchedulePlanId();

        return $dto;
    }
}
