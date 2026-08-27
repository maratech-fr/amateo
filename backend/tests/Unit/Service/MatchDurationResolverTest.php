<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SportCategory;
use App\Service\MatchDurationResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Family-default resolution for match durations (P2-54 RMM-9): the U-token in
 * the category name maps to a bucket, everything else falls to the documented
 * 105/30, and per-category overrides win column by column.
 */
#[Group('unit')]
final class MatchDurationResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function familyCases(): iterable
    {
        yield 'U7 → 75/30' => ['U7', 75, 30];
        yield 'U9 → 75/30' => ['U9', 75, 30];
        yield 'U11 → 75/30' => ['U11', 75, 30];
        yield 'U13 → 90/30' => ['U13', 90, 30];
        yield 'U15 → 90/30' => ['U15', 90, 30];
        yield 'U18 → 105/30' => ['U18', 105, 30];
        yield 'U21 → 105/30' => ['U21', 105, 30];
        yield 'Senior → fallback 105/30' => ['Senior', 105, 30];
        yield 'Vétéran → fallback 105/30' => ['Vétéran', 105, 30];
        yield 'Loisir → fallback 105/30' => ['Loisir', 105, 30];
        // Below/between the buckets = no youth family → documented fallback 105/30.
        yield 'U5 (below U7) → fallback 105/30' => ['U5', 105, 30];
        yield 'U12 (gap) → fallback 105/30' => ['U12', 105, 30];
        yield 'custom name without U-token → fallback 105/30' => ['Poussins', 105, 30];
    }

    #[DataProvider('familyCases')]
    public function testFamilyDefault(string $name, int $expectedMatch, int $expectedWarmup): void
    {
        $profile = new MatchDurationResolver()->familyDefault($this->category($name));

        self::assertSame($expectedMatch, $profile->matchMinutes);
        self::assertSame($expectedWarmup, $profile->warmupMinutes);
    }

    public function testResolveUsesOverridesWhenBothSet(): void
    {
        $category = $this->category('U13')->setMatchMinutes(70)->setWarmupMinutes(15);
        $profile = new MatchDurationResolver()->resolve($category);

        self::assertSame(70, $profile->matchMinutes);
        self::assertSame(15, $profile->warmupMinutes);
    }

    public function testResolveFillsAHalfSetRowFromTheFamily(): void
    {
        // Only the match minutes overridden: the warm-up still borrows the U13
        // family default (30), never a stray 0.
        $category = $this->category('U13')->setMatchMinutes(70);
        $profile = new MatchDurationResolver()->resolve($category);

        self::assertSame(70, $profile->matchMinutes);
        self::assertSame(30, $profile->warmupMinutes);
    }

    public function testResolveFallsBackToTheFamilyWhenNothingOverridden(): void
    {
        $profile = new MatchDurationResolver()->resolve($this->category('U9'));

        self::assertSame(75, $profile->matchMinutes);
        self::assertSame(30, $profile->warmupMinutes);
    }

    private function category(string $name): SportCategory
    {
        return new SportCategory()->setName($name);
    }
}
