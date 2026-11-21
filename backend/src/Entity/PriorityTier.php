<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PriorityTierRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[Family(Level::PLATFORM)]
#[ORM\Entity(repositoryClass: PriorityTierRepository::class)]
#[ORM\Table(name: 'priority_tier')]
#[ORM\HasLifecycleCallbacks]
class PriorityTier
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'string', length: 1)]
    private string $label;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20)]
    private string $color;

    #[ORM\Column(type: 'integer')]
    private int $orToolsWeight;

    #[ORM\Column(type: 'integer')]
    private int $defaultMinSessions;

    public function __construct()
    {
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function getOrToolsWeight(): int
    {
        return $this->orToolsWeight;
    }

    public function setOrToolsWeight(int $orToolsWeight): self
    {
        $this->orToolsWeight = $orToolsWeight;

        return $this;
    }

    public function getDefaultMinSessions(): int
    {
        return $this->defaultMinSessions;
    }

    public function setDefaultMinSessions(int $defaultMinSessions): self
    {
        $this->defaultMinSessions = $defaultMinSessions;

        return $this;
    }
}
