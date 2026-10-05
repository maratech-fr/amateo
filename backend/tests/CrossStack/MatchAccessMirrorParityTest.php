<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\MatchConflictDetector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-88 — CÔTÉ BACKEND de la parité mécanique du prédicat d'accès match.
 *
 * `matches/lib/matchAccess.ts::kickoffInsideWindow` (front, BLOQUE la pose sur le rail
 * synchrone) et `MatchConflictDetector::kickoffInsideWindow` (backend, DIAGNOSTIQUE
 * ACCESS_WINDOW_LOST) partagent CETTE algèbre d'appartenance — intervalle demi-ouvert
 * `[start, end[`, filtrée sur (gymnase, jour). Les MÊMES cas (`matchAccess.parity.json`)
 * les traversent : changer l'algèbre d'un seul côté rougit ce côté-là.
 *
 * L'ENVELOPPE diverge par conception (front : « aucune fenêtre ce jour → refus » +
 * indisponibilité, propres à la pose ; backend : HOME déjà posés seulement) — c'est déclaré
 * côté front, hors de ce prédicat. Ce module figure au registre `FrontRederivationRegistryTest`.
 */
#[Group('contract')]
final class MatchAccessMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/matches/lib/matchAccess.parity.json';

    public function testBackendKickoffInsideWindowMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            $expected = (bool) $case['inside'];
            self::assertSame(
                $expected,
                MatchConflictDetector::kickoffInsideWindow((string) $case['venueId'], (int) $case['day'], (string) $case['kickoff'], $case['windows']),
                \sprintf(
                    "PARITÉ ROMPUE (« %s ») : l'algèbre d'accès match backend diverge du front.\n"
                    . 'Front `kickoffInsideWindow` et backend `MatchConflictDetector::kickoffInsideWindow` doivent coïncider sur matchAccess.parity.json.',
                    (string) $case['name'],
                ),
            );
        }
    }

    public function testBackendDateInsideClosureMatchesTheSharedCases(): void
    {
        foreach ($this->closureCases() as $case) {
            $expected = (bool) $case['inside'];
            self::assertSame(
                $expected,
                MatchConflictDetector::dateInsideClosure((string) $case['matchDate'], (string) $case['startDate'], (string) $case['endDate']),
                \sprintf(
                    "PARITÉ ROMPUE (« %s ») : l'algèbre de fermeture de gymnase backend diverge du front.\n"
                    . 'Front `dateInsideClosure` et backend `MatchConflictDetector::dateInsideClosure` doivent coïncider sur matchAccess.parity.json (closureCases).',
                    (string) $case['name'],
                ),
            );
        }
    }

    /** @return list<array{name: string, venueId: string, day: int, kickoff: string, windows: list<array<string, mixed>>, inside: bool}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{name: string, venueId: string, day: int, kickoff: string, windows: list<array<string, mixed>>, inside: bool}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'matchAccess.parity.json ne porte plus aucun cas.');

        return $list;
    }

    /** @return list<array{name: string, matchDate: string, startDate: string, endDate: string, inside: bool}> */
    private function closureCases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{name: string, matchDate: string, startDate: string, endDate: string, inside: bool}> $list */
        $list = $decoded['closureCases'] ?? [];
        self::assertNotEmpty($list, 'matchAccess.parity.json ne porte plus aucun cas de fermeture (closureCases).');

        return $list;
    }
}
