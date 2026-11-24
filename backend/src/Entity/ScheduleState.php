<?php

declare(strict_types=1);

namespace App\Entity;

use App\Plans\PlanStates;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * La copie du froid du plan d'une version, prise à sa naissance (lot 5) : une ligne par version, écrite et lue par
 * {@see PlanStates} seul (SQL direct, hors UnitOfWork). `data` : famille (nom court) => id de ligne => champs froids.
 * Elle part avec sa version (clé en cascade), sa saison et son club ; elle ne porte aucun nom ni contact (rien de libre).
 */
#[Family(Level::VERSION)]
#[ORM\Entity]
#[ORM\Table(name: 'schedule_state')]
#[ORM\UniqueConstraint(name: 'uniq_schedule_state_schedule', columns: ['schedule_id'])]
#[ORM\Index(name: 'idx_schedule_state_club_season', columns: ['club_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class ScheduleState implements TenantOwnedInterface
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

    /** @var array<string, array<string, array<string, mixed>>> */
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

    /** @return array<string, array<string, array<string, mixed>>> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, array<string, array<string, mixed>>> $data */
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
