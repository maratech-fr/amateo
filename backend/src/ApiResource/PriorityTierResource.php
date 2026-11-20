<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Entity\PriorityTier;
use App\State\Provider\PriorityTierStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// SEC-14: read-only over the tenant API. PriorityTier is a GLOBAL reference table (no
// club_id) read by the solver for EVERY club (ScheduleConstraintBuilder::findBy([])),
// so a write here would tamper with all tenants' generations. Seeded via fixtures;
// any future edit belongs to a super-admin/ops surface, never the tenant API.
#[ApiResource(shortName: 'PriorityTier', operations: [
    new GetCollection,
    new Get,
], paginationEnabled: true, paginationItemsPerPage: 30, provider: PriorityTierStateProvider::class)]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class PriorityTierResource
{
    #[Groups(['read'])]
    public int $id = 0;

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $label = '';

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public string $color = '';

    #[Groups(['read'])]
    public int $orToolsWeight = 0;

    #[Groups(['read'])]
    public int $defaultMinSessions = 0;

    public static function fromEntity(PriorityTier $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->label = $entity->getLabel();
        $dto->name = $entity->getName();
        $dto->color = $entity->getColor();
        $dto->orToolsWeight = $entity->getOrToolsWeight();
        $dto->defaultMinSessions = $entity->getDefaultMinSessions();

        return $dto;
    }
}
