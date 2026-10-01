<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Basketball;

use App\Enum\TeamLevel;
use App\Service\Basketball\EngagementLevelDeducer;
use App\Service\Basketball\FbiDivisionSignature;
use App\Service\Basketball\VenueLabelNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * « Le niveau d'une équipe JEUNE suit son engagement FFBB » (décision fondateur
 * 2026-10-01). Ce service déduit le {@see TeamLevel} qu'implique une LIGNE
 * d'engagement FFBB d'une catégorie jeune (U9–U18) en championnat ou brassage, et
 * ARBITRE le niveau dominant d'une équipe quand ses engagements jeunes divergent
 * (la compétition au match le plus TARDIF l'emporte — D4). Pur, sans base ni
 * réseau : il consomme la signature de {@see FbiDivisionSignature::fromFfbbRow}.
 *
 * Relève de l'axe §7.1 « périmètre engagé » (il décide d'un niveau d'équipe).
 */
#[Group('phase1')]
final class EngagementLevelDeducerTest extends TestCase
{
    private EngagementLevelDeducer $deducer;

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string, 3: TeamLevel|null}>
     */
    public static function rows(): array
    {
        return [
            // Mapping D/R/N sur une catégorie jeune en championnat.
            'U13 départemental → DEPARTEMENTAL' => ['U13', 'Départemental', 'Championnat U13', TeamLevel::DEPARTEMENTAL],
            'U15 régional → REGIONAL' => ['U15', 'Régional', 'Championnat U15', TeamLevel::REGIONAL],
            'U18 national → NATIONAL' => ['U18', 'National', 'Championnat U18', TeamLevel::NATIONAL],
            'U9 et U18 (bornes) éligibles' => ['U9', 'Départemental', 'Plateau U9', TeamLevel::DEPARTEMENTAL],
            // Un brassage jeune est éligible (type ∈ {CHAMPIONSHIP, BRASSAGE}).
            'U13 brassage régional → REGIONAL' => ['U13', 'Régional', 'Régionale féminine U13 - Brassage', TeamLevel::REGIONAL],
            // Pré-régional / pré-national : aucun cas TeamLevel → null (jamais comblé).
            'U15 pré régional → null' => ['U15', 'Pré régional', 'Championnat U15', null],
            'U15 pré national → null' => ['U15', 'Pré national', 'Championnat U15', null],
            // Hors périmètre jeune : U21, seniors, et une coupe jeune.
            'U21 exclu' => ['U21', 'Régional', 'Championnat U21', null],
            'seniors exclu' => ['Seniors', 'Départemental', 'Championnat seniors', null],
            'coupe jeune exclue (type CUP)' => ['U18', 'Départemental', 'Coupe du Rhône U18', null],
            // Jamais ELITE (hors mapping D/R/N).
            'niveau non mappé → null' => ['U13', null, 'Championnat U13', null],
        ];
    }

    #[DataProvider('rows')]
    public function testDeduce(?string $category, ?string $level, string $name, ?TeamLevel $expected): void
    {
        self::assertSame($expected, $this->deducer->deduce($category, $level, 'Masculin', $name));
    }

    public function testArbitrateSingleLevelNeedsNoDate(): void
    {
        self::assertSame(
            TeamLevel::DEPARTEMENTAL,
            $this->deducer->arbitrate([
                ['level' => TeamLevel::DEPARTEMENTAL, 'latestMatchDate' => null],
                ['level' => TeamLevel::DEPARTEMENTAL, 'latestMatchDate' => '2026-11-01 20:00:00'],
            ]),
        );
    }

    public function testArbitrateDivergenceLatestMatchWins(): void
    {
        self::assertSame(
            TeamLevel::REGIONAL,
            $this->deducer->arbitrate([
                ['level' => TeamLevel::DEPARTEMENTAL, 'latestMatchDate' => '2026-11-01 20:00:00'],
                ['level' => TeamLevel::REGIONAL, 'latestMatchDate' => '2026-12-15 18:30:00'],
            ]),
        );
    }

    public function testArbitrateDivergenceWithoutAnyDateIsUndecidable(): void
    {
        self::assertNull($this->deducer->arbitrate([
            ['level' => TeamLevel::DEPARTEMENTAL, 'latestMatchDate' => null],
            ['level' => TeamLevel::REGIONAL, 'latestMatchDate' => null],
        ]));
    }

    public function testArbitrateTieOnLatestDateAcrossLevelsIsUndecidable(): void
    {
        self::assertNull($this->deducer->arbitrate([
            ['level' => TeamLevel::DEPARTEMENTAL, 'latestMatchDate' => '2026-12-15 18:30:00'],
            ['level' => TeamLevel::REGIONAL, 'latestMatchDate' => '2026-12-15 18:30:00'],
        ]));
    }

    public function testArbitrateEmptyIsNull(): void
    {
        self::assertNull($this->deducer->arbitrate([]));
    }

    protected function setUp(): void
    {
        $this->deducer = new EngagementLevelDeducer(new FbiDivisionSignature(new VenueLabelNormalizer));
    }
}
