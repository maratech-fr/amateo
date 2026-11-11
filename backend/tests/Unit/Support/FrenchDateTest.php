<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\FrenchDate;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : les dates lues par un humain (PDF des parents, e-mail d'invitation, messages 422 d'accès
 * match) s'écrivent en français courant, sans dépendre de ext-intl (« 1er novembre 2026 »,
 * « sam. 14 nov. », « samedi 1er novembre », et l'année une seule fois sur une même année). Seule
 * maison des noms de mois : `FrenchMonthNamesLiveInFrenchDateTest`.
 */
final class FrenchDateTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function ranges(): array
    {
        return [
            'même année' => ['2026-11-01', '2026-12-31', 'du 1er novembre au 31 décembre 2026'],
            'deux années' => ['2026-12-15', '2027-01-15', 'du 15 décembre 2026 au 15 janvier 2027'],
            'même mois' => ['2026-11-09', '2026-11-15', 'du 9 au 15 novembre 2026'],
            'un seul jour' => ['2026-11-14', '2026-11-14', 'du 14 novembre 2026'],
            'le premier seul' => ['2026-11-01', '2026-11-01', 'du 1er novembre 2026'],
        ];
    }

    public function testLongWritesTheFirstOfTheMonthAsPremier(): void
    {
        self::assertSame('1er novembre 2026', FrenchDate::long(new DateTimeImmutable('2026-11-01')));
    }

    public function testLongWritesAnyOtherDayAsANumber(): void
    {
        self::assertSame('9 novembre 2026', FrenchDate::long(new DateTimeImmutable('2026-11-09')));
    }

    /** Le 14/11/2026 est un samedi, le 15 un dimanche. */
    public function testShortDayCarriesTheAbbreviatedWeekdayAndMonth(): void
    {
        self::assertSame('sam. 14 nov.', FrenchDate::shortDay(new DateTimeImmutable('2026-11-14')));
        self::assertSame('dim. 15 nov.', FrenchDate::shortDay(new DateTimeImmutable('2026-11-15')));
    }

    #[DataProvider('ranges')]
    public function testRangePrintsTheYearOnBothBoundsOnlyWhenItChanges(string $from, string $to, string $expected): void
    {
        self::assertSame($expected, FrenchDate::range(new DateTimeImmutable($from), new DateTimeImmutable($to)));
    }

    /** Les messages 422 d'accès match : jour de la semaine en toutes lettres, sans année. Le 01/11/2025 est un samedi. */
    public function testWeekdayWritesTheDayNameAndTheFirstAsPremier(): void
    {
        self::assertSame('samedi 1er novembre', FrenchDate::weekday(new DateTimeImmutable('2025-11-01')));
        self::assertSame('samedi 7 novembre', FrenchDate::weekday(new DateTimeImmutable('2026-11-07')));
        self::assertSame('mercredi 18 novembre', FrenchDate::weekday(new DateTimeImmutable('2026-11-18')));
    }

    /** L'en-tête de jour du PDF (que le PDF capitalise) : jour, date et année. Le 01/11/2026 est un dimanche. */
    public function testLongDayWritesTheDayNameTheDateAndTheYear(): void
    {
        self::assertSame('samedi 14 novembre 2026', FrenchDate::longDay(new DateTimeImmutable('2026-11-14')));
        self::assertSame('dimanche 1er novembre 2026', FrenchDate::longDay(new DateTimeImmutable('2026-11-01')));
        self::assertSame('lundi 16 novembre 2026', FrenchDate::longDay(new DateTimeImmutable('2026-11-16')));
    }
}
