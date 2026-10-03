<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Seed\LoadTestClubSize;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Les trois tailles de club de charge et leur cycle sur l'ordinal (small → medium
 * → large), pour que le tir mélange des solve-sizes réalistes.
 */
#[Group('phase1')]
final class LoadTestClubSizeTest extends TestCase
{
    public function testEachSizeHasTeamsAndVenuesGrowing(): void
    {
        self::assertSame(10, LoadTestClubSize::SMALL->teamCount());
        self::assertSame(2, LoadTestClubSize::SMALL->venueCount());
        self::assertSame(21, LoadTestClubSize::MEDIUM->teamCount());
        self::assertSame(5, LoadTestClubSize::MEDIUM->venueCount());
        self::assertSame(49, LoadTestClubSize::LARGE->teamCount());
        self::assertSame(9, LoadTestClubSize::LARGE->venueCount());

        // Strictement croissant : une taille = une charge distincte.
        self::assertGreaterThan(LoadTestClubSize::SMALL->teamCount(), LoadTestClubSize::MEDIUM->teamCount());
        self::assertGreaterThan(LoadTestClubSize::MEDIUM->teamCount(), LoadTestClubSize::LARGE->teamCount());
        self::assertGreaterThan(LoadTestClubSize::SMALL->venueCount(), LoadTestClubSize::MEDIUM->venueCount());
        self::assertGreaterThan(LoadTestClubSize::MEDIUM->venueCount(), LoadTestClubSize::LARGE->venueCount());
    }

    public function testForIndexCyclesThroughTheThreeSizes(): void
    {
        self::assertSame(LoadTestClubSize::SMALL, LoadTestClubSize::forIndex(1));
        self::assertSame(LoadTestClubSize::MEDIUM, LoadTestClubSize::forIndex(2));
        self::assertSame(LoadTestClubSize::LARGE, LoadTestClubSize::forIndex(3));
        self::assertSame(LoadTestClubSize::SMALL, LoadTestClubSize::forIndex(4));
        self::assertSame(LoadTestClubSize::MEDIUM, LoadTestClubSize::forIndex(5));
        self::assertSame(LoadTestClubSize::LARGE, LoadTestClubSize::forIndex(6));

        // Les trois tailles apparaissent dans une rafale de 6 clubs.
        $sizes = [];
        for ($i = 1; $i <= 6; ++$i) {
            $sizes[LoadTestClubSize::forIndex($i)->value] = true;
        }
        self::assertCount(3, $sizes);
    }
}
