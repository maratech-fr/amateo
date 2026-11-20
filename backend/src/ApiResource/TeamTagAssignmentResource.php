<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Entity\TeamTagAssignment;
use App\State\Provider\TeamTagAssignmentStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'TeamTagAssignment', operations: [
    new GetCollection,
    new Get,
], paginationEnabled: true, paginationItemsPerPage: 30, provider: TeamTagAssignmentStateProvider::class)]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class TeamTagAssignmentResource
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
    public string $teamId = '';

    #[Groups(['read'])]
    public string $tagId = '';

    #[Groups(['read'])]
    public string $seasonId = '';

    public static function fromEntity(TeamTagAssignment $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->tagId = $entity->getTagId();
        $dto->seasonId = $entity->getSeasonId();

        return $dto;
    }
}
