<?php

declare(strict_types=1);

namespace App\Entity;

use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Faithful photo of the club's STRUCTURE (teams, venues, slots, coaches, links,
 * permanent constraints, base reservations…) at the moment a season-plan
 * version was generated — planning-versions D2 (specs/evolution/
 * planning-versions.md). Unlike Schedule.snapshotData (the transformed solver
 * payload), this serializes the backend entities column by column so the D3
 * restore can rebuild the workspace exactly as it was. One row per schedule
 * (unique), replaced on regeneration; purged with the schedule's artifacts and
 * by SeasonDataPurger.
 */
#[Family(Level::VERSION)]
#[ORM\Entity]
#[ORM\Table(name: 'schedule_structure_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_structure_snapshot_schedule', columns: ['schedule_id'])]
#[ORM\Index(name: 'idx_structure_snapshot_club_season', columns: ['club_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class ScheduleStructureSnapshot implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Schedule::class, OnDelete::DELETE)]
    private string $scheduleId;

    /** @var array<string, list<array<string, mixed>>> entity family (short class name) → serialized rows */
    #[ORM\Column(type: 'json')]
    private array $data = [];

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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
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

    /** @return array<string, list<array<string, mixed>>> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, list<array<string, mixed>>> $data */
    public function setData(array $data): self
    {
        $this->data = $data;

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
