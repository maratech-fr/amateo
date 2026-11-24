<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TeamLinkIntensity;
use App\Enum\TeamLinkType;
use App\Repository\TeamLinkRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A declared link between two teams (cadrage P1-4 §6 — the « passerelle »):
 * « SM1 et SM2 partagent des joueurs » (NOT_SIMULTANEOUS) or « SF1 et PNM :
 * l'un après l'autre » (BACK_TO_BACK). No player entity exists — the manager
 * DECLARES the bridge; the app never guesses it.
 *
 * SYMMETRIC: the processor normalizes teamAId < teamBId (lexical uuid order)
 * so A–B ≡ B–A, and the DB unique makes one couple = one link = one type
 * (BACK_TO_BACK already implies non-simultaneity).
 *
 * Cross-module by design (neutral name): matches consume it first, the
 * training solver later (vision). Copied on season transition.
 */
#[Family(Level::SEASON, cold: Family::ALL, ofSocle: true, copiedToNextSeason: true)]
#[ORM\Entity(repositoryClass: TeamLinkRepository::class)]
#[ORM\Table(name: 'team_link')]
#[ORM\UniqueConstraint(name: 'uniq_team_link_couple', columns: ['club_id', 'season_id', 'team_a_id', 'team_b_id'])]
#[ORM\Index(name: 'idx_team_link_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_team_link_team_a', columns: ['team_a_id'])]
#[ORM\Index(name: 'idx_team_link_team_b', columns: ['team_b_id'])]
#[ORM\HasLifecycleCallbacks]
class TeamLink implements TenantOwnedInterface
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

    /**
     * Invariant: teamAId < teamBId (normalized by the processor).
     * Explicit column names: Doctrine's underscore strategy would render
     * `teamAId` as `team_aid`, not the `team_a_id` the migration creates.
     */
    #[ORM\Column(name: 'team_a_id', type: 'guid')]
    #[RefersTo(Team::class, OnDelete::DELETE)]
    private string $teamAId;

    #[ORM\Column(name: 'team_b_id', type: 'guid')]
    #[RefersTo(Team::class, OnDelete::DELETE)]
    private string $teamBId;

    #[ORM\Column(length: 20, enumType: TeamLinkType::class)]
    private TeamLinkType $linkType;

    /**
     * Intensité CÔTÉ ENTRAÎNEMENT (lot PASSERELLES). Défaut PREFERRED : l'existant
     * (créé avant ce champ, migration DEFAULT 'PREFERRED') devient PREFERRED. Ne
     * touche pas le rail matchs — arbitrage fondateur n°1.
     */
    #[ORM\Column(name: 'training_intensity', length: 20, enumType: TeamLinkIntensity::class, options: ['default' => 'PREFERRED'])]
    private TeamLinkIntensity $trainingIntensity = TeamLinkIntensity::PREFERRED;

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

    public function getTeamAId(): string
    {
        return $this->teamAId;
    }

    public function setTeamAId(string $teamAId): self
    {
        $this->teamAId = $teamAId;

        return $this;
    }

    public function getTeamBId(): string
    {
        return $this->teamBId;
    }

    public function setTeamBId(string $teamBId): self
    {
        $this->teamBId = $teamBId;

        return $this;
    }

    public function getLinkType(): TeamLinkType
    {
        return $this->linkType;
    }

    public function setLinkType(TeamLinkType $linkType): self
    {
        $this->linkType = $linkType;

        return $this;
    }

    public function getTrainingIntensity(): TeamLinkIntensity
    {
        return $this->trainingIntensity;
    }

    public function setTrainingIntensity(TeamLinkIntensity $trainingIntensity): self
    {
        $this->trainingIntensity = $trainingIntensity;

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
