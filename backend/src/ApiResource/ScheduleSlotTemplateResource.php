<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\State\Provider\ScheduleSlotTemplateStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// Rail de retouche read-only : le déplacement passe par POST /api/schedule-slots/{id}/move
// (sous verdict moteur), verrous/contraintes par manual-edit — plus aucune écriture CRUD brute.
#[ApiResource(shortName: 'ScheduleSlotTemplate', operations: [
    new GetCollection,
    new Get,
], paginationEnabled: true, paginationItemsPerPage: 30, provider: ScheduleSlotTemplateStateProvider::class)]
#[ApiFilter(SearchFilter::class, properties: ['scheduleId' => 'exact'])]
class ScheduleSlotTemplateResource
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
    public string $scheduleId = '';

    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public string $venueId = '';

    #[Groups(['read'])]
    public ?string $coachId = null;

    #[Groups(['read'])]
    public int $dayOfWeek = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $startTime;

    #[Groups(['read'])]
    public int $durationMinutes = 90;

    #[Groups(['read'])]
    public LockLevel $lockLevel = LockLevel::NONE;

    /** Pourquoi le verrou : réservation de gymnase, épinglage manuel, ou origine inconnue. NULL si non verrouillé. */
    #[Groups(['read'])]
    public ?LockOrigin $lockOrigin = null;

    /** @var array<string, mixed>|null */
    #[Groups(['read'])]
    public ?array $pendingConstraintSuggestion = null;

    /**
     * Lot 9 — le bloc de mutualisation DONT cette séance est une séance de groupe (NULL = séance
     * ordinaire). Le planning l'AFFICHE (nom du bloc, pastille) sans re-dériver l'appartenance par
     * co-localisation ; server-authoritative, posé par le geste « mutualiser », jamais par l'API.
     */
    #[Groups(['read'])]
    public ?string $sharedTrainingBlockId = null;

    public static function fromEntity(ScheduleSlotTemplate $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->scheduleId = $entity->getScheduleId();
        $dto->teamId = $entity->getTeamId();
        $dto->venueId = $entity->getVenueId();
        $dto->coachId = $entity->getCoachId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->startTime = $entity->getStartTime();
        $dto->durationMinutes = $entity->getDurationMinutes();
        $dto->lockLevel = $entity->getLockLevel();
        $dto->lockOrigin = $entity->getLockOrigin();
        $dto->pendingConstraintSuggestion = $entity->getPendingConstraintSuggestion();
        $dto->sharedTrainingBlockId = $entity->getSharedTrainingBlockId();

        return $dto;
    }
}
