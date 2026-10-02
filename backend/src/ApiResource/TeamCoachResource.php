<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\TeamCoachInput;
use App\Entity\TeamCoach;
use App\Enum\TeamCoachRole;
use App\State\Processor\TeamCoachStateProcessor;
use App\State\Provider\TeamCoachStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// PUT retiré (nettoyage API) : non appelé par le front (une liaison se crée/se retire).
#[ApiResource(shortName: 'TeamCoach', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Delete,
], input: TeamCoachInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: TeamCoachStateProvider::class, processor: TeamCoachStateProcessor::class)]
class TeamCoachResource
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
    public string $coachId = '';

    #[Groups(['read'])]
    public TeamCoachRole $role;

    #[Groups(['read'])]
    public bool $isRequired = false;

    public static function fromEntity(TeamCoach $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->coachId = $entity->getCoachId();
        $dto->role = $entity->getRole();
        $dto->isRequired = $entity->getIsRequired();

        return $dto;
    }
}
