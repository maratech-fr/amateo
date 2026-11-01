<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VenueMatchWindowRepository;
use App\Service\MatchConflictDetector;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A venue's MATCH access window (cadrage P1-4 §5.1) : the city hall grants the
 * club different accesses on match days than on training weekdays (« on n'a
 * plus accès à l'annexe et à De Barros que pour l'entraînement »). One row per
 * (venue, day, time range) — a venue with ≥ 1 window IS a match venue (the
 * derived flag, never a boolean on Venue).
 *
 * Season-scoped fact, NOT a period setting: no schedulePlanId (contrary to
 * VenueTrainingSlot's loaned slots). Copied on season transition — the city
 * hall convention renews. EXCEPT a dated opening (openingDate set): a one-shot
 * fact of one day, never copied, and it does not make a club « adopt » the
 * access windows ({@see MatchConflictDetector::hasWeeklyAccess}).
 */
#[ORM\Entity(repositoryClass: VenueMatchWindowRepository::class)]
#[ORM\Table(name: 'venue_match_window')]
#[ORM\Index(name: 'idx_venue_match_window_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_venue_match_window_venue', columns: ['venue_id'])]
#[ORM\HasLifecycleCallbacks]
class VenueMatchWindow implements TenantOwnedInterface
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
    private string $venueId;

    /**
     * ISO 1 (Monday) .. 7 (Sunday) — NOT weekend-restricted (Friday-night matches exist).
     * NULL for the « Jours fériés » line (PR2, Q1 option c) : that line carries no weekday
     * and applies to every official public holiday instead, and NULL for a dated opening.
     * Exactly one of dayOfWeek / appliesOnPublicHolidays / openingDate is set (enforced by
     * the state processor).
     */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $dayOfWeek = null;

    /**
     * PR2 (Q1 option c, décision fondateur 2026-10-27) — the « Jours fériés » line: TRUE means
     * the window applies to any official public holiday of the club's territory (table
     * public_holiday) instead of a weekday. A weekday line carries FALSE + a dayOfWeek.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $appliesOnPublicHolidays = false;

    /** A dated opening: this venue, this day only. It ADDS to the lines of that date. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $openingDate = null;

    /** The reason of a dated opening (« Coupe »). Screen only, never sent to the engine. */
    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $startTime;

    /** Same-day end, exclusive; never crosses midnight (P4-61 precedent). */
    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $endTime;

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

    public function getVenueId(): string
    {
        return $this->venueId;
    }

    public function setVenueId(string $venueId): self
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(?int $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function isAppliesOnPublicHolidays(): bool
    {
        return $this->appliesOnPublicHolidays;
    }

    public function setAppliesOnPublicHolidays(bool $appliesOnPublicHolidays): self
    {
        $this->appliesOnPublicHolidays = $appliesOnPublicHolidays;

        return $this;
    }

    public function getOpeningDate(): ?DateTimeImmutable
    {
        return $this->openingDate;
    }

    public function setOpeningDate(?DateTimeImmutable $openingDate): self
    {
        $this->openingDate = $openingDate;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getStartTime(): DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(DateTimeImmutable $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(DateTimeImmutable $endTime): self
    {
        $this->endTime = $endTime;

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
