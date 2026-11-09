<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HolidayReminderLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per (season × school holiday × threshold) holiday reminder actually sent — the
 * idempotence ledger of the school-holiday branch of app:periods:remind. Keyed on the
 * club-owned season_id (uuid), so it needs no club_id and no RLS (same pattern as
 * transition_reminder_log). Written by {@see HolidayReminderLogRepository::markSent} with
 * INSERT … ON CONFLICT DO NOTHING, never persist+flush; purged with the season.
 */
#[ORM\Entity(repositoryClass: HolidayReminderLogRepository::class)]
#[ORM\Table(name: 'holiday_reminder_log')]
#[ORM\UniqueConstraint(name: 'uniq_holiday_reminder', columns: ['season_id', 'school_holiday_id', 'threshold'])]
class HolidayReminderLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'guid')]
    private string $schoolHolidayId;

    /** Milestone bucket the reminder was sent for: 30, 14 or 7 days before the holiday. */
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

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getSchoolHolidayId(): string
    {
        return $this->schoolHolidayId;
    }

    public function setSchoolHolidayId(string $schoolHolidayId): self
    {
        $this->schoolHolidayId = $schoolHolidayId;

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
