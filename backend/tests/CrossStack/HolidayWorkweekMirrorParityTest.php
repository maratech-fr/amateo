<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\HolidayWorkweekRule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CÔTÉ BACKEND de la parité mécanique de la règle « une semaine est-elle de vacances ? »
 * (lundi→vendredi couvert).
 *
 * `cockpit/lib/holidayWorkweek.ts::holidayCoversWorkweek` (front, qualifie l'OFFRE de reprise
 * et l'exclusion de l'offre fermeture) et `HolidayWorkweekRule::covers` (backend, garde le POST
 * d'une semaine-enfant de vacances) sont deux implémentations INÉVITABLES de la même règle (le
 * cockpit doit qualifier une semaine sans aller-retour réseau). Ici, les MÊMES cas
 * (`holidayWorkweek.parity.json`, dans l'arbre front) traversent les DEUX implémentations :
 * changer la règle d'un seul côté rougit ce côté-là.
 *
 * Ce module figure au registre `FrontRederivationRegistryTest` (miroir déclaré + parité).
 */
#[Group('contract')]
final class HolidayWorkweekMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/cockpit/lib/holidayWorkweek.parity.json';

    public function testBackendCoversMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            $expected = (bool) $case['expected'];
            $label = (string) $case['label'];

            self::assertSame(
                $expected,
                HolidayWorkweekRule::covers(
                    (string) $case['monday'],
                    (string) $case['holidayStart'],
                    (string) $case['holidayEnd'],
                    (string) $case['seasonStart'],
                    (string) $case['seasonEnd'],
                ),
                \sprintf(
                    "PARITÉ ROMPUE (« %s ») : le backend ne rend pas le verdict partagé.\n"
                    . "Front `holidayCoversWorkweek` et backend `HolidayWorkweekRule::covers` doivent coïncider\n"
                    . 'sur holidayWorkweek.parity.json — sinon le cockpit offre une semaine que le POST refuse (ou l\'inverse).',
                    $label,
                ),
            );
        }
    }

    /** @return list<array{label: string, monday: string, holidayStart: string, holidayEnd: string, seasonStart: string, seasonEnd: string, expected: bool}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{label: string, monday: string, holidayStart: string, holidayEnd: string, seasonStart: string, seasonEnd: string, expected: bool}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'holidayWorkweek.parity.json ne porte plus aucun cas.');

        return $list;
    }
}
