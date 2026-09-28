<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LeagueResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class LeagueResolverTest extends TestCase
{
    public function testDerivesLeagueFromTheThreeLetterPrefix(): void
    {
        $resolver = new LeagueResolver;
        self::assertSame('AURA', $resolver->resolveFromFfbbCode('ARA0069123'));
        self::assertSame('GEST', $resolver->resolveFromFfbbCode('GES0067060'));
        self::assertSame('PACA', $resolver->resolveFromFfbbCode('pca0013001'));
    }

    public function testReadableButUncataloguedPrefixReturnsItself(): void
    {
        // Founder ruling 2026-09-29: any 3-letter prefix names a league (outre-mer
        // included) — an uncatalogued one returns the FFBB prefix itself, never null.
        $resolver = new LeagueResolver;
        self::assertSame('GUY', $resolver->resolveFromFfbbCode('GUY0973017')); // Guyane
        self::assertSame('ZZZ', $resolver->resolveFromFfbbCode('ZZZ0069123'));
    }

    public function testUnreadableCodeReturnsNull(): void
    {
        // No 3-letter prefix (a test/garbage code) → null → no league identity.
        $resolver = new LeagueResolver;
        self::assertNull($resolver->resolveFromFfbbCode('A11Y0069123'));
        self::assertNull($resolver->resolveFromFfbbCode('12'));
        self::assertNull($resolver->resolveFromFfbbCode(''));
        self::assertNull($resolver->resolveFromFfbbCode(null));
    }
}
