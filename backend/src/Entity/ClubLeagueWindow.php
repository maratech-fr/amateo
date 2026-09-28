<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClubLeagueWindowRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * P4-272 ① — the per-club COPY of the federation match-window envelope (« écran
 * unique des contraintes de match », section Ligue). Tenant + season owned,
 * mirroring the business columns of the GLOBAL catalog ({@see LeagueMatchWindow})
 * but now the SINGLE house the placement payload, the conflict radar and
 * `GET /api/league-match-windows` read from — the global catalog only seeds this
 * copy (birth of a club, season transition) and feeds the suggestion (PR ②).
 *
 * Seeded from the club's EFFECTIVE league (its own if catalogued, else the
 * federation default AURA) so day-one behaviour is identical to reading the
 * catalog. The manager then edits/adds/removes rows freely (ClubLeagueWindow
 * CRUD, management-gated). An emptied copy = ZERO league rule: no HARD at
 * placement, one INFO diagnostic for the whole club (founder decision) — the
 * manager assumes it, exactly like an out-of-window manual placement, which
 * stays PERMITTED and merely SIGNALLED by the radar (LEAGUE_WINDOW_VIOLATION).
 *
 * `level` = DEPARTEMENTAL | REGIONAL (federation tier). `gender` null = all
 * genders. `dayOfWeek` 1=Monday..7=Sunday. `kickoffMin`/`kickoffMax` bound the
 * tip-off, NOT a match duration. Copied on season transition (the manager's
 * corrections renew with the season, like habits / access windows).
 */
#[ORM\Entity(repositoryClass: ClubLeagueWindowRepository::class)]
#[ORM\Table(name: 'club_league_window')]
#[ORM\UniqueConstraint(name: 'uniq_club_league_window', columns: ['club_id', 'season_id', 'category', 'level', 'gender', 'day_of_week', 'kickoff_min'])]
#[ORM\Index(name: 'idx_club_league_window_club_season', columns: ['club_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class ClubLeagueWindow implements TenantOwnedInterface, LeagueWindowInterface
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

    /** Provenance: the effective league the copy was seeded from. */
    #[ORM\Column(type: 'string', length: 24)]
    private string $league;

    #[ORM\Column(type: 'string', length: 40)]
    private string $category;

    #[ORM\Column(type: 'string', length: 20)]
    private string $level;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(type: 'smallint')]
    private int $dayOfWeek;

    #[ORM\Column(name: 'kickoff_min', type: 'time_immutable')]
    private DateTimeImmutable $kickoffMin;

    #[ORM\Column(name: 'kickoff_max', type: 'time_immutable')]
    private DateTimeImmutable $kickoffMax;

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

    public function getLeague(): string
    {
        return $this->league;
    }

    public function setLeague(string $league): self
    {
        $this->league = $league;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): self
    {
        $this->level = $level;

        return $this;
    }

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(?string $gender): self
    {
        $this->gender = $gender;

        return $this;
    }

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getKickoffMin(): DateTimeImmutable
    {
        return $this->kickoffMin;
    }

    public function setKickoffMin(DateTimeImmutable $kickoffMin): self
    {
        $this->kickoffMin = $kickoffMin;

        return $this;
    }

    public function getKickoffMax(): DateTimeImmutable
    {
        return $this->kickoffMax;
    }

    public function setKickoffMax(DateTimeImmutable $kickoffMax): self
    {
        $this->kickoffMax = $kickoffMax;

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
