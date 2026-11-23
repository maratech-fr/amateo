<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionPlanRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[Family(Level::PLATFORM)]
#[ORM\Entity(repositoryClass: SubscriptionPlanRepository::class)]
#[ORM\Table(name: 'subscription_plan')]
#[ORM\HasLifecycleCallbacks]
class SubscriptionPlan
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

    // Stable attribution key (never displayed) : `decouverte`, `complete`, `beta`. The
    // superadmin set-plan action targets a plan by CODE, not by its (volatile) display
    // name or UUID.
    #[ORM\Column(type: 'string', length: 60, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    // Convention: 0 = ILLIMITÉ (P1-3). The team cap lives on the tier of the paid offer
    // (SubscriptionPlanTier); maxGenerations is the Découverte output credit pool (10)
    // and stays 0 = illimité on the paid and bêta offers.
    #[ORM\Column(type: 'integer')]
    private int $maxVenues;

    #[ORM\Column(type: 'integer')]
    private int $maxGenerations;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $features = [];

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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

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

    public function getMaxVenues(): int
    {
        return $this->maxVenues;
    }

    public function setMaxVenues(int $maxVenues): self
    {
        $this->maxVenues = $maxVenues;

        return $this;
    }

    public function getMaxGenerations(): int
    {
        return $this->maxGenerations;
    }

    public function setMaxGenerations(int $maxGenerations): self
    {
        $this->maxGenerations = $maxGenerations;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /** @param array<string, mixed> $features */
    public function setFeatures(array $features): self
    {
        $this->features = $features;

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
