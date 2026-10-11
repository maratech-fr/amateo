<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\VenueWindowOccupancy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CÔTÉ BACKEND de la parité mécanique de la règle « un créneau de grille est-il OCCUPÉ ? »
 * (une séance du même gymnase+jour le CHEVAUCHE dans le temps, `[début, fin)` demi-ouvert — pas
 * seulement un début exact).
 *
 * `planning/lib/emptySlots.ts::computeEmptySlots` (front, la grille à l'écran) et
 * `VenueWindowOccupancy::windowIsFilled` (backend, la case « vide » de l'export PDF/XLSX) sont
 * deux implémentations INÉVITABLES de la même règle (la grille doit la rendre sans aller-retour
 * réseau ; l'export la recalcule côté serveur). Ici, les MÊMES cas (`emptySlots.parity.json`,
 * dans l'arbre front) traversent les DEUX implémentations : changer la règle d'un seul côté rougit
 * ce côté-là.
 *
 * Ce module figure au registre `FrontRederivationRegistryTest` (miroir déclaré + parité).
 */
#[Group('contract')]
final class EmptySlotOverlapMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/planning/lib/emptySlots.parity.json';

    public function testBackendWindowIsFilledMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            self::assertSame(
                $case['expectedFilled'],
                VenueWindowOccupancy::windowIsFilled($case['window'], $case['placements']),
                \sprintf(
                    "PARITÉ ROMPUE (« %s ») : le backend ne rend pas le verdict partagé.\n"
                    . "Front `computeEmptySlots` et backend `VenueWindowOccupancy::windowIsFilled` doivent coïncider\n"
                    . 'sur emptySlots.parity.json — sinon l\'export montre une case « vide » que la grille ne montre pas (ou l\'inverse).',
                    $case['label'],
                ),
            );
        }
    }

    /**
     * @return list<array{
     *     label: string,
     *     window: array{venueId: string, dayOfWeek: int, startTime: string, durationMinutes: int},
     *     placements: list<array{venueId: string, dayOfWeek: int, startTime: string, durationMinutes: int}>,
     *     expectedFilled: bool
     * }>
     */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{label: string, window: array{venueId: string, dayOfWeek: int, startTime: string, durationMinutes: int}, placements: list<array{venueId: string, dayOfWeek: int, startTime: string, durationMinutes: int}>, expectedFilled: bool}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'emptySlots.parity.json ne porte plus aucun cas.');

        return $list;
    }
}
