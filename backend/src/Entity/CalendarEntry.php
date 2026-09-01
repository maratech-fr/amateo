<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Repository\CalendarEntryRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A dated entry on the club's season calendar (cockpit exception layer).
 * kind=event: an informative marker (AG, tournament), optionally disruptive.
 * kind=period: a window that alters the base plan (closure, holiday, cutoff,
 * mutualisation); carries dated constraints (Constraint.calendarEntryId). Its
 * secondary plan (ADR-0002) lives in schedule_plan.calendarEntryId — the active
 * version is the plan's chosenScheduleId, no longer a pointer on the entry.
 * See specs/courantes/accueil-cockpit-temporel.md §9ter.
 */
#[ORM\Entity(repositoryClass: CalendarEntryRepository::class)]
#[ORM\Table(name: 'calendar_entry')]
#[ORM\Index(name: 'idx_calendar_entry_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_calendar_entry_window', columns: ['club_id', 'start_date', 'end_date'])]
#[ORM\Index(name: 'idx_calendar_entry_parent', columns: ['parent_entry_id'])]
#[ORM\HasLifecycleCallbacks]
class CalendarEntry implements TenantOwnedInterface
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

    #[ORM\Column(length: 20, enumType: CalendarEntryKind::class)]
    private CalendarEntryKind $kind;

    #[ORM\Column(type: 'string', length: 180)]
    private string $title;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $endDate;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDisruptive = false;

    #[ORM\Column(length: 20, nullable: true, enumType: CalendarEntryPeriodType::class)]
    private ?CalendarEntryPeriodType $periodType = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $schoolHolidayId = null;

    // Découpage à la semaine (P2-5 E1, fondateur 2026-07-18) : une entrée ENFANT
    // couvre UNE semaine pleine (lun→dim ∩ saison) d'une période mère et porte son
    // propre plan par le rail existant (1 entrée = 1 plan). Un seul niveau — un
    // enfant n'est jamais parent. null = entrée racine (mère ou période simple).
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parentEntryId = null;

    #[ORM\Column(length: 20, enumType: CalendarEntryStatus::class, options: ['default' => 'active'])]
    private CalendarEntryStatus $status = CalendarEntryStatus::ACTIVE;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $createdBy = null;

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

    public function getKind(): CalendarEntryKind
    {
        return $this->kind;
    }

    public function setKind(CalendarEntryKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

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

    public function getIsDisruptive(): bool
    {
        return $this->isDisruptive;
    }

    public function setIsDisruptive(bool $isDisruptive): self
    {
        $this->isDisruptive = $isDisruptive;

        return $this;
    }

    public function getPeriodType(): ?CalendarEntryPeriodType
    {
        return $this->periodType;
    }

    public function setPeriodType(?CalendarEntryPeriodType $periodType): self
    {
        $this->periodType = $periodType;

        return $this;
    }

    public function getParentEntryId(): ?string
    {
        return $this->parentEntryId;
    }

    /**
     * P2-59 — SOURCE UNIQUE du modèle FAIT/GENÈSE : la lecture des contraintes datées d'un
     * plan de période est l'UNION de deux natures.
     *  - un FAIT vit sur la MÈRE (l'incident : `venue_closed` décrit le fait, pas la réponse)
     *    et vaut pour toutes ses semaines ;
     *  - une GENÈSE vit sur l'entrée-ENFANT (la semaine) et ne vaut que pour ce plan seul.
     * Une entrée-ENFANT lit donc SES genèses ∪ les faits de SA mère → `[id, parentEntryId]` ;
     * une entrée RACINE ne porte que ses propres datées → `[id]` (byte-identique à l'ancien
     * modèle : elle est sa propre source).
     *
     * @return non-empty-list<string>
     */
    public function datedConstraintSourceIds(): array
    {
        return null === $this->parentEntryId ? [$this->id] : [$this->id, $this->parentEntryId];
    }

    public function setParentEntryId(?string $parentEntryId): self
    {
        $this->parentEntryId = $parentEntryId;

        return $this;
    }

    public function getSchoolHolidayId(): ?string
    {
        return $this->schoolHolidayId;
    }

    public function setSchoolHolidayId(?string $schoolHolidayId): self
    {
        $this->schoolHolidayId = $schoolHolidayId;

        return $this;
    }

    public function getStatus(): CalendarEntryStatus
    {
        return $this->status;
    }

    public function setStatus(CalendarEntryStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): self
    {
        $this->createdBy = $createdBy;

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
