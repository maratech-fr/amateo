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

    /**
     * PR B (D17) — ANGLE JUMEAU de {@see self::testGridSummaryCountsSlotsReservationsPinsBlocksAndLockedMinutes}
     * (forme abrégée `slots`/`reservations`) : le MÊME résumé sur la FORME RÉELLE que le support reçoit,
     * celle de {@see App\Entity\Schedule::engineInput()} — créneaux dans `venues[].trainingSlots` (jamais
     * `slots`) et épingles dans `slotTemplates` via `lockLevel:"HARD"` (jamais `reservations` + `pinned`).
     * `gridSummary` tolère les DEUX conventions ; cet angle garde une régression que le jumeau ne peut
     * PAS voir (un oubli du repli `?? trainingSlots` laisserait le jumeau VERT, mais la prod — qui envoie
     * `trainingSlots` — compterait 0 créneau).
     */
    public function testGridSummaryToleratesTheRealEngineInputShape(): void
    {
        // Snapshot MOTEUR réel : 2 trainingSlots, 2 slotTemplates dont 1 HARD (90 min), 1 bloc.
        $payload = [
            'venues' => [
                ['id' => 'v1', 'trainingSlots' => [
                    ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
                    ['dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 60, 'capacity' => 1],
                ]],
            ],
            'slotTemplates' => [
                ['id' => 's1', 'teamId' => 't1', 'venueId' => 'v1', 'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'lockLevel' => 'HARD'],
                ['id' => 's2', 'teamId' => 't2', 'venueId' => 'v1', 'dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 60, 'lockLevel' => 'NONE'],
            ],
            'sharedBlocks' => [
                ['id' => 'b1', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1],
            ],
        ];

        $summary = (new PayloadCapacityMirror)->gridSummary($payload);

        self::assertSame(2, $summary['slots'] ?? null, '2 créneaux lus dans `trainingSlots` (forme moteur réelle)');
        self::assertSame(2, $summary['reservations'] ?? null, '2 `slotTemplates` comptés comme réservations');
        self::assertSame(1, $summary['pins'] ?? null, 'une seule `slotTemplate` verrouillée (`lockLevel:"HARD"`)');
        self::assertSame(1, $summary['blocks'] ?? null, '1 bloc mutualisé');
        self::assertSame(90, $summary['lockedMinutes'] ?? null, 'la part verrouillée = la durée de la slotTemplate HARD (90 min)');
    }
}
