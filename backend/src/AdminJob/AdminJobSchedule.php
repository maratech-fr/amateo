<?php

declare(strict_types=1);

namespace App\AdminJob;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

final readonly class AdminJobSchedule
{
    private const TIMEZONE = 'Europe/Paris';

    private function __construct(public string $cadence, private int $hour = 0, private int $minute = 0)
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new InvalidArgumentException('A job schedule time must be a valid hour and minute.');
        }
    }

    public static function everyTenMinutes(): self
    {
        return new self('every_10_minutes');
    }

    public static function daily(int $hour, int $minute = 0): self
    {
        return new self('daily', $hour, $minute);
    }

    public static function weekly(int $hour, int $minute = 0): self
    {
        return new self('weekly', $hour, $minute);
    }

    public static function quarterly(int $hour, int $minute = 0): self
    {
        return new self('quarterly', $hour, $minute);
    }

    /**
     * SA4 — cadence des ACTIONS support : déclenchement manuel uniquement, jamais
     * planifié. Le scheduler n'itère que le catalogue des jobs ; une définition
     * manuelle qui y entrerait par erreur lève au lieu de tourner en silence.
     */
    public static function manual(): self
    {
        return new self('manual');
    }

    public function nextDueAt(DateTimeImmutable $now, ?DateTimeImmutable $lastScheduledFor): DateTimeImmutable
    {
        if ('manual' === $this->cadence) {
            throw new LogicException('A manual action has no schedule — it must never reach the scheduler.');
        }

        $due = $this->mostRecentDueAt($now);
        if (!$lastScheduledFor instanceof DateTimeImmutable || $lastScheduledFor < $due) {
            return $due;
        }

        return match ($this->cadence) {
            'every_10_minutes' => $due->modify('+10 minutes'),
            'daily' => $due->modify('+1 day'),
            'weekly' => $due->modify('+7 days'),
            'quarterly' => $due->modify('+3 months'),
            default => throw new LogicException(\sprintf('Unsupported admin job cadence "%s".', $this->cadence)),
        };
    }

    private function mostRecentDueAt(DateTimeImmutable $now): DateTimeImmutable
    {
        $local = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
        if ('every_10_minutes' === $this->cadence) {
            return $local->setTime((int) $local->format('H'), intdiv((int) $local->format('i'), 10) * 10);
        }

        if ('daily' === $this->cadence) {
            $candidate = $local->setTime($this->hour, $this->minute);

            return $candidate <= $local ? $candidate : $candidate->modify('-1 day');
        }

        if ('weekly' === $this->cadence) {
            // Dimanche = 0 (w) : recule jusqu'au dimanche de la semaine courante à
            // l'heure voulue, puis d'une semaine de plus si ce créneau n'a pas encore eu lieu.
            $candidate = $local->modify(\sprintf('-%d days', (int) $local->format('w')))->setTime($this->hour, $this->minute);

            return $candidate <= $local ? $candidate : $candidate->modify('-7 days');
        }

        $year = (int) $local->format('Y');
        foreach ([10, 7, 4, 1] as $month) {
            $candidate = $local->setDate($year, $month, 1)->setTime($this->hour, $this->minute);
            if ($candidate <= $local) {
                return $candidate;
            }
        }

        return $local->setDate($year - 1, 10, 1)->setTime($this->hour, $this->minute);
    }
}
