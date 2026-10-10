<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Period-editable structure: a SPARSE override of a team's participation for one
 * period (CalendarEntry). A row exists ONLY when the manager changed the team for
 * that period — no row means "seasonal defaults apply". Used by the overlay build
 * to drop deactivated teams and override sessions-per-week; the base plan (and the
 * Team's seasonal fields) are never touched.
 */
#[ORM\Entity]
#[ORM\Table(name: 'team_period_override')]
#[ORM\UniqueConstraint(name: 'uniq_team_period_override', columns: ['schedule_plan_id', 'team_id'])]
#[ORM\Index(name: 'idx_team_period_override_plan', columns: ['schedule_plan_id'])]
class TeamPeriodOverride implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

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
     * ADR-0002 inv. 5 — les réglages de période s'accrochent au PLAN, pas au déclencheur
     * calendrier. Aujourd'hui un plan par période (uniq_schedule_plan_calendar_entry), donc
     * l'ancre revient au même ; c'est le découpage hebdomadaire (types-de-planning E1) qui
     * la rend nécessaire : 2 semaines ⇒ 2 plans ⇒ 2 jeux de réglages sur le MÊME déclencheur,
     * que `calendarEntryId` ne saurait pas distinguer.
     */
    #[ORM\Column(type: 'guid')]
    private string $schedulePlanId;

    #[ORM\Column(type: 'guid')]
    private string $teamId;

    /** false = the team does NOT train during this period. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    /** null = keep the team's seasonal sessionsPerWeek; set = the period-specific volume. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sessionsPerWeek = null;

    /**
     * Generic ORIGIN marker for an override NOT entered by hand by the manager. null = a
     * manager-entered override (the historical default). A producer stamps its own value — e.g.
     * 'mutualisation' when a mutualize gesture activates a zero-session team so it can join a
     * shared block. Several rails may reuse this column with their own marker.
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $source = null;

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

    public function getClubId(): ?string
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

    public function getSchedulePlanId(): string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(string $schedulePlanId): self
    {
        $this->schedulePlanId = $schedulePlanId;

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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getSessionsPerWeek(): ?int
    {
        return $this->sessionsPerWeek;
    }

    public function setSessionsPerWeek(?int $sessionsPerWeek): self
    {
        $this->sessionsPerWeek = $sessionsPerWeek;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->source = $source;

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
