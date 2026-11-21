<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConstraintConflictRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[Family(Level::VERSION)]
#[ORM\Entity(repositoryClass: ConstraintConflictRepository::class)]
#[ORM\Table(name: 'constraint_conflict')]
#[ORM\Index(name: 'idx_constraint_conflict_schedule', columns: ['schedule_id'])]
#[ORM\HasLifecycleCallbacks]
class ConstraintConflict
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
    #[RefersTo(Schedule::class, OnDelete::DELETE, owner: true)]
    private string $scheduleId;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $constraintIds = [];

    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $suggestedResolution = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isResolved = false;

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

    public function getScheduleId(): string
    {
        return $this->scheduleId;
    }

    public function setScheduleId(string $scheduleId): self
    {
        $this->scheduleId = $scheduleId;

        return $this;
    }

    /** @return list<string> */
    public function getConstraintIds(): array
    {
        return $this->constraintIds;
    }

    /** @param list<string> $constraintIds */
    public function setConstraintIds(array $constraintIds): self
    {
        $this->constraintIds = $constraintIds;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getSuggestedResolution(): ?string
    {
        return $this->suggestedResolution;
    }

    public function setSuggestedResolution(?string $suggestedResolution): self
    {
        $this->suggestedResolution = $suggestedResolution;

        return $this;
    }

    public function getIsResolved(): bool
    {
        return $this->isResolved;
    }

    public function isIsResolved(): bool
    {
        return $this->isResolved;
    }

    public function setIsResolved(bool $isResolved): self
    {
        $this->isResolved = $isResolved;

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
