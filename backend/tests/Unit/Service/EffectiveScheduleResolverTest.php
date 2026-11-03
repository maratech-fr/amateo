<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\CalendarEntryPeriodType;
use App\Service\EffectiveScheduleResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * THE rule « which training calendar applies on a given date » (ADR-0002,
 * P4-188). Several active periods may cover the same date (a root closure split
 * into début·milieu·fin, a root and its child sharing a start): the NARROWEST
 * one wins, deterministically, whatever the list order — because period ids are
 * random UUIDv4, the SQL order can put a root before its child, and a « first
 * covering period wins » rule then silently loses the child's overlay.
 *
 * Fermetures de gymnase (C2, capture inversée) : une FERMETURE sans version ne capture plus la
 * date — le planning en vigueur s'applique et le résolveur rend AUSSI les gymnases fermés ce
 * jour-là (`excludedVenueIds`), moins les jours rouverts du plan pointé qui gouverne.
 */
#[Group('unit')]
final class EffectiveScheduleResolverTest extends TestCase
{
    private const SEASON = 'sched-season';

    /** 2026-09-03 est un JEUDI (ISO 4). */
    private const THURSDAY = '2026-09-03';

    private const MISTRAL = 'venue-mistral';

    private const BREL = 'venue-brel';

    public function testNarrowChildWinsOverBroaderRootInBothListOrders(): void
    {
        // Two POINTED periods cover the date (a holiday and its narrower « début » child):
        // the child is the most specific statement and must win, whatever the list order
        // (period ids are random UUIDv4, the SQL order may put the root first).
        $root = $this->period('2026-08-31', '2026-10-16', 'sched-root', CalendarEntryPeriodType::HOLIDAY);
        $child = $this->period('2026-08-31', '2026-09-06', 'sched-debut', CalendarEntryPeriodType::HOLIDAY);
        $date = new DateTimeImmutable(self::THURSDAY);

        $resolver = new EffectiveScheduleResolver;
        self::assertSame('sched-debut', $resolver->resolve($date, [$root, $child], self::SEASON, [])['scheduleId']);
        self::assertSame('sched-debut', $resolver->resolve($date, [$child, $root], self::SEASON, [])['scheduleId']);
    }

    /**
     * Fermetures de gymnase (C2, capture inversée) — une FERMETURE sans version ne capture plus la
     * date : le planning en vigueur (ici le socle) s'applique, et c'est le gymnase fermé qui sort
     * (`excludedVenueIds`) — jamais tout l'entraînement du club.
     */
    public function testAClosureWithoutAVersionDoesNotCaptureTheDateItExcludesItsVenue(): void
    {
        $root = $this->period('2026-08-31', '2026-10-16', null, CalendarEntryPeriodType::CLOSURE);

        $result = new EffectiveScheduleResolver()->resolve(new DateTimeImmutable(self::THURSDAY), [$root], self::SEASON, [self::MISTRAL => [self::THURSDAY => true]]);

        self::assertSame(self::SEASON, $result['scheduleId'], 'une fermeture sans version ne suspend pas tout l’entraînement');
        self::assertSame([self::MISTRAL], $result['excludedVenueIds'], 'seul le gymnase fermé ce jour-là sort');
    }

    /** Vacances (et coupures) sans version : inchangé — la période capture et suspend l'entraînement. */
    public function testAHolidayWithoutAVersionStillSuspendsTraining(): void
    {
        $holiday = $this->period('2026-08-31', '2026-10-16', null, CalendarEntryPeriodType::HOLIDAY);

        self::assertNull(new EffectiveScheduleResolver()->resolve(new DateTimeImmutable(self::THURSDAY), [$holiday], self::SEASON, [])['scheduleId']);
    }

    /**
     * Une coupe de fermeture étroite SANS version ne masque plus la période plus large pointée qui
     * gouverne la date (elle ne capture plus) ; une coupure de vacances étroite sans version, elle,
     * suspend toujours.
     */
    public function testANarrowClosureWithoutVersionNoLongerHidesTheWiderPointedPeriod(): void
    {
        $wide = $this->period('2026-08-31', '2026-10-16', 'sched-wide', CalendarEntryPeriodType::HOLIDAY);
        $narrowClosure = $this->period('2026-08-31', '2026-09-06', null, CalendarEntryPeriodType::CLOSURE);
        $narrowHoliday = $this->period('2026-08-31', '2026-09-06', null, CalendarEntryPeriodType::HOLIDAY);
        $date = new DateTimeImmutable(self::THURSDAY);

        $resolver = new EffectiveScheduleResolver;
        self::assertSame('sched-wide', $resolver->resolve($date, [$wide, $narrowClosure], self::SEASON, [])['scheduleId']);
        self::assertSame('sched-wide', $resolver->resolve($date, [$narrowClosure, $wide], self::SEASON, [])['scheduleId']);
        self::assertNull($resolver->resolve($date, [$wide, $narrowHoliday], self::SEASON, [])['scheduleId']);
    }

    public function testEqualWidthPeriodsKeepTheFirstInTheList(): void
    {
        // Two periods of identical width covering the date: the first encountered
        // wins (a stable, documented tie-break).
        $first = $this->period('2026-09-01', '2026-09-07', 'sched-first', CalendarEntryPeriodType::HOLIDAY);
        $second = $this->period('2026-09-01', '2026-09-07', 'sched-second', CalendarEntryPeriodType::HOLIDAY);

        self::assertSame('sched-first', new EffectiveScheduleResolver()->resolve(new DateTimeImmutable(self::THURSDAY), [$first, $second], self::SEASON, [])['scheduleId']);
    }

    public function testOutsideEveryPeriodTheSeasonCalendarApplies(): void
    {
        $period = $this->period('2026-09-01', '2026-09-07', 'sched-period', CalendarEntryPeriodType::HOLIDAY);

        self::assertSame(['scheduleId' => self::SEASON, 'excludedVenueIds' => []], new EffectiveScheduleResolver()->resolve(new DateTimeImmutable('2026-10-20'), [$period], self::SEASON, []));
        self::assertSame(['scheduleId' => self::SEASON, 'excludedVenueIds' => []], new EffectiveScheduleResolver()->resolve(new DateTimeImmutable('2026-10-20'), [], self::SEASON, []));
    }

    /** Fermeture par DATE : le gymnase n'est exclu que les jours fermés, pas les autres jours de la semaine. */
    public function testAVenueIsExcludedOnlyOnItsClosedDates(): void
    {
        $closed = [self::MISTRAL => [self::THURSDAY => true]];

        $resolver = new EffectiveScheduleResolver;
        self::assertSame([self::MISTRAL], $resolver->resolve(new DateTimeImmutable(self::THURSDAY), [], self::SEASON, $closed)['excludedVenueIds']);
        self::assertSame([], $resolver->resolve(new DateTimeImmutable('2026-09-04'), [], self::SEASON, $closed)['excludedVenueIds'], 'le lendemain, le gymnase sert');
    }

    /**
     * La réouverture fait foi : un jour ROUVERT (OPEN/BLANK) par le plan pointé qui gouverne la date
     * n'exclut pas son gymnase ; un autre gymnase fermé le même jour, non rouvert, reste exclu.
     */
    public function testADayReopenedByTheGoverningPlanIsNotExcluded(): void
    {
        $holiday = $this->period('2026-08-31', '2026-09-06', 'sched-h', CalendarEntryPeriodType::HOLIDAY, [self::MISTRAL => [4 => true]]);
        $closed = [self::MISTRAL => [self::THURSDAY => true], self::BREL => [self::THURSDAY => true]];

        $result = new EffectiveScheduleResolver()->resolve(new DateTimeImmutable(self::THURSDAY), [$holiday], self::SEASON, $closed);

        self::assertSame('sched-h', $result['scheduleId']);
        self::assertSame([self::BREL], $result['excludedVenueIds'], 'Mistral rouvert le jeudi sert ; Brel reste fermé');
    }

    /** Sous le socle (aucune période), la fermeture est un fait BRUT : aucune réouverture ne s'y applique. */
    public function testUnderTheSocleTheClosureIsARawFact(): void
    {
        $result = new EffectiveScheduleResolver()->resolve(new DateTimeImmutable(self::THURSDAY), [], self::SEASON, [self::MISTRAL => [self::THURSDAY => true]]);

        self::assertSame(['scheduleId' => self::SEASON, 'excludedVenueIds' => [self::MISTRAL]], $result);
    }

    /**
     * @param array<string, array<int, true>> $reopenedWeekdaysByVenue
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null, periodType: CalendarEntryPeriodType, reopenedWeekdaysByVenue: array<string, array<int, true>>}
     */
    private function period(string $start, string $end, ?string $scheduleId, CalendarEntryPeriodType $periodType, array $reopenedWeekdaysByVenue = []): array
    {
        return [
            'start' => new DateTimeImmutable($start),
            'end' => new DateTimeImmutable($end),
            'scheduleId' => $scheduleId,
            'periodType' => $periodType,
            'reopenedWeekdaysByVenue' => $reopenedWeekdaysByVenue,
        ];
    }
}
