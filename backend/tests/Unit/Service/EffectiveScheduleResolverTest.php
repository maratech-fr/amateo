<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

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
 */
#[Group('unit')]
final class EffectiveScheduleResolverTest extends TestCase
{
    private const SEASON = 'sched-season';

    public function testNarrowChildWinsOverBroaderRootInBothListOrders(): void
    {
        // The founder's case: a root closure 31/08 → 16/10 (no plan, scheduleId
        // null) and its « début » child 31/08 → 06/09 pointing at a version.
        // On 03/09 the child is the most specific statement and must win, even
        // though it shares its start with the root and its id may sort after it.
        $root = $this->period(['start' => '2026-08-31', 'end' => '2026-10-16', 'scheduleId' => null]);
        $child = $this->period(['start' => '2026-08-31', 'end' => '2026-09-06', 'scheduleId' => 'sched-debut']);
        $date = new DateTimeImmutable('2026-09-03');

        $resolver = new EffectiveScheduleResolver;
        self::assertSame('sched-debut', $resolver->resolve($date, [$root, $child], self::SEASON));
        self::assertSame('sched-debut', $resolver->resolve($date, [$child, $root], self::SEASON));
    }

    public function testRootAloneWithoutAPlanResolvesToNullNotTheSeason(): void
    {
        // A period covering the date with NO chosen version SUSPENDS training —
        // it never falls back to the season calendar.
        $root = $this->period(['start' => '2026-08-31', 'end' => '2026-10-16', 'scheduleId' => null]);

        self::assertNull(new EffectiveScheduleResolver()->resolve(new DateTimeImmutable('2026-09-03'), [$root], self::SEASON));
    }

    public function testNarrowerNullChildWinsOverBroaderPointedPeriod(): void
    {
        // A narrow child that points at nothing (a cut) still wins over a wider
        // pointed period: the cut suspends training, no fallback to the broader
        // calendar.
        $wide = $this->period(['start' => '2026-08-31', 'end' => '2026-10-16', 'scheduleId' => 'sched-wide']);
        $narrowCut = $this->period(['start' => '2026-08-31', 'end' => '2026-09-06', 'scheduleId' => null]);
        $date = new DateTimeImmutable('2026-09-03');

        $resolver = new EffectiveScheduleResolver;
        self::assertNull($resolver->resolve($date, [$wide, $narrowCut], self::SEASON));
        self::assertNull($resolver->resolve($date, [$narrowCut, $wide], self::SEASON));
    }

    public function testEqualWidthPeriodsKeepTheFirstInTheList(): void
    {
        // Two periods of identical width covering the date: the first encountered
        // wins (a stable, documented tie-break).
        $first = $this->period(['start' => '2026-09-01', 'end' => '2026-09-07', 'scheduleId' => 'sched-first']);
        $second = $this->period(['start' => '2026-09-01', 'end' => '2026-09-07', 'scheduleId' => 'sched-second']);
        $date = new DateTimeImmutable('2026-09-03');

        self::assertSame('sched-first', new EffectiveScheduleResolver()->resolve($date, [$first, $second], self::SEASON));
    }

    public function testOutsideEveryPeriodTheSeasonCalendarApplies(): void
    {
        $period = $this->period(['start' => '2026-09-01', 'end' => '2026-09-07', 'scheduleId' => 'sched-period']);

        self::assertSame(self::SEASON, new EffectiveScheduleResolver()->resolve(new DateTimeImmutable('2026-10-20'), [$period], self::SEASON));
        self::assertSame(self::SEASON, new EffectiveScheduleResolver()->resolve(new DateTimeImmutable('2026-10-20'), [], self::SEASON));
    }

    /**
     * @param array{start: string, end: string, scheduleId: string|null} $period
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}
     */
    private function period(array $period): array
    {
        return [
            'start' => new DateTimeImmutable($period['start']),
            'end' => new DateTimeImmutable($period['end']),
            'scheduleId' => $period['scheduleId'],
        ];
    }
}
