<?php

declare(strict_types=1);

namespace App\Entity;

use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Period-editable structure: a SPARSE override that DISABLES a permanent constraint
 * for one CLOSURE period (CalendarEntry). A row exists ONLY when the manager turned a
 * constraint off for that period — no row means "the permanent constraint applies as
 * usual". Used by the overlay build to drop deactivated constraints; the base plan
 * (and the Constraint's own isActive) are never touched.
 */
#[Family(Level::PLAN)]
#[ORM\Entity]
#[ORM\Table(name: 'constraint_period_override')]
#[ORM\UniqueConstraint(name: 'uniq_constraint_period_override', columns: ['schedule_plan_id', 'constraint_id'])]
#[ORM\Index(name: 'idx_constraint_period_override_plan', columns: ['schedule_plan_id'])]
class ConstraintPeriodOverride implements TenantOwnedInterface
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
    #[RefersTo(SchedulePlan::class, OnDelete::DELETE)]
    private string $schedulePlanId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Constraint::class, OnDelete::DELETE)]
    private string $constraintId;

    /** false = the constraint does NOT apply during this period. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

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

    public function getConstraintId(): string
    {
        return $this->constraintId;
    }

    public function setConstraintId(string $constraintId): self
    {
        $this->constraintId = $constraintId;

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

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
