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
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Dto\TeamLinkInput;
use App\Entity\TeamLink;
use App\State\Processor\TeamLinkStateProcessor;
use App\State\Provider\TeamLinkStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/** A declared team bridge (players shared / chained) — symmetric, one per couple. */
#[ApiResource(shortName: 'TeamLink', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: TeamLinkInput::class, paginationEnabled: true, paginationItemsPerPage: 50, provider: TeamLinkStateProvider::class, processor: TeamLinkStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact'])]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class TeamLinkResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    /** Normalized: teamAId < teamBId. */
    #[Groups(['read'])]
    public string $teamAId = '';

    #[Groups(['read'])]
    public string $teamBId = '';

    #[Groups(['read'])]
    public string $linkType = '';

    /** Intensité côté entraînement (PREFERRED par défaut). */
    #[Groups(['read'])]
    public string $trainingIntensity = '';

    public static function fromEntity(TeamLink $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamAId = $entity->getTeamAId();
        $dto->teamBId = $entity->getTeamBId();
        $dto->linkType = $entity->getLinkType()->value;
        $dto->trainingIntensity = $entity->getTrainingIntensity()->value;

        return $dto;
    }
}
