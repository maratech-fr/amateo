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
use App\Dto\CoachWishMutualizationInput;
use App\Entity\CoachWishMutualization;
use App\State\Processor\CoachWishMutualizationStateProcessor;
use App\State\Provider\CoachWishMutualizationStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Demande de mutualisation d'un coach pour une période de vacances (feature #10, lot D2).
 * DEMANDE informative, jamais une contrainte : aucun effet solveur. Ancrée à l'entrée MÈRE des
 * vacances + une équipe (une par période, pas par semaine). Writes management-only (processor).
 */
#[ApiResource(shortName: 'CoachWishMutualization', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: CoachWishMutualizationInput::class, paginationEnabled: false, provider: CoachWishMutualizationStateProvider::class, processor: CoachWishMutualizationStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['calendarEntryId' => 'exact', 'teamId' => 'exact'])]
class CoachWishMutualizationResource
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
    public string $calendarEntryId = '';

    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public ?string $coachId = null;

    /** @var list<string> */
    #[Groups(['read'])]
    public array $partnerTeamIds = [];

    #[Groups(['read'])]
    public int $sharedSlots = 1;

    #[Groups(['read'])]
    public bool $done = false;

    public static function fromEntity(CoachWishMutualization $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->calendarEntryId = $entity->getCalendarEntryId();
        $dto->teamId = $entity->getTeamId();
        $dto->coachId = $entity->getCoachId();
        $dto->partnerTeamIds = $entity->getPartnerTeamIds();
        $dto->sharedSlots = $entity->getSharedSlots();
        $dto->done = $entity->isDone();

        return $dto;
    }
}
