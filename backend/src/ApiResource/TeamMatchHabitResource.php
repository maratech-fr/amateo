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
use App\Dto\TeamMatchHabitInput;
use App\Entity\TeamMatchHabit;
use App\State\Processor\TeamMatchHabitStateProcessor;
use App\State\Provider\TeamMatchHabitStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/** A team's ideal match slot — one per team, venue optional, tagged week A/B/ALL. */
#[ApiResource(shortName: 'TeamMatchHabit', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: TeamMatchHabitInput::class, paginationEnabled: true, paginationItemsPerPage: 50, provider: TeamMatchHabitStateProvider::class, processor: TeamMatchHabitStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['teamId' => 'exact', 'seasonId' => 'exact'])]
class TeamMatchHabitResource
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
    public int $dayOfWeek = 0;

    /** HH:MM */
    #[Groups(['read'])]
    public string $kickoffTime = '';

    #[Groups(['read'])]
    public ?string $venueId = null;

    /** Semaine d'alternance du créneau idéal — A | B | ALL. */
    #[Groups(['read'])]
    public string $week = 'ALL';

    public static function fromEntity(TeamMatchHabit $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->kickoffTime = $entity->getKickoffTime()->format('H:i');
        $dto->venueId = $entity->getVenueId();
        $dto->week = $entity->getWeek()->value;

        return $dto;
    }
}
