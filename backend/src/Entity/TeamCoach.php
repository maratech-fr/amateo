<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TeamCoachRole;
use App\Repository\TeamCoachRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamCoachRepository::class)]
#[ORM\Table(name: 'team_coach')]
#[ORM\Index(name: 'idx_team_coach_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_team_coach_team', columns: ['team_id'])]
#[ORM\Index(name: 'idx_team_coach_coach', columns: ['coach_id'])]
#[ORM\UniqueConstraint(name: 'uniq_team_coach_role', columns: ['team_id', 'coach_id', 'role'])]
#[ORM\HasLifecycleCallbacks]
class TeamCoach implements TenantOwnedInterface
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
    private string $teamId;

    #[ORM\Column(type: 'guid')]
    private string $coachId;

    #[ORM\Column(length: 20, enumType: TeamCoachRole::class)]
    private TeamCoachRole $role;

    #[ORM\Column(type: 'boolean')]
    private bool $isRequired = true;

    /** Le gestionnaire a choisi ce coach principal pour recevoir le lien de collecte de l'équipe. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $wishRecipient = false;

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

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): self
    {
        $this->teamId = $teamId;

        return $this;
    }

    public function getCoachId(): string
    {
        return $this->coachId;
    }

    public function setCoachId(string $coachId): self
    {
        $this->coachId = $coachId;

        return $this;
    }

    public function getRole(): TeamCoachRole
    {
        return $this->role;
    }

    public function setRole(TeamCoachRole $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getIsRequired(): bool
    {
        return $this->isRequired;
    }

    public function isIsRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $isRequired): self
    {
        $this->isRequired = $isRequired;

        return $this;
    }

    public function isWishRecipient(): bool
    {
        return $this->wishRecipient;
    }

    public function setWishRecipient(bool $wishRecipient): self
    {
        $this->wishRecipient = $wishRecipient;

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
