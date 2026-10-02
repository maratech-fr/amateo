<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Entity\Sport;
use App\State\Provider\SportStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// SEC-14: read-only over the tenant API. Sport is a GLOBAL reference table (no club_id);
// clubs consume it, they don't edit it. Seeded via fixtures / register's seedNewClub (EM,
// not the API). Any edit belongs to a super-admin/ops surface, never the tenant API.
// GetCollection retiré (nettoyage API) : le front ne liste pas les sports par cette route ;
// le Get item reste requis pour les IRI.
#[ApiResource(shortName: 'Sport', operations: [
    new Get,
], provider: SportStateProvider::class)]
class SportResource
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
    public string $slug = '';

    #[Groups(['read'])]
    public ?string $icon = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    public static function fromEntity(Sport $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->slug = $entity->getSlug();
        $dto->icon = $entity->getIcon();
        $dto->isActive = $entity->getIsActive();

        return $dto;
    }
}
