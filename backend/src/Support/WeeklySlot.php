<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Un créneau de semaine : un jour de 1 (lundi) à 7 (dimanche), une heure de début en minutes depuis
 * minuit, une durée. Seule maison de la lecture d'une heure en minutes, du chevauchement de deux
 * créneaux (intervalles demi-ouverts : deux créneaux jointifs ne se chevauchent pas) et de la borne de
 * minuit (un créneau tient dans sa journée, 24:00 pile compris).
 */
final readonly class WeeklySlot
{
    public const int MINUTES_PER_DAY = 1440;

    public function __construct(public int $day, public int $startMinutes, public int $durationMinutes)
    {
        if ($day < 1 || $day > 7) {
            throw new InvalidArgumentException(\sprintf('Jour %d hors de la semaine (1 = lundi … 7 = dimanche).', $day));
        }
        if ($startMinutes < 0 || $startMinutes >= self::MINUTES_PER_DAY) {
            throw new InvalidArgumentException(\sprintf('Début %d hors de la journée (0 à 1439 minutes).', $startMinutes));
        }
        if ($durationMinutes < 1) {
            throw new InvalidArgumentException(\sprintf('Durée %d : un créneau dure au moins une minute.', $durationMinutes));
        }
    }

    public static function at(int $day, DateTimeInterface|string $start, int $durationMinutes): self
    {
        return new self($day, self::minutesOf($start), $durationMinutes);
    }

    /** « 9:00 », « 09:00 », « 09:00:00 » ou une date-heure → minutes depuis minuit (secondes ignorées). */
    public static function minutesOf(DateTimeInterface|string $time): int
    {
        $minutes = self::tryMinutesOf($time);
        if (null === $minutes) {
            throw new InvalidArgumentException(\sprintf('Heure illisible : « %s ».', \is_string($time) ? $time : $time->format('H:i:s')));
        }

        return $minutes;
    }

    /** Comme {@see minutesOf}, mais null pour une heure illisible ou une valeur qui n'est pas une heure écrite. */
    public static function tryMinutesOf(mixed $time): ?int
    {
        if ($time instanceof DateTimeInterface) {
            return (int) $time->format('G') * 60 + (int) $time->format('i');
        }
        if (!\is_string($time) || 1 !== preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $matches)) {
            return null;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    /** Minutes depuis minuit → « HH:MM ». */
    public static function clock(int $minutes): string
    {
        return \sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Deux intervalles demi-ouverts `[aStart, aEnd)` et `[bStart, bEnd)` s'intersectent. */
    public static function intervalsOverlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $bStart < $aEnd;
    }

    public function endMinutes(): int
    {
        return $this->startMinutes + $this->durationMinutes;
    }

    /** Même jour ET intervalles demi-ouverts qui s'intersectent. */
    public function overlaps(self $other): bool
    {
        return $this->day === $other->day
            && self::intervalsOverlap($this->startMinutes, $this->endMinutes(), $other->startMinutes, $other->endMinutes());
    }

    /** Fin au plus tard à minuit pile (24:00). */
    public function endsWithinDay(): bool
    {
        return $this->endMinutes() <= self::MINUTES_PER_DAY;
    }
}
