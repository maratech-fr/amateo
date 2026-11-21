<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\WeeklySlot;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : un créneau de semaine (un jour de 1, lundi, à 7, dimanche, une heure de début, une durée)
 * se lit et se compare d'une seule façon dans tout le backend. Deux créneaux qui se touchent ne se
 * chevauchent pas (intervalles demi-ouverts), deux jours différents jamais ; un créneau qui passe
 * minuit est reconnu ; « 9:00 », « 09:00 » et « 09:00:00 » valent la même heure. Un jour hors de 1 à 7
 * n'existe pas : c'est la numérotation que le moteur lit (`rest_day = match_day % 7 + 1`).
 *
 * Les sites qui appellent cette classe (chevauchements, bornes de minuit, lectures d'heure) gardent
 * leurs propres tests et les miroirs `CoachDoubleBookingMirrorParityTest`,
 * `EmptySlotOverlapMirrorParityTest`, inchangés : ce test-ci prouve les cas de la règle, une fois.
 */
final class WeeklySlotTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function daysOutsideTheWeek(): array
    {
        return ['0 (l\'ancienne numérotation du lundi)' => [0], '8, après le dimanche' => [8], 'négatif' => [-1]];
    }

    /** @return array<string, array{string}> */
    public static function writingsOfNineOClock(): array
    {
        return ['un seul chiffre d\'heure' => ['9:00'], 'deux chiffres' => ['09:00'], 'avec les secondes' => ['09:00:00']];
    }

    public function testMondayIsOneAndSundayIsSeven(): void
    {
        self::assertSame(1, new WeeklySlot(1, 0, 1)->day, 'lundi = 1');
        self::assertSame(7, new WeeklySlot(7, 1439, 1)->day, 'dimanche = 7, dernière minute du jour comprise');
    }

    #[DataProvider('daysOutsideTheWeek')]
    public function testADayOutsideOneToSevenIsRefused(int $day): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WeeklySlot($day, 540, 60);
    }

    public function testAStartOutsideTheDayOrAnEmptyDurationIsRefused(): void
    {
        foreach ([[-1, 60], [1440, 60], [540, 0], [540, -30]] as [$start, $duration]) {
            try {
                new WeeklySlot(1, $start, $duration);
                self::fail(\sprintf('début %d, durée %d : doit être refusé', $start, $duration));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('writingsOfNineOClock')]
    public function testEveryWritingOfNineOClockIsMinute540(string $time): void
    {
        self::assertSame(540, WeeklySlot::minutesOf($time));
        self::assertSame(540, WeeklySlot::tryMinutesOf($time));
    }

    public function testATimeOfDayIsReadFromADateTimeToo(): void
    {
        self::assertSame(1110, WeeklySlot::minutesOf(new DateTimeImmutable('1970-01-01 18:30:00')));

        $slot = WeeklySlot::at(6, new DateTimeImmutable('1970-01-01 18:30:00'), 90);
        self::assertSame([6, 1110, 90, 1200], [$slot->day, $slot->startMinutes, $slot->durationMinutes, $slot->endMinutes()]);
    }

    public function testAnUnreadableTimeIsRefusedOrNull(): void
    {
        self::assertNull(WeeklySlot::tryMinutesOf('midi'));
        self::assertNull(WeeklySlot::tryMinutesOf(null));
        self::assertNull(WeeklySlot::tryMinutesOf(540), 'un entier n\'est pas une heure écrite (comme `CoachDoubleBookingDetector::timeToMinutes` aujourd\'hui)');

        $this->expectException(InvalidArgumentException::class);
        WeeklySlot::minutesOf('midi');
    }

    public function testClockWritesTwoDigitsForHoursAndMinutes(): void
    {
        self::assertSame('00:00', WeeklySlot::clock(0));
        self::assertSame('09:05', WeeklySlot::clock(545));
        self::assertSame('23:59', WeeklySlot::clock(1439));
    }

    public function testTwoSlotsThatTouchDoNotOverlap(): void
    {
        $first = WeeklySlot::at(2, '17:00', 90);
        $next = WeeklySlot::at(2, '18:30', 90);

        self::assertFalse($first->overlaps($next), 'fin 18:30 = début 18:30 : jointifs, pas de chevauchement');
        self::assertFalse($next->overlaps($first), 'symétrique');
        self::assertFalse(WeeklySlot::intervalsOverlap(1020, 1110, 1110, 1200));
    }

    public function testTwoSlotsThatShareAMinuteOverlap(): void
    {
        $first = WeeklySlot::at(2, '17:00', 90);

        self::assertTrue($first->overlaps(WeeklySlot::at(2, '18:29', 30)), 'une minute en commun suffit');
        self::assertTrue($first->overlaps(WeeklySlot::at(2, '17:30', 15)), 'un créneau contenu dans l\'autre');
        self::assertTrue(WeeklySlot::intervalsOverlap(1020, 1110, 1050, 1140));
        self::assertTrue(WeeklySlot::intervalsOverlap(1050, 1140, 1020, 1110), 'symétrique');
    }

    public function testTheSameHoursOnTwoDaysDoNotOverlap(): void
    {
        self::assertFalse(WeeklySlot::at(2, '17:00', 90)->overlaps(WeeklySlot::at(3, '17:00', 90)));
    }

    public function testASlotThatEndsAtMidnightStaysInItsDayAndOneThatPassesItDoesNot(): void
    {
        self::assertSame(1440, WeeklySlot::MINUTES_PER_DAY);
        self::assertTrue(WeeklySlot::at(5, '22:30', 90)->endsWithinDay(), 'fin à 24:00 pile : dans la journée');
        self::assertFalse(WeeklySlot::at(5, '23:00', 90)->endsWithinDay(), 'fin à 00:30 le lendemain : dépasse minuit');
    }
}
