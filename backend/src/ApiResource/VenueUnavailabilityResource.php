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
use App\Dto\VenueUnavailabilityInput;
use App\Entity\VenueUnavailability;
use App\State\Processor\VenueUnavailabilityStateProcessor;
use App\State\Provider\VenueUnavailabilityStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/** All-circumstances venue unavailability — alerts matches AND training, blocks nothing. */
// PUT retiré (nettoyage API) : non appelé par le front (une indisponibilité se crée/se retire).
#[ApiResource(shortName: 'VenueUnavailability', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Delete,
], input: VenueUnavailabilityInput::class, paginationEnabled: true, paginationItemsPerPage: 50, provider: VenueUnavailabilityStateProvider::class, processor: VenueUnavailabilityStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['venueId' => 'exact', 'seasonId' => 'exact'])]
class VenueUnavailabilityResource
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

    /** Y-m-d, inclusive. */
    #[Groups(['read'])]
    public string $startDate = '';

    /** Y-m-d, inclusive. */
    #[Groups(['read'])]
    public string $endDate = '';

    #[Groups(['read'])]
    public ?string $label = null;

    public static function fromEntity(VenueUnavailability $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->venueId = $entity->getVenueId();
        $dto->startDate = $entity->getStartDate()->format('Y-m-d');
        $dto->endDate = $entity->getEndDate()->format('Y-m-d');
        $dto->label = $entity->getLabel();

        return $dto;
    }
}
