<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Entity\TeamTag;
use App\State\Provider\TeamTagStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'TeamTag', operations: [
    new GetCollection,
    new Get,
], paginationEnabled: true, paginationItemsPerPage: 30, provider: TeamTagStateProvider::class)]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class TeamTagResource
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
    public string $name = '';

    #[Groups(['read'])]
    public ?string $color = null;

    #[Groups(['read'])]
    public bool $isSystem = false;

    /** GENRE / NIVEAU / AGE — the axis grouping the constraint target picker (null when unclassified). */
    #[Groups(['read'])]
    public ?string $axis = null;

    public static function fromEntity(TeamTag $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->color = $entity->getColor();
        $dto->isSystem = $entity->getIsSystem();
        $dto->axis = $entity->getAxis()?->value;

        return $dto;
    }
}
