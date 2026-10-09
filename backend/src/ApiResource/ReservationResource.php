<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\ReservationInput;
use App\Entity\Reservation;
use App\State\Processor\ReservationStateProcessor;
use App\State\Provider\ReservationStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Reservation', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Delete,
], input: ReservationInput::class, paginationEnabled: true, paginationItemsPerPage: 100, provider: ReservationStateProvider::class, processor: ReservationStateProcessor::class)]
class ReservationResource
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
    public ?string $schedulePlanId = null;

    #[Groups(['read'])]
    public ?string $teamId = null;

    /** Libellé d'un créneau LIBRE (lot 4bis) — null pour une réservation d'équipe. */
    #[Groups(['read'])]
    public ?string $label = null;

    #[Groups(['read'])]
    public string $venueId = '';

    #[Groups(['read'])]
    public int $dayOfWeek = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $startTime;

    #[Groups(['read'])]
    public int $durationMinutes = 90;

    public static function fromEntity(Reservation $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->teamId = $entity->getTeamId();
        $dto->label = $entity->getLabel();
        $dto->venueId = $entity->getVenueId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->startTime = $entity->getStartTime();
        $dto->durationMinutes = $entity->getDurationMinutes();

        return $dto;
    }
}
