<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\HolidayReminderLog;
use App\Entity\Season;
use App\Entity\TeamTagAssignment;
use App\Service\ErasedClubPurger;
use App\Service\SeasonDataPurger;
use App\Structure\FamilyCatalog;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lot conception 1, PR 4a — « le catalogue des familles rend ce que les purges tiennent à la main
 * aujourd'hui » : les familles des constantes de `SeasonDataPurger` (purge par le parent, par
 * club+saison, exclusions) et d'`ErasedClubPurger` (purge par club, exclusions), raisons comprises,
 * mot pour mot. C'est la condition pour que la PR 4b remplace les constantes par le catalogue sans
 * qu'aucune purge ne change. Test de passage : supprimé par la PR 4b, avec les constantes qu'il relit.
 *
 * Les trois familles que la purge de saison efface par un code à part (`TeamTagAssignment` et
 * `HolidayReminderLog` par la saison seule, la ligne `Season` sous condition) ne sont dans aucune
 * constante de liste : elles ne sont jamais déclarées épargnées, et les deux premières peuvent figurer
 * dans la purge de saison du catalogue.
 *
 * Angle jumeau, cité : `PurgeCompletenessTest` (toute entité tenant est couverte par ces constantes).
 */
final class FamilyCatalogMatchesPurgeListsTest extends KernelTestCase
{
    /** Effacées par la saison seule, hors des constantes de liste (`SeasonDataPurger::purge`). */
    private const array PURGED_APART_BY_SEASON = [HolidayReminderLog::class, TeamTagAssignment::class];

    public function testPurgedViaOwnerIsThePurgeByTheParent(): void
    {
        /** @var array<class-string, array{0: string, 1: class-string}> $expected */
        $expected = $this->constant(SeasonDataPurger::class, 'PURGED_VIA_PARENT');
        $actual = $this->catalog()->purgedViaOwner();
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    public function testPurgedWithSeasonIsThePurgeByClubAndSeason(): void
    {
        /** @var list<class-string> $expected */
        $expected = $this->constant(SeasonDataPurger::class, 'PURGED_BY_CLUB_SEASON');
        $actual = array_values(array_diff($this->catalog()->purgedWithSeason(), self::PURGED_APART_BY_SEASON));
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testPurgedWithClubIsThePurgeByClub(): void
    {
        /** @var list<class-string> $expected */
        $expected = $this->constant(ErasedClubPurger::class, 'PURGED_BY_CLUB');
        $actual = $this->catalog()->purgedWithClub();
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testFamiliesKeptAtSeasonPurgeCarryTheReasonsOfTheExclusionList(): void
    {
        $this->assertKeptReasons(
            $this->constant(SeasonDataPurger::class, 'EXCLUDED_FROM_SEASON_PURGE'),
            static fn (FamilyCatalog $catalog, string $class): ?string => $catalog->of($class)->keptAtSeasonPurge,
        );
    }

    public function testFamiliesKeptAtErasureCarryTheReasonsOfTheExclusionList(): void
    {
        $this->assertKeptReasons(
            $this->constant(ErasedClubPurger::class, 'EXCLUDED_FROM_ERASURE'),
            static fn (FamilyCatalog $catalog, string $class): ?string => $catalog->of($class)->keptAtErasure,
        );
    }

    public function testFamiliesPurgedApartAreNeverDeclaredKept(): void
    {
        $catalog = $this->catalog();
        foreach ([...self::PURGED_APART_BY_SEASON, Season::class] as $class) {
            self::assertNull($catalog->of($class)->keptAtSeasonPurge, $class . ' est effacée par la purge de saison.');
            self::assertNull($catalog->of($class)->keptAtErasure, $class . ' est effacée par l\'effacement du club.');
        }
    }

    /**
     * Chaque table de la liste d'exclusion est une famille qui porte la même raison, mot pour mot ;
     * aucune autre famille ne se dit épargnée.
     *
     * @param array<string, string>                          $excludedByTable table => raison
     * @param callable(FamilyCatalog, class-string): ?string $reasonOf
     */
    private function assertKeptReasons(array $excludedByTable, callable $reasonOf): void
    {
        $catalog = $this->catalog();
        $classOfTable = $this->classOfTable();

        $expected = [];
        foreach ($excludedByTable as $table => $reason) {
            self::assertArrayHasKey($table, $classOfTable, 'Table exclue sans entité : ' . $table);
            $expected[$classOfTable[$table]] = $reason;
        }

        $actual = [];
        foreach ($catalog->all() as $class) {
            $reason = $reasonOf($catalog, $class);
            if (null !== $reason) {
                $actual[$class] = $reason;
            }
        }
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    /** @return array<string, class-string> */
    private function classOfTable(): array
    {
        $map = [];
        foreach (self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata() as $metadata) {
            $map[$metadata->getTableName()] = $metadata->getName();
        }

        return $map;
    }

    /** @param class-string $class */
    private function constant(string $class, string $name): mixed
    {
        return new ReflectionClass($class)->getConstant($name);
    }

    private function catalog(): FamilyCatalog
    {
        self::bootKernel();

        return self::getContainer()->get(FamilyCatalog::class);
    }
}
