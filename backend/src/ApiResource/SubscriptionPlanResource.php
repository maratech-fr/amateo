<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Entity\SubscriptionPlan;
use App\Entity\SubscriptionPlanTier;
use App\State\Provider\SubscriptionPlanStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// SEC-14: read-only over the tenant API. SubscriptionPlan is the GLOBAL offer catalogue
// (code, maxGenerations, tiers with their team cap — no amount anywhere, P1-3) — a write here
// would let any club member falsify caps/quotas. Managed out-of-band (migration seed /
// super-admin actions), never the tenant API. The `beta` offer is hidden from the collection
// (provider).
#[ApiResource(shortName: 'SubscriptionPlan', operations: [
    new GetCollection,
    new Get,
], paginationEnabled: true, paginationItemsPerPage: 30, provider: SubscriptionPlanStateProvider::class)]
#[ClubResource(module: Module::CLUB, read: Audience::MEMBER, write: Audience::MANAGER)]
class SubscriptionPlanResource
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
    public string $code = '';

    #[Groups(['read'])]
    public string $name = '';

    // Convention 0 = illimité (P1-3).
    #[Groups(['read'])]
    public int $maxVenues = 0;

    #[Groups(['read'])]
    public int $maxGenerations = 0;

    /** @var array<string, mixed> */
    #[Groups(['read'])]
    public array $features = [];

    /**
     * Les paliers de l'offre, rangés par position ; vide pour une offre sans palier. Plafond
     * d'équipes `maxTeams` (0 = illimité) ; le prix d'un palier n'est jamais servi.
     *
     * @var list<array{code: string, position: int, maxTeams: int}>
     */
    #[Groups(['read'])]
    public array $tiers = [];

    /** @param list<SubscriptionPlanTier> $tiers les paliers de l'offre, rangés par position */
    public static function fromEntity(SubscriptionPlan $entity, array $tiers = []): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->code = $entity->getCode();
        $dto->name = $entity->getName();
        $dto->maxVenues = $entity->getMaxVenues();
        $dto->maxGenerations = $entity->getMaxGenerations();
        $dto->features = $entity->getFeatures();
        $dto->tiers = array_map(static fn (SubscriptionPlanTier $tier): array => [
            'code' => $tier->getCode(),
            'position' => $tier->getPosition(),
            'maxTeams' => $tier->getMaxTeams(),
        ], $tiers);

        return $dto;
    }
}
