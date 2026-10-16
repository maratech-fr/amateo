<?php

declare(strict_types=1);

namespace App\Tests\Unit\AdminJob;

use App\AdminJob\AdminJobSchedule;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdminJobScheduleTest extends TestCase
{
    public function testTenMinuteScheduleCatchesUpTheLatestSlotThenMovesForward(): void
    {
        $schedule = AdminJobSchedule::everyTenMinutes();
        $now = new DateTimeImmutable('2026-07-16T10:27:40+02:00');
        $due = $schedule->nextDueAt($now, null);

        self::assertSame('2026-07-16T10:20:00+02:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('2026-07-16T10:30:00+02:00', $schedule->nextDueAt($now, $due)->format(DateTimeInterface::ATOM));
    }

    public function testDailyScheduleCatchesUpAndKeepsParisLocalTimeAcrossDst(): void
    {
        $schedule = AdminJobSchedule::daily(8);
        $now = new DateTimeImmutable('2026-10-25T09:00:00+01:00');
        $due = $schedule->nextDueAt($now, new DateTimeImmutable('2026-10-24T08:00:00+02:00'));

        self::assertSame('2026-10-25T08:00:00+01:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('2026-10-26T08:00:00+01:00', $schedule->nextDueAt($now, $due)->format(DateTimeInterface::ATOM));
    }

    public function testQuarterlyScheduleUsesJanuaryAprilJulyAndOctober(): void
    {
        $schedule = AdminJobSchedule::quarterly(4, 30);
        $now = new DateTimeImmutable('2026-07-16T12:00:00+02:00');
        $due = $schedule->nextDueAt($now, new DateTimeImmutable('2026-04-01T04:30:00+02:00'));

        self::assertSame('2026-07-01T04:30:00+02:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('2026-10-01T04:30:00+02:00', $schedule->nextDueAt($now, $due)->format(DateTimeInterface::ATOM));
    }

    public function testWeeklyScheduleFallsOnSundayFourAmAndSpansTheWeek(): void
    {
        // Promesse : la cadence hebdomadaire tombe le DIMANCHE 04:00 Europe/Paris et
        // enjambe la semaine entière — un samedi rattrape le dimanche précédent, puis saute au suivant.
        $schedule = AdminJobSchedule::weekly(4);
        $saturday = new DateTimeImmutable('2026-07-18T12:00:00+02:00');
        $due = $schedule->nextDueAt($saturday, null);

        self::assertSame('2026-07-12T04:00:00+02:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('Sunday', $due->format('l'));
        self::assertSame('2026-07-19T04:00:00+02:00', $schedule->nextDueAt($saturday, $due)->format(DateTimeInterface::ATOM));
    }

    public function testWeeklyScheduleBoundaryAroundSundayFourAm(): void
    {
        // Promesse : à 03:59 le créneau de 04:00 n'a pas encore eu lieu (c'est le dimanche
        // précédent) ; à 04:00 pile et à 04:01 c'est le créneau du dimanche même.
        $schedule = AdminJobSchedule::weekly(4);

        self::assertSame(
            '2026-07-12T04:00:00+02:00',
            $schedule->nextDueAt(new DateTimeImmutable('2026-07-19T03:59:00+02:00'), null)->format(DateTimeInterface::ATOM),
        );
        self::assertSame(
            '2026-07-19T04:00:00+02:00',
            $schedule->nextDueAt(new DateTimeImmutable('2026-07-19T04:00:00+02:00'), null)->format(DateTimeInterface::ATOM),
        );
        self::assertSame(
            '2026-07-19T04:00:00+02:00',
            $schedule->nextDueAt(new DateTimeImmutable('2026-07-19T04:01:00+02:00'), null)->format(DateTimeInterface::ATOM),
        );
    }

    public function testWeeklyScheduleKeepsParisLocalTimeAcrossDstFallBack(): void
    {
        // Promesse : 04:00 reste 04:00 à l'heure locale de Paris même le dimanche du passage
        // à l'heure d'hiver (dernier dimanche d'octobre) ; le saut d'une semaine ne dérive pas.
        $schedule = AdminJobSchedule::weekly(4);
        $afterFallBack = new DateTimeImmutable('2026-10-25T05:00:00+01:00');
        $due = $schedule->nextDueAt($afterFallBack, null);

        self::assertSame('Europe/Paris', $due->getTimezone()->getName());
        self::assertSame('2026-10-25T04:00:00+01:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('2026-11-01T04:00:00+01:00', $schedule->nextDueAt($afterFallBack, $due)->format(DateTimeInterface::ATOM));
    }

    public function testScheduleAlwaysUsesEuropeParis(): void
    {
        $due = AdminJobSchedule::daily(8)->nextDueAt(new DateTimeImmutable('2026-07-16T10:00:00+00:00'), null);

        self::assertSame('Europe/Paris', $due->getTimezone()->getName());
        self::assertSame('2026-07-16T08:00:00+02:00', $due->format(DateTimeInterface::ATOM));
    }

    public function testInvalidWallClockTimeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminJobSchedule::daily(24);
    }
}
