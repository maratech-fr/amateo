<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Gender;
use App\Repository\SportCategoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SportCategoryRepository::class)]
#[ORM\Table(name: 'sport_category')]
#[ORM\Index(name: 'idx_sport_category_sport', columns: ['sport_id'])]
#[ORM\Index(name: 'idx_sport_category_club', columns: ['club_id'])]
#[ORM\HasLifecycleCallbacks]
class SportCategory implements TenantOwnedInterface
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

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $clubId = null;

    #[ORM\Column(type: 'guid')]
    private string $sportId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'boolean')]
    private bool $isCustom = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ageMin = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ageMax = null;

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(length: 10, nullable: true, enumType: Gender::class)]
    private ?Gender $gender = null;

    // P2-54 RMM-9 — per-category match duration override (minutes). NULL means
    // « follow the family default » (MatchDurationResolver), never 0.
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $matchMinutes = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $warmupMinutes = null;

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

    public function getClubId(): ?string
    {
        return $this->clubId;
    }

    public function setClubId(?string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSportId(): string
    {
        return $this->sportId;
    }

    public function setSportId(string $sportId): self
    {
        $this->sportId = $sportId;

        return $this;
    }

    public function setSport(Sport $sport): self
    {
        $this->sportId = $sport->getId();

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

    public function getIsCustom(): bool
    {
        return $this->isCustom;
    }

    public function isIsCustom(): bool
    {
        return $this->isCustom;
    }

    public function setIsCustom(bool $isCustom): self
    {
        $this->isCustom = $isCustom;

        return $this;
    }

    public function getAgeMin(): ?int
    {
        return $this->ageMin;
    }

    public function setAgeMin(?int $ageMin): self
    {
        $this->ageMin = $ageMin;

        return $this;
    }

    public function getAgeMax(): ?int
    {
        return $this->ageMax;
    }

    public function setAgeMax(?int $ageMax): self
    {
        $this->ageMax = $ageMax;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getGender(): ?Gender
    {
        return $this->gender;
    }

    public function setGender(?Gender $gender): self
    {
        $this->gender = $gender;

        return $this;
    }

    public function getMatchMinutes(): ?int
    {
        return $this->matchMinutes;
    }

    public function setMatchMinutes(?int $matchMinutes): self
    {
        $this->matchMinutes = $matchMinutes;

        return $this;
    }

    public function getWarmupMinutes(): ?int
    {
        return $this->warmupMinutes;
    }

    public function setWarmupMinutes(?int $warmupMinutes): self
    {
        $this->warmupMinutes = $warmupMinutes;

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
