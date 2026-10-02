<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\SeasonInput;
use App\Entity\Season;
use App\State\Processor\SeasonStateProcessor;
use App\State\Provider\SeasonStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// PUT/DELETE retirés (nettoyage API) : non appelés par le front. Le POST (création de
// saison) reste — il sert de fixture à plusieurs tests et provisionne le plan SEASON.
#[ApiResource(shortName: 'Season', operations: [
    new GetCollection,
    new Get,
    new Post,
], input: SeasonInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: SeasonStateProvider::class, processor: SeasonStateProcessor::class)]
class SeasonResource
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
    public DateTimeImmutable $startDate;

    #[Groups(['read'])]
    public DateTimeImmutable $endDate;

    #[Groups(['read'])]
    public string $status = '';

    #[Groups(['read'])]
    public ?string $exportPdfUrl = null;

    /** @var array<string, mixed> */
    #[Groups(['read'])]
    public array $transitionData = [];

    public static function fromEntity(Season $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->startDate = $entity->getStartDate();
        $dto->endDate = $entity->getEndDate();
        $dto->status = $entity->getStatus()->value;
        $dto->exportPdfUrl = $entity->getExportPdfUrl();
        $dto->transitionData = $entity->getTransitionData();

        return $dto;
    }
}
