<?php

declare(strict_types=1);

namespace App\Entity;

use App\Matches\CompetitionRegistry;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'engagement d'une équipe dans une compétition : son libellé FBI, sa poule et ses journées attendues.
 * Au plus un par (compétition, équipe). Lu et écrit par {@see CompetitionRegistry} seulement.
 *
 * Clés posées à la main en migration (invisibles de Doctrine) : `(competition_id, club_id)` →
 * `competition (id, club_id)` `ON DELETE CASCADE` ; le match désigne l'engagement par
 * `(competition_id, team_id, club_id)` `ON DELETE RESTRICT`. Aucune clé vers l'équipe : la suppression
 * d'une équipe passe par sa cascade (`CascadePlan::forTeam`).
 */
#[Family(Level::SEASON, cold: [], copiedToNextSeason: false)]
#[ORM\Entity]
#[ORM\Table(name: 'engagement')]
#[ORM\Index(name: 'idx_engagement_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_engagement_team', columns: ['team_id'])]
#[ORM\UniqueConstraint(name: 'uniq_engagement_competition_team', columns: ['competition_id', 'team_id'])]
#[ORM\UniqueConstraint(name: 'uniq_engagement_competition_team_club', columns: ['competition_id', 'team_id', 'club_id'])]
#[ORM\HasLifecycleCallbacks]
class Engagement implements TenantOwnedInterface
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
    #[RefersTo(Competition::class, OnDelete::DELETE)]
    private string $competitionId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Team::class, OnDelete::DELETE)]
    private string $teamId;

    /**
     * Le libellé d'équipe du club tel que l'export FBI l'écrit (« B CHARPENNES CROIX LUIZET - 2 ») : ce qui
     * distingue deux équipes du club dans une même division. Null quand la division suffit.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $fbiTeamLabel = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ffbbPouleId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ffbbPouleName = null;

    /**
     * Les clubs de la poule, copiés à l'appariement : la donnée hors ligne de la garde de poule de l'import.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $ffbbPouleOpponents = null;

    /** 2×(N−1) pour une poule de N clubs, figé à l'appariement. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $expectedMatchdays = null;

    public function __construct(string $clubId, string $seasonId, string $competitionId, string $teamId)
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $this->id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->clubId = $clubId;
        $this->seasonId = $seasonId;
        $this->competitionId = $competitionId;
        $this->teamId = $teamId;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
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

    public function getCompetitionId(): string
    {
        return $this->competitionId;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function getFbiTeamLabel(): ?string
    {
        return $this->fbiTeamLabel;
    }

    public function setFbiTeamLabel(?string $fbiTeamLabel): self
    {
        $this->fbiTeamLabel = $fbiTeamLabel;

        return $this;
    }

    public function getFfbbPouleId(): ?string
    {
        return $this->ffbbPouleId;
    }

    public function setFfbbPouleId(?string $ffbbPouleId): self
    {
        $this->ffbbPouleId = $ffbbPouleId;

        return $this;
    }

    public function getFfbbPouleName(): ?string
    {
        return $this->ffbbPouleName;
    }

    public function setFfbbPouleName(?string $ffbbPouleName): self
    {
        $this->ffbbPouleName = $ffbbPouleName;

        return $this;
    }

    /** @return list<string>|null */
    public function getFfbbPouleOpponents(): ?array
    {
        return $this->ffbbPouleOpponents;
    }

    /** @param list<string>|null $ffbbPouleOpponents */
    public function setFfbbPouleOpponents(?array $ffbbPouleOpponents): self
    {
        $this->ffbbPouleOpponents = $ffbbPouleOpponents;

        return $this;
    }

    public function getExpectedMatchdays(): ?int
    {
        return $this->expectedMatchdays;
    }

    public function setExpectedMatchdays(?int $expectedMatchdays): self
    {
        $this->expectedMatchdays = $expectedMatchdays;

        return $this;
    }
}
