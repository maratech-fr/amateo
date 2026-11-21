<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PeriodReminderLogRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per (period entry × threshold) reminder actually sent — the idempotence
 * ledger of the app:periods:remind cron. Keyed on the globally-unique
 * calendarEntryId (uuid), so it needs no club_id and no RLS (like the
 * school_holiday_period reference table). Lets a missed daily run catch up
 * (window match) without ever re-sending the same milestone.
 */
#[Family(Level::SEASON)]
#[ORM\Entity(repositoryClass: PeriodReminderLogRepository::class)]
#[ORM\Table(name: 'period_reminder_log')]
#[ORM\UniqueConstraint(name: 'uniq_period_reminder', columns: ['calendar_entry_id', 'threshold'])]
class PeriodReminderLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(CalendarEntry::class, OnDelete::DELETE, owner: true)]
    private string $calendarEntryId;

    /** Milestone bucket the reminder was sent for: 14, 7 or 3. */
    #[ORM\Column(type: 'smallint')]
    private int $threshold;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $sentAt;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $this->sentAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
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

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function setThreshold(int $threshold): self
    {
        $this->threshold = $threshold;

        return $this;
    }

    public function getSentAt(): DateTimeImmutable
    {
        return $this->sentAt;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
