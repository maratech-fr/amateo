<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\CoachPlayerMembershipInput;
use App\Entity\CoachPlayerMembership;
use App\State\Processor\CoachPlayerMembershipStateProcessor;
use App\State\Provider\CoachPlayerMembershipStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// PUT retiré (nettoyage API) : non appelé par le front (une liaison se crée/se retire).
#[ApiResource(shortName: 'CoachPlayerMembership', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Delete,
], input: CoachPlayerMembershipInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: CoachPlayerMembershipStateProvider::class, processor: CoachPlayerMembershipStateProcessor::class)]
class CoachPlayerMembershipResource
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
    public string $coachId = '';

    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public ?string $position = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    public static function fromEntity(CoachPlayerMembership $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->coachId = $entity->getCoachId();
        $dto->teamId = $entity->getTeamId();
        $dto->position = $entity->getPosition();
        $dto->isActive = $entity->getIsActive();

        return $dto;
    }
}
