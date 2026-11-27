<?php

declare(strict_types=1);

namespace App\Tests\Unit\Matches;

use App\Entity\Competition;
use App\Enum\CompetitionType;
use App\Matches\CompetitionClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : la nature d'une compétition se lit d'une seule façon dans son libellé (lot 7, PR 4a) — premier mot
 * exact « amical » → amicale ; « coupe », ou un début « crml » / « cmrl » → coupe ; « brassage » → brassage ;
 * sinon la nature par défaut du chemin (championnat, coupe pour le canal API). Casse et accents ne comptent pas.
 * Et une compétition existante ne devient jamais amicale, ni ne cesse de l'être : seul un reclassement vers coupe
 * ou brassage d'une compétition non amicale est rendu.
 *
 * Logique à cas nommée par le plan (§5, ligne 14) : le jeu {@see self::NATURE_CASES} est PARTAGÉ avec
 * `Integration/Migration/CompetitionNatureMigrationTest`, qui prouve que la conversion écrit la même nature que
 * ce classifieur — classifieur et conversion disent la même chose.
 */
#[Group('unit')]
final class CompetitionClassifierTest extends TestCase
{
    /**
     * Libellé → nature attendue, défaut championnat. Lu aussi par la migration (stockée championnat avant elle).
     *
     * @var array<string, string>
     */
    public const array NATURE_CASES = [
        'Amical PNF' => 'FRIENDLY',
        'AMICAL RM3' => 'FRIENDLY',
        'amical' => 'FRIENDLY',
        'Amical - U15 F' => 'FRIENDLY',
        'Parc Amical' => 'CHAMPIONSHIP',
        'Amicaux' => 'CHAMPIONSHIP',
        'Coupe du Rhône' => 'CUP',
        'COUPE DE FRANCE U17' => 'CUP',
        'CRML U15 M' => 'CUP',
        'CMRL SF' => 'CUP',
        'U15 CRML' => 'CHAMPIONSHIP',
        'Brassage U13 F' => 'BRASSAGE',
        'Coupe brassage U11' => 'CUP',
        'PNM' => 'CHAMPIONSHIP',
        'DF2' => 'CHAMPIONSHIP',
        'Pré-régionale masculine' => 'CHAMPIONSHIP',
    ];

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function natureCases(): iterable
    {
        foreach (self::NATURE_CASES as $label => $nature) {
            yield $label => [$label, $nature];
        }
    }

    #[DataProvider('natureCases')]
    public function testTheLabelSaysTheNature(string $label, string $expected): void
    {
        self::assertSame(CompetitionType::from($expected), CompetitionClassifier::classify($label));
    }

    /** Le canal API garde son défaut « coupe » ; l'amical et le brassage s'y reconnaissent pareil. */
    #[TestWith(['PNM', 'CUP'])]
    #[TestWith(['Amical PNM', 'FRIENDLY'])]
    #[TestWith(['Brassage U13 F', 'BRASSAGE'])]
    #[TestWith(['Parc Amical', 'CUP'])]
    public function testTheDefaultOnlyAppliesWhenTheLabelSaysNothing(string $label, string $expected): void
    {
        self::assertSame(CompetitionType::from($expected), CompetitionClassifier::classify($label, CompetitionType::CUP));
    }

    /**
     * Reclassement d'une compétition EXISTANTE (appariement, import) : vers coupe ou brassage seulement, et jamais
     * vers ni depuis « amical » (une compétition ne devient jamais amicale, une amicale ne change jamais de nature).
     */
    #[TestWith(['CHAMPIONSHIP', 'Coupe du Rhône', 'CUP'])]
    #[TestWith(['CHAMPIONSHIP', 'CRML U15 M', 'CUP'])]
    #[TestWith(['CHAMPIONSHIP', 'Brassage U13 F', 'BRASSAGE'])]
    #[TestWith(['CUP', 'Brassage U13 F', 'BRASSAGE'])]
    #[TestWith(['CHAMPIONSHIP', 'Amical PNM', null])]
    #[TestWith(['CUP', 'AMICAL RM3', null])]
    #[TestWith(['CHAMPIONSHIP', 'PNM', null])]
    #[TestWith(['FRIENDLY', 'Coupe du Rhône', null])]
    #[TestWith(['FRIENDLY', 'Brassage U13 F', null])]
    #[TestWith(['FRIENDLY', 'PNM', null])]
    public function testAnExistingCompetitionIsOnlyReclassedTowardCupOrBrassage(string $stored, string $label, ?string $expected): void
    {
        $existing = new Competition;
        $existing->setName('Nom stocké');
        $existing->setCompetitionType(CompetitionType::from($stored));

        self::assertSame(null === $expected ? null : CompetitionType::from($expected), CompetitionClassifier::reclassOf($existing, $label));
    }

    /** `isFriendly()` est la seule question « amical ? » posée à la nature. */
    public function testOnlyTheFriendlyNatureIsFriendly(): void
    {
        self::assertSame(
            ['CHAMPIONSHIP' => false, 'CUP' => false, 'BRASSAGE' => false, 'FRIENDLY' => true],
            array_combine(
                array_map(static fn (CompetitionType $type): string => $type->value, CompetitionType::cases()),
                array_map(static fn (CompetitionType $type): bool => $type->isFriendly(), CompetitionType::cases()),
            ),
        );
    }
}
