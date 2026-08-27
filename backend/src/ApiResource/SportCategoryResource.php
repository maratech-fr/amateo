<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\SportCategoryInput;
use App\Entity\SportCategory;
use App\Service\MatchDurationProfile;
use App\State\Processor\SportCategoryStateProcessor;
use App\State\Provider\SportCategoryStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'SportCategory', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: SportCategoryInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: SportCategoryStateProvider::class, processor: SportCategoryStateProcessor::class)]
class SportCategoryResource
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
    public string $sportId = '';

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public bool $isCustom = false;

    #[Groups(['read'])]
    public ?int $ageMin = null;

    #[Groups(['read'])]
    public ?int $ageMax = null;

    #[Groups(['read'])]
    public int $sortOrder = 0;

    // P2-54 RMM-9 — la durée de match propre à la catégorie (null = héritée). Les
    // deux `default*` portent le défaut de FAMILLE résolu par le serveur : le front
    // les affiche en placeholder / en-tête de groupe, il ne re-dérive JAMAIS les
    // défauts (FrontRederivationRegistryTest).
    #[Groups(['read'])]
    public ?int $matchMinutes = null;

    #[Groups(['read'])]
    public ?int $warmupMinutes = null;

    #[Groups(['read'])]
    public int $defaultMatchMinutes = 0;

    #[Groups(['read'])]
    public int $defaultWarmupMinutes = 0;

    public static function fromEntity(SportCategory $entity, MatchDurationProfile $familyDefault): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->sportId = $entity->getSportId();
        $dto->name = $entity->getName();
        $dto->isCustom = $entity->getIsCustom();
        $dto->ageMin = $entity->getAgeMin();
        $dto->ageMax = $entity->getAgeMax();
        $dto->sortOrder = $entity->getSortOrder();
        $dto->matchMinutes = $entity->getMatchMinutes();
        $dto->warmupMinutes = $entity->getWarmupMinutes();
        $dto->defaultMatchMinutes = $familyDefault->matchMinutes;
        $dto->defaultWarmupMinutes = $familyDefault->warmupMinutes;

        return $dto;
    }
}
