<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\WeekSegmentationRule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CÔTÉ BACKEND de la parité mécanique du DÉCOUPAGE début·milieu·fin (règle fondateur 2026-09-05).
 *
 * `cockpit/lib/weekSegmentation.ts::weekSegments` (front, qualifie l'offre du picker) et
 * `WeekSegmentationRule::segments` (backend, garde le POST d'une semaine-enfant de fermeture et le
 * geste « d'un bloc ») sont deux implémentations INÉVITABLES du même algorithme (le cockpit doit
 * segmenter sans aller-retour réseau). Ici, les MÊMES cas (`weekSegmentation.parity.json`, dans
 * l'arbre front) traversent les DEUX implémentations : changer la règle d'un seul côté rougit
 * ce côté-là.
 *
 * Ce module figure au registre `FrontRederivationRegistryTest` (miroir déclaré + parité).
 */
#[Group('contract')]
final class WeekSegmentationMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/cockpit/lib/weekSegmentation.parity.json';

    public function testBackendSegmentsMatchTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            $label = (string) $case['label'];
            $actual = WeekSegmentationRule::segments($case['offered'], (string) $case['eventStart'], (string) $case['eventEnd']);

            self::assertSame(
                $case['expected'],
                $actual,
                \sprintf(
                    "PARITÉ ROMPUE (« %s ») : le backend ne rend pas le découpage partagé.\n"
                    . "Front `weekSegments` et backend `WeekSegmentationRule::segments` doivent coïncider\n"
                    . 'sur weekSegmentation.parity.json — sinon le cockpit propose un segment que le POST refuse (ou l\'inverse).',
                    $label,
                ),
            );
        }
    }

    /**
     * @return list<array{
     *     label: string,
     *     eventStart: string,
     *     eventEnd: string,
     *     offered: list<array{monday: string, startDate: string, endDate: string}>,
     *     expected: list<array{monday: string, startDate: string, endDate: string, kind: string, weeks: list<string>}>
     * }>
     */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{label: string, eventStart: string, eventEnd: string, offered: list<array{monday: string, startDate: string, endDate: string}>, expected: list<array{monday: string, startDate: string, endDate: string, kind: string, weeks: list<string>}>}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'weekSegmentation.parity.json ne porte plus aucun cas.');

        return $list;
    }
}
