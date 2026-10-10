<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\TeamPeriodOverrideInput;
use App\Entity\TeamPeriodOverride;
use App\State\Processor\TeamPeriodOverrideStateProcessor;
use App\State\Provider\TeamPeriodOverrideStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Period-editable structure: a sparse per-(period, team) override — the team is
 * off for the period, or trains a different number of sessions. No row = seasonal
 * defaults. The overlay build reads these; the base plan is never touched.
 */
#[ApiResource(shortName: 'TeamPeriodOverride', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: TeamPeriodOverrideInput::class, paginationEnabled: false, provider: TeamPeriodOverrideStateProvider::class, processor: TeamPeriodOverrideStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['schedulePlanId' => 'exact', 'teamId' => 'exact'])]
class TeamPeriodOverrideResource
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
    public string $schedulePlanId = '';

    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public bool $isActive = true;

    #[Groups(['read'])]
    public ?int $sessionsPerWeek = null;

    /**
     * Lot 9 — l'origine de cet override quand il a été posé par un geste nommé (``mutualisation``
     * pour une équipe activée par la mutualisation d'une case) ; NULL pour un réglage ordinaire du
     * wizard. Le planning l'AFFICHE (pastille « via mutualisation »), il ne décide rien avec.
     */
    #[Groups(['read'])]
    public ?string $source = null;

    public static function fromEntity(TeamPeriodOverride $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->teamId = $entity->getTeamId();
        $dto->isActive = $entity->isActive();
        $dto->sessionsPerWeek = $entity->getSessionsPerWeek();
        $dto->source = $entity->getSource();

        return $dto;
    }
}
