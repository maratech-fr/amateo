<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransitionReminderLogRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per (season × threshold) transition reminder actually sent — the
 * idempotence ledger of the app:seasons:remind-transition cron. Keyed on the
 * globally-unique seasonId (uuid), so it needs no club_id and no RLS (same
 * pattern as period_reminder_log). Lets a missed run catch up (bucket match)
 * without ever re-sending the same milestone.
 */
#[Family(Level::SEASON, cold: [], copiedToNextSeason: false)]
#[ORM\Entity(repositoryClass: TransitionReminderLogRepository::class)]
#[ORM\Table(name: 'transition_reminder_log')]
#[ORM\UniqueConstraint(name: 'uniq_transition_reminder', columns: ['season_id', 'threshold'])]
class TransitionReminderLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    /** Milestone bucket the reminder was sent for: 61, 30 or 14 days before the pivot. */
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
