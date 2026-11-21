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
 * Une CAMPAGNE de collecte des doléances coachs pour une période de vacances (feature #10,
 * lot C2). Une par période (unique sur calendarEntryId), MODIFIABLE : le gestionnaire y
 * choisit les semaines et les équipes concernées + une date limite (extensible).
 *
 * Les tokens des coachs (CoachWishToken) pendent à la campagne. Les doléances (CoachWish),
 * elles, restent ancrées à la période et SURVIVENT à la suppression de la campagne :
 * annuler une collecte n'efface pas la todo-list.
 */
#[Family(Level::SEASON)]
#[ORM\Entity]
#[ORM\Table(name: 'coach_wish_campaign')]
#[ORM\UniqueConstraint(name: 'uniq_coach_wish_campaign_entry', columns: ['calendar_entry_id'])]
class CoachWishCampaign implements TenantOwnedInterface
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

    /** La période MÈRE des vacances (kind=PERIOD, periodType=HOLIDAY, parentEntryId=null). */
    #[ORM\Column(type: 'guid')]
    #[RefersTo(CalendarEntry::class, OnDelete::DELETE, owner: true)]
    private string $calendarEntryId;

    /** Date limite : au-delà, les liens coachs sont morts (410). Extensible → ils revivent. */
    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $deadline;

    /**
     * Semaines retenues (lundis Y-m-d).
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $weeks = [];

    /**
     * Équipes retenues.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $teamIds = [];

    /** Dernier digest 7h (C3) — le prochain ne part que si une réponse lui est postérieure. */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lastDigestAt = null;

    /** Dernière relance manuelle (C3) — une seule par jour Europe/Paris (anti-harcèlement). */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lastReminderAt = null;

    /** Récap final (C3) — envoyé UNE fois après la deadline, quel que soit l'état (D5). */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $finalRecapSentAt = null;

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

    public function touch(): self
    {
        $this->updatedAt = new DateTimeImmutable;

        return $this;
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

    public function getCalendarEntryId(): string
    {
        return $this->calendarEntryId;
    }

    public function setCalendarEntryId(string $calendarEntryId): self
    {
        $this->calendarEntryId = $calendarEntryId;

        return $this;
    }

    public function getDeadline(): DateTimeImmutable
    {
        return $this->deadline;
    }

    public function setDeadline(DateTimeImmutable $deadline): self
    {
        $this->deadline = $deadline;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getWeeks(): array
    {
        return $this->weeks;
    }

    /**
     * @param list<string> $weeks
     */
    public function setWeeks(array $weeks): self
    {
        $this->weeks = $weeks;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getTeamIds(): array
    {
        return $this->teamIds;
    }

    /**
     * @param list<string> $teamIds
     */
    public function setTeamIds(array $teamIds): self
    {
        $this->teamIds = $teamIds;

        return $this;
    }

    public function getLastDigestAt(): ?DateTimeImmutable
    {
        return $this->lastDigestAt;
    }

    public function markDigestSent(DateTimeImmutable $at): self
    {
        $this->lastDigestAt = $at;

        return $this;
    }

    public function getLastReminderAt(): ?DateTimeImmutable
    {
        return $this->lastReminderAt;
    }

    public function markReminderSent(DateTimeImmutable $at): self
    {
        $this->lastReminderAt = $at;

        return $this;
    }

    public function getFinalRecapSentAt(): ?DateTimeImmutable
    {
        return $this->finalRecapSentAt;
    }

    public function markFinalRecapSent(DateTimeImmutable $at): self
    {
        $this->finalRecapSentAt = $at;

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
