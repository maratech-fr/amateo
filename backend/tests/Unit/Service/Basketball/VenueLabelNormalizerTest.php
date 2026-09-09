<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Basketball;

use App\Service\Basketball\VenueLabelNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-187a D1 — le foyer unique de normalisation des libellés de salle. Casse,
 * accents, tirets et espaces multiples repliés vers une clé stable ; deux salles
 * réellement différentes gardent des clés distinctes.
 */
#[Group('phase1')]
final class VenueLabelNormalizerTest extends TestCase
{
    private VenueLabelNormalizer $normalizer;

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function labels(): array
    {
        return [
            ['GYMNASE MATEO', 'gymnase mateo'],
            ['Gymnase Matéo', 'gymnase mateo'],
            ['MATEO', 'mateo'],
            ['GYMNASE JEANNE DESPARMET-RUELLO', 'gymnase jeanne desparmet ruello'],
            ['  Salle   des   Sports  ', 'salle des sports'],
            ['Halle Carpentier (n°2)', 'halle carpentier n 2'],
        ];
    }

    #[DataProvider('labels')]
    public function testNormalizeFoldsCaseAccentsPunctuationAndSpacing(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($raw));
    }

    public function testTwoTrulyDifferentLabelsKeepDistinctKeys(): void
    {
        self::assertNotSame($this->normalizer->normalize('GYMNASE MATEO'), $this->normalizer->normalize('MATEO'));
    }

    public function testContainsWordIsWholeWordNotSubstring(): void
    {
        self::assertTrue($this->normalizer->containsWord('GYMNASE MATEO 1', 'gymnase mateo'));
        self::assertFalse($this->normalizer->containsWord('GYMNASE MATEOVILLE', 'mateo'));
    }

    public function testFuzzyMatchesEqualityAndContainmentEitherWay(): void
    {
        self::assertTrue($this->normalizer->fuzzyMatches('Coubertin', 'GYMNASE PIERRE DE COUBERTIN'));
        self::assertTrue($this->normalizer->fuzzyMatches('GYMNASE PIERRE DE COUBERTIN', 'coubertin'));
        self::assertFalse($this->normalizer->fuzzyMatches('Mateo', 'Coubertin'));
    }

    public function testAnEmptySideNeverDiverges(): void
    {
        self::assertTrue($this->normalizer->fuzzyMatches('', 'Coubertin'));
        self::assertTrue($this->normalizer->fuzzyMatches('Coubertin', '  '));
    }

    protected function setUp(): void
    {
        $this->normalizer = new VenueLabelNormalizer;
    }
}
