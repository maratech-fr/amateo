<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SchedulePlanType;
use App\Service\SchedulePlanProvisioner;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A SchedulePlan (ADR-0002) — the first-order, named container the manager
 * thinks in ("le planning"). Its Schedules are its VERSIONS
 * (Schedule.schedulePlanId + Schedule.versionNumber).
 *
 * - SEASON: the base plan of the whole season, exactly one per season
 *   (calendarEntryId null). Its name is the season plan's public name.
 * - CLOSURE / HOLIDAY: a bounded secondary plan bound to a CalendarEntry period
 *   (calendarEntryId set), created with the manager's gesture — "ajuster cette
 *   période" IS the creation of the CalendarEntry, and of this plan with it
 *   (lot C; it used to appear lazily at the period's first version, which was too
 *   late for the settings that hang off the plan).
 *
 * chosenScheduleId points at the version the manager validated ("le pointeur");
 * null = espace de travail (no version chosen yet).
 *
 * Named SchedulePlan (not Plan) so the domain concept never collides with the
 * billing catalogue (App\Entity\SubscriptionPlan).
 *
 * The SEASON plan and the version it points at ARE the season's calendar: every
 * reader (conflict radar, routing, guided mode, match module) derives "settled"
 * from this pointer and from nothing else. The mirrors that used to answer the
 * same question (Season.baselineScheduleId, VALIDATED/ARCHIVED statuses) are
 * gone — two truths is what made them diverge.
 */
#[ORM\Entity]
#[ORM\Table(name: 'schedule_plan')]
#[ORM\Index(name: 'idx_schedule_plan_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_schedule_plan_lock_source', columns: ['lock_source_schedule_id'])]
// At most one SEASON plan per season (the base plan is unique).
#[ORM\UniqueConstraint(name: 'uniq_schedule_plan_season_base', columns: ['season_id'], options: ['where' => '(type = \'SEASON\')'])]
// At most one plan per period entry (a period has one plan, many versions).
#[ORM\UniqueConstraint(name: 'uniq_schedule_plan_calendar_entry', columns: ['calendar_entry_id'], options: ['where' => '(calendar_entry_id IS NOT NULL)'])]
#[ORM\HasLifecycleCallbacks]
class SchedulePlan implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(length: 20, enumType: SchedulePlanType::class)]
    private SchedulePlanType $type;

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $startDate;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $endDate;

    /** Set on CLOSURE/HOLIDAY plans — the period this plan adjusts. null on SEASON. */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $calendarEntryId = null;

    /** The validated version ("le pointeur"). null = espace de travail. */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $chosenScheduleId = null;

    /**
     * V1bis — le POINTEUR de la version dont les verrous HARD sont la SOURCE des données
     * « en cours » du plan (ce dont « Régénérer » repart). Posé par « Charger » (endpoint
     * load-locks) et re-pointé à chaque nouvelle version COMPLETED (générer devient la
     * source). null = aucun pointeur → repli sur la dernière version COMPLETED. Lu UNIQUEMENT
     * par la maison unique {@see SchedulePlanProvisioner::lockSourceScheduleIdOfPlan}
     * (SEASON comme PÉRIODE). FK ON DELETE SET NULL : supprimer la version pointée relâche le
     * pointeur, le repli reprend la main.
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $lockSourceScheduleId = null;

    /**
     * Instant de la PREMIÈRE validation du plan (stat superadmin « temps de
     * clôture » : création → 1re validation, décision fondateur 2026-07-18).
     * Posé UNE fois par choose() (COALESCE en SQL), jamais effacé — une
     * réouverture/revalidation ne le touche pas. null = jamais validé.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $firstChosenAt = null;

    /**
     * Monotonic version counter (ADR-0002 lot B1). Never derived from
     * MAX(versionNumber): validating a plan DELETES the non-chosen versions, so a
     * MAX-based number would be reused (a deleted V3 then regenerated as V3
     * again). Incremented in SQL and never decremented.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $lastVersionNumber = 0;

    /**
     * Period-editable structure (ADR-0002 inv. 5): has the manager configured this
     * plan's team selection at least once? Distinguishes a FRESH plan (seed the
     * Fanion-only default) from one set back to all-active (0 sparse overrides, NOT
     * to be re-seeded). Set true on the first TeamPeriodOverride write; survives
     * reload (unlike a client-side guard).
     *
     * Lot C: moved off CalendarEntry — it is a property of the RESPONSE (the plan),
     * not of the FACT (the calendar event). Meaningless on SEASON plans, which have
     * no period team-selection step; only CLOSURE/HOLIDAY ever flip it.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $teamSelectionInitialized = false;

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

    public function getType(): SchedulePlanType
    {
        return $this->type;
    }

    public function setType(SchedulePlanType $type): self
    {
        $this->type = $type;

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

    public function getStartDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getCalendarEntryId(): ?string
    {
        return $this->calendarEntryId;
    }

    public function setCalendarEntryId(?string $calendarEntryId): self
    {
        $this->calendarEntryId = $calendarEntryId;

        return $this;
    }

    public function isTeamSelectionInitialized(): bool
    {
        return $this->teamSelectionInitialized;
    }

    public function setTeamSelectionInitialized(bool $teamSelectionInitialized): self
    {
        $this->teamSelectionInitialized = $teamSelectionInitialized;

        return $this;
    }

    public function getChosenScheduleId(): ?string
    {
        return $this->chosenScheduleId;
    }

    public function setChosenScheduleId(?string $chosenScheduleId): self
    {
        $this->chosenScheduleId = $chosenScheduleId;

        return $this;
    }

    public function getFirstChosenAt(): ?DateTimeImmutable
    {
        return $this->firstChosenAt;
    }

    public function getLockSourceScheduleId(): ?string
    {
        return $this->lockSourceScheduleId;
    }

    public function setLockSourceScheduleId(?string $lockSourceScheduleId): self
    {
        $this->lockSourceScheduleId = $lockSourceScheduleId;

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
