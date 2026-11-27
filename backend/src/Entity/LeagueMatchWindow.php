<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LeagueMatchWindowRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Federation-imposed match kickoff windows per league × category × level
 * (× gender), the "envelope HARD" a club inherits (spec gestion-matchs §6bis).
 * GLOBAL reference, not tenant-owned: no club_id/season_id → no RLS, shared by
 * every club (same pattern as public_holiday / school_holiday_period). Seeded
 * from data/league-match-windows.aura.json (app:league-windows:seed).
 *
 * The AURA seed is the FEDERATION default base for EVERY club (couche 1 of the
 * 3-layer model: fédé → correction ligue → règles club). A club's league is
 * derived from its ffbbClubCode (LeagueResolver); until other leagues are
 * catalogued, all clubs inherit AURA.
 *
 * `level` = DEPARTEMENTAL | REGIONAL (federation tier). `gender` null = all
 * genders. `dayOfWeek` 1=Monday..7=Sunday. `kickoffMin`/`kickoffMax` bound the
 * tip-off, NOT a match duration.
 */
#[Family(Level::PLATFORM)]
#[ORM\Entity(repositoryClass: LeagueMatchWindowRepository::class)]
#[ORM\Table(name: 'league_match_window')]
#[ORM\UniqueConstraint(name: 'uniq_league_match_window', columns: ['league', 'category', 'level', 'gender', 'day_of_week', 'kickoff_min'])]
#[ORM\Index(name: 'idx_league_match_window_league', columns: ['league'])]
class LeagueMatchWindow implements LeagueWindowInterface
{
    public const LEVEL_DEPARTEMENTAL = 'DEPARTEMENTAL';
    public const LEVEL_REGIONAL = 'REGIONAL';

    /** Federation default base inherited by every club (couche 1). */
    public const FEDERATION_DEFAULT_LEAGUE = 'AURA';

    /** Readable name of FEDERATION_DEFAULT_LEAGUE, shown when a club falls back to it. */
    public const FEDERATION_DEFAULT_LEAGUE_LABEL = 'Auvergne-Rhône-Alpes';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 24)]
    private string $league;

    #[ORM\Column(type: 'string', length: 40)]
    private string $category;

    #[ORM\Column(type: 'string', length: 20)]
    private string $level;

    /** Null = applies to all genders; else a Gender enum value. */
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
        $this->createdAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
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
