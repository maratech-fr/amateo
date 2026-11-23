<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Dto\VenueTravelTimeInput;
use App\Entity\PlaceTravelTime;
use App\State\Processor\VenueTravelTimeStateProcessor;
use App\State\Provider\VenueTravelTimeStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/** A per-couple travel-time bracket between two venues — symmetric, one per couple. */
// DELETE retiré (nettoyage API) : non appelé par le front (un barème se crée/se modifie).
#[ApiResource(shortName: 'VenueTravelTime', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
], input: VenueTravelTimeInput::class, paginationEnabled: true, paginationItemsPerPage: 50, provider: VenueTravelTimeStateProvider::class, processor: VenueTravelTimeStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact'])]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class VenueTravelTimeResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    /** Normalized: venueAId < venueBId. */
    #[Groups(['read'])]
    public string $venueAId = '';

    #[Groups(['read'])]
    public string $venueBId = '';

    #[Groups(['read'])]
    public ?int $drivingMinutes = null;

    #[Groups(['read'])]
    public ?int $walkingMinutes = null;

    /** AUTO|MANUAL — null while the matching minutes value is null. */
    #[Groups(['read'])]
    public ?string $drivingSource = null;

    #[Groups(['read'])]
    public ?string $walkingSource = null;

    /**
     * Le temps au club entre deux lieux, nommé par les gymnases de la saison servie (rangés par
     * l'appelant, plus petit identifiant d'abord).
     */
    public static function fromEntity(PlaceTravelTime $entity, string $venueAId, string $venueBId): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->venueAId = $venueAId;
        $dto->venueBId = $venueBId;
        $dto->drivingMinutes = $entity->getDrivingMinutes();
        $dto->walkingMinutes = $entity->getWalkingMinutes();
        $dto->drivingSource = $entity->getDrivingSource()?->value;
        $dto->walkingSource = $entity->getWalkingSource()?->value;

        return $dto;
    }
}
