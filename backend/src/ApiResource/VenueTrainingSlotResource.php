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
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Dto\VenueTrainingSlotInput;
use App\Entity\VenueTrainingSlot;
use App\State\Processor\VenueTrainingSlotStateProcessor;
use App\State\Provider\VenueTrainingSlotStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'VenueTrainingSlot', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: VenueTrainingSlotInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: VenueTrainingSlotStateProvider::class, processor: VenueTrainingSlotStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['venueId' => 'exact', 'seasonId' => 'exact'])]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class VenueTrainingSlotResource
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
    public int $durationMinutes = 90;

    #[Groups(['read'])]
    public int $capacity = 1;

    /** Libellé d'un créneau mutualisé (« CEC3 »), null quand le créneau n'en porte pas. Esthétique. */
    #[Groups(['read'])]
    public ?string $groupLabel = null;

    /** Period-editable structure: null = permanent seasonal slot; set = a slot scoped to that period (additive). */
    #[Groups(['read'])]
    public ?string $schedulePlanId = null;

    /**
     * @param string|null $schedulePlanId le plan à la frontière de l'API : null pour le socle (jusqu'au lot 6)
     */
    public static function fromEntity(VenueTrainingSlot $entity, ?string $schedulePlanId): self
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
        $dto->capacity = $entity->getCapacity();
        $dto->groupLabel = $entity->getGroupLabel();
        $dto->schedulePlanId = $schedulePlanId;

        return $dto;
    }
}
