<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TeamTagAxis;
use App\Repository\TeamTagRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[Family(Level::CLUB, keptAtSeasonPurge: 'club-scoped sans saison — porte de sortie ErasedClubPurger')]
#[ORM\Entity(repositoryClass: TeamTagRepository::class)]
#[ORM\Table(name: 'team_tag')]
#[ORM\Index(name: 'idx_team_tag_club', columns: ['club_id'])]
// P4-64 : un nom de tag est unique DANS un club. Sans cet index, deux écritures de Team
// concurrentes créaient le même tag deux fois, et le résolveur en choisissait une pendant que
// les assignations se répartissaient entre les deux — une contrainte n'atteignait alors
// qu'une partie des équipes, sans erreur.
#[ORM\UniqueConstraint(name: 'uniq_team_tag_club_name', columns: ['club_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class TeamTag implements TenantOwnedInterface
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

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSystem = false;

    /** GENRE / NIVEAU / AGE for the constraint target grouping — null when the tag fits no axis. */
    #[ORM\Column(length: 20, nullable: true, enumType: TeamTagAxis::class)]
    private ?TeamTagAxis $axis = null;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function getIsSystem(): bool
    {
        return $this->isSystem;
    }

    public function isIsSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): self
    {
        $this->isSystem = $isSystem;

        return $this;
    }

    public function getAxis(): ?TeamTagAxis
    {
        return $this->axis;
    }

    public function setAxis(?TeamTagAxis $axis): self
    {
        $this->axis = $axis;

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
