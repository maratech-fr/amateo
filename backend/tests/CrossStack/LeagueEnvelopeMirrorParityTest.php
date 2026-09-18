<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\MatchConflictDetector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FRT-32 — CÔTÉ BACKEND de la parité mécanique du prédicat d'enveloppe ligue.
 *
 * `matches/lib/envelope.ts::kickoffInsideLeagueWindow` (front, BLOQUE la pose via
 * `isInEnvelope`) et `MatchConflictDetector::kickoffInsideLeagueWindow` (backend,
 * DIAGNOSTIQUE LEAGUE_WINDOW_VIOLATION) partagent CETTE algèbre d'appartenance —
 * intervalle FERMÉ `[kickoffMin, kickoffMax]` (les deux bornes incluses), filtrée sur le
 * JOUR. Les MÊMES cas (`leagueEnvelope.parity.json`) les traversent : changer l'algèbre
 * d'un seul côté rougit ce côté-là.
 *
 * L'exemption AMICAL (front : `!isFriendly` ; backend : saut des `competitionId` null) et
 * la résolution équipe↔fenêtre (déjà serveur) divergent par conception — c'est déclaré
 * côté front, hors de ce prédicat. Ce module figure au registre `FrontRederivationRegistryTest`.
 */
#[Group('contract')]
final class LeagueEnvelopeMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/matches/lib/leagueEnvelope.parity.json';

    public function testBackendKickoffInsideLeagueWindowMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            $expected = (bool) $case['inside'];
            self::assertSame(
                $expected,
                MatchConflictDetector::kickoffInsideLeagueWindow((int) $case['day'], (string) $case['kickoff'], $case['windows']),
                \sprintf(
                    "PARITÉ ROMPUE (« %s ») : l'algèbre d'enveloppe ligue backend diverge du front.\n"
                    . 'Front `kickoffInsideLeagueWindow` et backend `MatchConflictDetector::kickoffInsideLeagueWindow` doivent coïncider sur leagueEnvelope.parity.json.',
                    (string) $case['name'],
                ),
            );
        }
    }

    /** @return list<array{name: string, day: int, kickoff: string, windows: list<array{dayOfWeek: int, kickoffMin: string, kickoffMax: string}>, inside: bool}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{name: string, day: int, kickoff: string, windows: list<array{dayOfWeek: int, kickoffMin: string, kickoffMax: string}>, inside: bool}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'leagueEnvelope.parity.json ne porte plus aucun cas.');

        return $list;
    }
}
