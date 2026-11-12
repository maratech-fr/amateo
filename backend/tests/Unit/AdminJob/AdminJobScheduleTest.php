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
        // Promesse : un job qui a DÉJÀ tourné (10:00) et a manqué 10:10 et 10:20 ne rattrape
        // que le plus récent (10:20), une seule fois, puis passe au suivant.
        $schedule = AdminJobSchedule::everyTenMinutes();
        $now = new DateTimeImmutable('2026-07-16T10:27:40+02:00');
        $due = $schedule->nextDueAt($now, new DateTimeImmutable('2026-07-16T10:00:00+02:00'));

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
        // enjambe la semaine entière — un samedi rattrape le dimanche précédent (job déjà passé
        // le dimanche d'avant), puis saute au suivant.
        $schedule = AdminJobSchedule::weekly(4);
        $saturday = new DateTimeImmutable('2026-07-18T12:00:00+02:00');
        $due = $schedule->nextDueAt($saturday, new DateTimeImmutable('2026-07-05T04:00:00+02:00'));

        self::assertSame('2026-07-12T04:00:00+02:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('Sunday', $due->format('l'));
        self::assertSame('2026-07-19T04:00:00+02:00', $schedule->nextDueAt($saturday, $due)->format(DateTimeInterface::ATOM));
    }

    public function testWeeklyScheduleBoundaryAroundSundayFourAm(): void
    {
        // Promesse : à 03:59 le créneau de 04:00 n'a pas encore eu lieu (c'est le dimanche
        // précédent) ; à 04:00 pile et à 04:01 c'est le créneau du dimanche même. Job déjà passé
        // le 05/07, pour lire la borne sans la règle « jamais lancé ».
        $schedule = AdminJobSchedule::weekly(4);
        $lastRun = new DateTimeImmutable('2026-07-05T04:00:00+02:00');

        self::assertSame(
            '2026-07-12T04:00:00+02:00',
            $schedule->nextDueAt(new DateTimeImmutable('2026-07-19T03:59:00+02:00'), $lastRun)->format(DateTimeInterface::ATOM),
        );
        self::assertSame(
            '2026-07-19T04:00:00+02:00',
            $schedule->nextDueAt(new DateTimeImmutable('2026-07-19T04:00:00+02:00'), $lastRun)->format(DateTimeInterface::ATOM),
        );
        self::assertSame(
            '2026-07-19T04:00:00+02:00',
            $schedule->nextDueAt(new DateTimeImmutable('2026-07-19T04:01:00+02:00'), $lastRun)->format(DateTimeInterface::ATOM),
        );
    }

    public function testWeeklyScheduleKeepsParisLocalTimeAcrossDstFallBack(): void
    {
        // Promesse : 04:00 reste 04:00 à l'heure locale de Paris même le dimanche du passage
        // à l'heure d'hiver (dernier dimanche d'octobre) ; le saut d'une semaine ne dérive pas.
        $schedule = AdminJobSchedule::weekly(4);
        $afterFallBack = new DateTimeImmutable('2026-10-25T05:00:00+01:00');
        $due = $schedule->nextDueAt($afterFallBack, new DateTimeImmutable('2026-10-18T04:00:00+02:00'));

        self::assertSame('Europe/Paris', $due->getTimezone()->getName());
        self::assertSame('2026-10-25T04:00:00+01:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('2026-11-01T04:00:00+01:00', $schedule->nextDueAt($afterFallBack, $due)->format(DateTimeInterface::ATOM));
    }

    public function testScheduleAlwaysUsesEuropeParis(): void
    {
        // Promesse : un « maintenant » en UTC est lu en heure de Paris (10:00Z = 12:00 Paris) ;
        // job jamais lancé, 08:00 est passé de 4 h → il attend le 08:00 Paris du lendemain.
        $due = AdminJobSchedule::daily(8)->nextDueAt(new DateTimeImmutable('2026-07-16T10:00:00+00:00'), null);

        self::assertSame('Europe/Paris', $due->getTimezone()->getName());
        self::assertSame('2026-07-17T08:00:00+02:00', $due->format(DateTimeInterface::ATOM));
    }

    public function testDailyJobNeverRunWaitsForItsNextSlotInsteadOfFiringAtTheFirstTick(): void
    {
        // Promesse : un job quotidien 00:15 JAMAIS lancé, découvert le 12/11 à 10:00 Paris (déploiement),
        // ne part pas au premier tick : son échéance est le 13/11 00:15, pas le 12/11 00:15.
        $due = AdminJobSchedule::daily(0, 15)->nextDueAt(new DateTimeImmutable('2026-11-12T10:00:00+01:00'), null);

        self::assertSame('2026-11-13T00:15:00+01:00', $due->format(DateTimeInterface::ATOM));
    }

    public function testNeverRunJobFiresOnItsOwnSlotDespiteTheTickLag(): void
    {
        // Promesse : « attendre son prochain créneau » ne doit jamais devenir « ne jamais partir ».
        // Le cron-runner fait `sleep 60` APRÈS chaque passage (docker-compose.prod.yml) : il
        // n'arrive jamais pile sur hh:mm:00, et un long job (sauvegarde) le retarde de plusieurs
        // minutes. Un job jamais lancé, atteint par le tick qui suit son créneau, part sur CE
        // créneau (c'est le sien, pas un rattrapage) — pile, 45 s après, ou 20 min après un long job.
        $schedule = AdminJobSchedule::daily(0, 15);

        self::assertSame('2026-11-12T00:15:00+01:00', $schedule->nextDueAt(new DateTimeImmutable('2026-11-12T00:15:00+01:00'), null)->format(DateTimeInterface::ATOM));
        self::assertSame('2026-11-12T00:15:00+01:00', $schedule->nextDueAt(new DateTimeImmutable('2026-11-12T00:15:45+01:00'), null)->format(DateTimeInterface::ATOM));
        self::assertSame('2026-11-12T00:15:00+01:00', $schedule->nextDueAt(new DateTimeImmutable('2026-11-12T00:35:00+01:00'), null)->format(DateTimeInterface::ATOM));
        self::assertSame('2026-07-16T10:20:00+02:00', AdminJobSchedule::everyTenMinutes()->nextDueAt(new DateTimeImmutable('2026-07-16T10:20:30+02:00'), null)->format(DateTimeInterface::ATOM));
    }

    public function testDailyJobThatAlreadyRanCatchesUpTheMissedSlotOnceThenMovesForward(): void
    {
        // Promesse : le rattrapage reste pour un job qui a DÉJÀ tourné — passé le 10/11 00:15, il a
        // manqué le 11/11 et le 12/11 ; à 10:00 le 12/11, il rattrape le 12/11 00:15 une seule fois,
        // puis attend le 13/11 00:15.
        $schedule = AdminJobSchedule::daily(0, 15);
        $now = new DateTimeImmutable('2026-11-12T10:00:00+01:00');
        $due = $schedule->nextDueAt($now, new DateTimeImmutable('2026-11-10T00:15:00+01:00'));

        self::assertSame('2026-11-12T00:15:00+01:00', $due->format(DateTimeInterface::ATOM));
        self::assertSame('2026-11-13T00:15:00+01:00', $schedule->nextDueAt($now, $due)->format(DateTimeInterface::ATOM));
    }

    public function testNeverRunJobWaitsForItsNextSlotOnWeeklyQuarterlyAndAcrossDst(): void
    {
        // Promesse : même règle « jamais lancé = attend son prochain créneau » pour les autres
        // cadences, heure de Paris conservée au passage à l'heure d'hiver.
        // Samedi 18/07 midi : le dimanche 04:00 suivant (19/07), pas le 12/07 déjà passé.
        self::assertSame(
            '2026-07-19T04:00:00+02:00',
            AdminJobSchedule::weekly(4)->nextDueAt(new DateTimeImmutable('2026-07-18T12:00:00+02:00'), null)->format(DateTimeInterface::ATOM),
        );
        // 16/07 : le 1ᵉʳ octobre 04:30, pas le 1ᵉʳ juillet déjà passé.
        self::assertSame(
            '2026-10-01T04:30:00+02:00',
            AdminJobSchedule::quarterly(4, 30)->nextDueAt(new DateTimeImmutable('2026-07-16T12:00:00+02:00'), null)->format(DateTimeInterface::ATOM),
        );
        // Samedi 24/10 09:00 (heure d'été) : le 25/10 08:00 à l'heure d'HIVER (+01:00).
        self::assertSame(
            '2026-10-25T08:00:00+01:00',
            AdminJobSchedule::daily(8)->nextDueAt(new DateTimeImmutable('2026-10-24T09:00:00+02:00'), null)->format(DateTimeInterface::ATOM),
        );
    }

    public function testInvalidWallClockTimeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminJobSchedule::daily(24);
    }
}
