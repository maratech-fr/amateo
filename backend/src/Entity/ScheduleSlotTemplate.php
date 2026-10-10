<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Repository\ScheduleSlotTemplateRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduleSlotTemplateRepository::class)]
#[ORM\Table(name: 'schedule_slot_template')]
#[ORM\Index(name: 'idx_schedule_slot_template_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_schedule_slot_template_schedule', columns: ['schedule_id'])]
#[ORM\Index(name: 'idx_schedule_slot_template_team', columns: ['team_id'])]
#[ORM\Index(name: 'idx_schedule_slot_template_venue', columns: ['venue_id'])]
#[ORM\Index(name: 'idx_schedule_slot_template_coach', columns: ['coach_id'])]
#[ORM\Index(name: 'idx_schedule_slot_template_shared_block', columns: ['shared_training_block_id'])]
#[ORM\HasLifecycleCallbacks]
class ScheduleSlotTemplate implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'guid')]
    private string $scheduleId;

    #[ORM\Column(type: 'guid')]
    private string $teamId;

    #[ORM\Column(type: 'guid')]
    private string $venueId;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $coachId = null;

    #[ORM\Column(type: 'smallint')]
    private int $dayOfWeek;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $startTime;

    #[ORM\Column(type: 'smallint')]
    private int $durationMinutes;

    #[ORM\Column(type: 'string', length: 10, enumType: LockLevel::class)]
    private LockLevel $lockLevel = LockLevel::NONE;

    /**
     * L'origine du verrou — pourquoi ce créneau est bloqué. NULL quand lockLevel = NONE
     * (pas de verrou, donc pas d'origine). Server-authoritative : jamais posé par l'API.
     */
    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: LockOrigin::class)]
    private ?LockOrigin $lockOrigin = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $pendingConstraintSuggestion = null;

    /**
     * Lot 9 — le bloc de mutualisation DONT cette séance est une séance de groupe (option B).
     * NULL = séance ordinaire. Posé par le geste « mutualiser » sur la source, les séances
     * remplacées et la séance neuve d'une équipe activée ; FK `ON DELETE CASCADE` — la séance ne
     * survit jamais à son bloc.
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $sharedTrainingBlockId = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getScheduleId(): string
    {
        return $this->scheduleId;
    }

    public function setScheduleId(string $scheduleId): self
    {
        $this->scheduleId = $scheduleId;

        return $this;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): self
    {
        $this->teamId = $teamId;

        return $this;
    }

    public function getVenueId(): string
    {
        return $this->venueId;
    }

    public function setVenueId(string $venueId): self
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function getCoachId(): ?string
    {
        return $this->coachId;
    }

    public function setCoachId(?string $coachId): self
    {
        $this->coachId = $coachId;

        return $this;
    }

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getStartTime(): DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(DateTimeImmutable $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $durationMinutes): self
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    public function getLockLevel(): LockLevel
    {
        return $this->lockLevel;
    }

    public function setLockLevel(LockLevel $lockLevel): self
    {
        $this->lockLevel = $lockLevel;

        return $this;
    }

    public function getLockOrigin(): ?LockOrigin
    {
        return $this->lockOrigin;
    }

    public function setLockOrigin(?LockOrigin $lockOrigin): self
    {
        $this->lockOrigin = $lockOrigin;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPendingConstraintSuggestion(): ?array
    {
        return $this->pendingConstraintSuggestion;
    }

    /** @param array<string, mixed>|null $pendingConstraintSuggestion */
    public function setPendingConstraintSuggestion(?array $pendingConstraintSuggestion): self
    {
        $this->pendingConstraintSuggestion = $pendingConstraintSuggestion;

        return $this;
    }

    public function getSharedTrainingBlockId(): ?string
    {
        return $this->sharedTrainingBlockId;
    }

    public function setSharedTrainingBlockId(?string $sharedTrainingBlockId): self
    {
        $this->sharedTrainingBlockId = $sharedTrainingBlockId;

        return $this;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
