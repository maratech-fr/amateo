<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MatchWeek;
use App\Repository\TeamMatchHabitRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A team's ideal match slot (« créneau idéal », cadrage P1-4 §5.3 — « nous sommes
 * des êtres d'habitude ») : « SF3 = dimanche 17h30 à Coubertin ». A POINT in time,
 * not a range (every founder example is a kickoff instant), venue optional (the
 * away-kickoff estimation only needs day+time). ONE per team (DB unique on
 * club+season+team since P4-271) — a team declares a SINGLE ideal slot, tagged
 * week {@see MatchWeek} A (the default) or B.
 *
 * The `week` tag is a VISUAL AID (the manager's ideal model), never a constraint:
 * it feeds the frontend's A/B typical-week view and NEVER travels to the engine —
 * the solver sees an habitual window without a week label.
 *
 * Serves three consumers: the PR D solver's SOFT preference, the weekend-grid
 * ghost blocks (protecting the weekends of a team whose calendar is not out
 * yet), and the away-kickoff estimation that feeds the conflict radar.
 * Copied on season transition (habits renew with the season, `week` carried).
 */
#[ORM\Entity(repositoryClass: TeamMatchHabitRepository::class)]
#[ORM\Table(name: 'team_match_habit')]
#[ORM\UniqueConstraint(name: 'uniq_team_match_habit_team', columns: ['club_id', 'season_id', 'team_id'])]
#[ORM\Index(name: 'idx_team_match_habit_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_team_match_habit_team', columns: ['team_id'])]
#[ORM\HasLifecycleCallbacks]
class TeamMatchHabit implements TenantOwnedInterface
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

    /** ISO 1 (Monday) .. 7 (Sunday). */
    #[ORM\Column(type: 'smallint')]
    private int $dayOfWeek;

    /** The habitual kickoff — an instant, not a window. */
    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $kickoffTime;

    /** Habitual HOME venue — null when the habit is day+time only. */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $venueId = null;

    /**
     * Semaine d'alternance du créneau idéal (aide visuelle A/B — P4-271). `A` par
     * défaut : un club sans alternance range tout en A. Ne voyage JAMAIS au moteur.
     */
    #[ORM\Column(type: 'string', length: 8, enumType: MatchWeek::class, options: ['default' => 'A'])]
    private MatchWeek $week = MatchWeek::A;

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

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getKickoffTime(): DateTimeImmutable
    {
        return $this->kickoffTime;
    }

    public function setKickoffTime(DateTimeImmutable $kickoffTime): self
    {
        $this->kickoffTime = $kickoffTime;

        return $this;
    }

    public function getVenueId(): ?string
    {
        return $this->venueId;
    }

    public function setVenueId(?string $venueId): self
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function getWeek(): MatchWeek
    {
        return $this->week;
    }

    public function setWeek(MatchWeek $week): self
    {
        $this->week = $week;

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
