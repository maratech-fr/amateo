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
use App\Dto\CoachWishInput;
use App\Entity\CoachWish;
use App\State\Processor\CoachWishStateProcessor;
use App\State\Provider\CoachWishStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Doléance coach pour une semaine de vacances (feature #10, lot C1). Souhait, jamais une
 * contrainte : aucun effet solveur. Ancrée à l'entrée MÈRE des vacances + le lundi de la
 * semaine. Writes management-only (cf. processor).
 */
#[ApiResource(shortName: 'CoachWish', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: CoachWishInput::class, paginationEnabled: false, provider: CoachWishStateProvider::class, processor: CoachWishStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['calendarEntryId' => 'exact', 'teamId' => 'exact'])]
class CoachWishResource
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

    /** Exposé en Y-m-d (une DATE, pas un instant) pour un aller-retour propre côté front. */
    #[Groups(['read'])]
    public string $weekStart = '';

    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public ?string $coachId = null;

    #[Groups(['read'])]
    public int $slotsWanted = 0;

    /** @var list<int> */
    #[Groups(['read'])]
    public array $unavailableDays = [];

    /**
     * Jours souhaités, ISO 1–7 — informatif, aucun effet solveur.
     *
     * @var list<int>
     */
    #[Groups(['read'])]
    public array $wishedDays = [];

    #[Groups(['read'])]
    public ?string $comment = null;

    #[Groups(['read'])]
    public bool $done = false;

    public static function fromEntity(CoachWish $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->calendarEntryId = $entity->getCalendarEntryId();
        $dto->weekStart = $entity->getWeekStart()->format('Y-m-d');
        $dto->teamId = $entity->getTeamId();
        $dto->coachId = $entity->getCoachId();
        $dto->slotsWanted = $entity->getSlotsWanted();
        $dto->unavailableDays = $entity->getUnavailableDays();
        $dto->wishedDays = $entity->getWishedDays();
        $dto->comment = $entity->getComment();
        $dto->done = $entity->isDone();

        return $dto;
    }
}
