<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CoachRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CoachRepository::class)]
#[ORM\Table(name: 'coach')]
#[ORM\Index(name: 'idx_coach_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_coach_parent', columns: ['parent_coach_id'])]
#[ORM\HasLifecycleCallbacks]
class Coach implements TenantOwnedInterface
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

    #[ORM\Column(type: 'string', length: 120)]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 120)]
    private string $lastName;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $maxDaysOverride = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $acceptableLateMinutes = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean')]
    private bool $isEmployee = false;

    /**
     * P2-53 RMM-8 — véhiculé ou non. Défaut false (prudent : « pas véhiculé »
     * applique la limite À PIED). Consommé par le solveur en PR-2 : véhiculé →
     * barème voiture d'une paire de gymnases, sinon barème à pied.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVehicled = false;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parentCoachId = null;

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

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getMaxDaysOverride(): ?int
    {
        return $this->maxDaysOverride;
    }

    public function setMaxDaysOverride(?int $maxDaysOverride): self
    {
        $this->maxDaysOverride = $maxDaysOverride;

        return $this;
    }

    public function getAcceptableLateMinutes(): ?int
    {
        return $this->acceptableLateMinutes;
    }

    public function setAcceptableLateMinutes(?int $acceptableLateMinutes): self
    {
        $this->acceptableLateMinutes = $acceptableLateMinutes;

        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isEmployee(): bool
    {
        return $this->isEmployee;
    }

    public function setIsEmployee(bool $isEmployee): self
    {
        $this->isEmployee = $isEmployee;

        return $this;
    }

    public function isVehicled(): bool
    {
        return $this->isVehicled;
    }

    public function setIsVehicled(bool $isVehicled): self
    {
        $this->isVehicled = $isVehicled;

        return $this;
    }

    public function getParentCoachId(): ?string
    {
        return $this->parentCoachId;
    }

    public function setParentCoachId(?string $parentCoachId): self
    {
        $this->parentCoachId = $parentCoachId;

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
