<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PayloadCapacityMirror;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PR B (D17-2) — le RÉSUMÉ DE GRILLE joint au signalement d'un échec de génération : une fonction
 * PURE, à côté de {@see PayloadCapacityMirror}, calculée sur le SNAPSHOT figé du solve (pas la base).
 * Elle compte les créneaux, les réservations/épingles, les blocs, et la part VERROUILLÉE en minutes.
 *
 * TANK écrit ROUGE (option A) ; NEO ajoute la méthode. Contrat défini ici :
 * `PayloadCapacityMirror::gridSummary(array $payload): array{slots:int, reservations:int, pins:int,
 * blocks:int, lockedMinutes:int}`. Le ROUGE est une assertion PROPRE (`method_exists`), jamais un
 * fatal « undefined method » : les assertions de valeurs ne s'exécutent qu'une fois la méthode ajoutée.
 */
#[Group('unit')]
final class PayloadGridSummaryTest extends TestCase
{
    public function testGridSummaryCountsSlotsReservationsPinsBlocksAndLockedMinutes(): void
    {
        self::assertTrue(
            method_exists(PayloadCapacityMirror::class, 'gridSummary'),
            'D17 : le résumé de grille est une fonction pure `gridSummary` à côté de PayloadCapacityMirror',
        );

        // Snapshot figé minimal : 2 créneaux (90 + 60 min), 2 réservations dont 1 épinglée, 1 bloc.
        $payload = [
            'venues' => [
                ['id' => 'v1', 'slots' => [
                    ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
                    ['dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 60, 'capacity' => 1],
                ]],
            ],
            'reservations' => [
                ['teamId' => 't1', 'venueId' => 'v1', 'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'pinned' => true],
                ['teamId' => 't2', 'venueId' => 'v1', 'dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 60, 'pinned' => false],
            ],
            'sharedBlocks' => [
                ['id' => 'b1', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1],
            ],
        ];

        $summary = (new PayloadCapacityMirror)->gridSummary($payload);

        self::assertSame(2, $summary['slots'] ?? null, '2 créneaux dans le snapshot');
        self::assertSame(2, $summary['reservations'] ?? null, '2 réservations');
        self::assertSame(1, $summary['pins'] ?? null, '1 réservation épinglée');
        self::assertSame(1, $summary['blocks'] ?? null, '1 bloc mutualisé');
        self::assertSame(90, $summary['lockedMinutes'] ?? null, 'la part verrouillée = la durée de la réservation épinglée (90 min)');
    }
}
