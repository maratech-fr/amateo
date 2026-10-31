<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\CoachDoubleBookingDetector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-88 — CÔTÉ BACKEND de la parité mécanique de la règle de dédoublement de coach.
 *
 * `wizard/lib/coachDoubleBooking.ts::bookingsCollide` (front, prévient au clic) et
 * `CoachDoubleBookingDetector::bookingsCollide` (backend, bloque au récap) sont deux
 * implémentations INÉVITABLES de la même règle (la modale doit répondre sans aller-retour
 * réseau). Elles ne restaient alignées que par des suites JUMELLES « qui se ressemblent »
 * (P2-9) — ce qui ne garantit rien. Ici, les MÊMES cas (`coachDoubleBooking.parity.json`,
 * dans l'arbre front) traversent les DEUX implémentations : changer la règle d'un seul
 * côté rougit ce côté-là. C'est la parité MÉCANIQUE que demande la règle d'intention de
 * `.claude/rules/frontend.md`.
 *
 * Ce module figure au registre `FrontRederivationRegistryTest` (miroir déclaré + parité).
 */
#[Group('contract')]
final class CoachDoubleBookingMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/wizard/lib/coachDoubleBooking.parity.json';

    public function testBackendBookingsCollideMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            $expected = (bool) $case['collide'];
            $name = (string) $case['name'];

            self::assertSame($expected, CoachDoubleBookingDetector::bookingsCollide($case['a'], $case['b']), \sprintf(
                "PARITÉ ROMPUE (« %s ») : le backend ne rend pas le verdict partagé.\n"
                . "Front `bookingsCollide` et backend `CoachDoubleBookingDetector::bookingsCollide` doivent\n"
                . 'coïncider sur coachDoubleBooking.parity.json — sinon la modale autorise ce que le récap refuse.',
                $name,
            ));
            // Symétrique : l'ordre des deux séances ne change jamais le verdict.
            self::assertSame($expected, CoachDoubleBookingDetector::bookingsCollide($case['b'], $case['a']), $name . ' (ordre inversé)');
        }
    }

    /**
     * Chantier H — la TOLÉRANCE d'arrivée tardive se décide IDENTIQUEMENT des deux côtés.
     * `lateArrivalTolerated($a, $b, $toleranceMinutes, $baremeMinutes)` rend LE verdict partagé
     * (`coachDoubleBooking.parity.json::toleranceCases`) : retard = max(0, fin(a) + barème − début(b)),
     * toléré ssi 1 <= tolérance <= 30, début(b) STRICTEMENT après début(a), et retard <= tolérance.
     * Changer la règle d'un seul côté rougit ce côté-là ; la disponibilité de l'adjoint reste HORS
     * de cette règle pure (vérifiée serveur, cf. CoachLateToleranceSemanticsGateTest).
     */
    public function testBackendLateArrivalToleratedMatchesTheSharedCases(): void
    {
        foreach ($this->toleranceCases() as $case) {
            $expected = (bool) $case['tolerated'];
            $name = (string) $case['name'];

            self::assertSame($expected, CoachDoubleBookingDetector::lateArrivalTolerated(
                $case['a'],
                $case['b'],
                (int) $case['toleranceMinutes'],
                (int) $case['baremeMinutes'],
            ), \sprintf(
                "PARITÉ ROMPUE (« %s ») : le backend ne rend pas le verdict de tolérance partagé.\n"
                . 'Front `lateArrivalTolerated` et backend `CoachDoubleBookingDetector::lateArrivalTolerated`'
                . ' doivent coïncider sur coachDoubleBooking.parity.json::toleranceCases.',
                $name,
            ));
        }
    }

    /**
     * Chantier H / P4-333 — deux équipes co-réservées partagent-elles un coach PRINCIPAL ?
     * `teamsShareMainCoach($coachIdsA, $coachIdsB)` rend LE verdict partagé
     * (`coachDoubleBooking.parity.json::multiCoachCases`). Le bloqueur ne regardait que le
     * premier principal (P4-319) ; il doit regarder TOUS les principaux (exemple Camille/Dorian).
     */
    public function testBackendTeamsShareMainCoachMatchesTheSharedCases(): void
    {
        foreach ($this->multiCoachCases() as $case) {
            $expected = (bool) $case['shares'];
            $name = (string) $case['name'];

            self::assertSame($expected, CoachDoubleBookingDetector::teamsShareMainCoach(
                $case['coachIdsA'],
                $case['coachIdsB'],
            ), \sprintf('PARITÉ MULTI-COACH ROMPUE (« %s »).', $name));
            // Symétrique : l'ordre des deux équipes ne change jamais le verdict.
            self::assertSame($expected, CoachDoubleBookingDetector::teamsShareMainCoach(
                $case['coachIdsB'],
                $case['coachIdsA'],
            ), $name . ' (ordre inversé)');
        }
    }

    /**
     * « Tant qu'un coach tient la séance, c'est OK » — l'affectation des tenues se décide
     * IDENTIQUEMENT des deux côtés : `holdersFor` rend EXACTEMENT les tenues de
     * `coachDoubleBooking.parity.json::holdCases`, ou null si une séance n'a personne. Sans cette
     * parité, la modale laisserait poser ce que le récap bloque.
     */
    public function testBackendHoldersForMatchesTheSharedCases(): void
    {
        /** @var list<array{name: string, sessions: list<array{id: string, teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>, mainCoachesByTeam: array<string, list<string>>, blockedByCoach?: array<string, list<array{0: int, 1: int, 2: int}>>, playingByCoach?: array<string, list<array{teamId: string, venueId: string, dayOfWeek: int, startMinutes: int, durationMinutes: int}>>, groups?: list<list<string>>, holders: array<string, string>|null}> $cases */
        $cases = $this->decoded()['holdCases'] ?? [];
        self::assertNotEmpty($cases, 'coachDoubleBooking.parity.json ne porte aucun cas de tenue.');

        foreach ($cases as $case) {
            self::assertEquals($case['holders'], CoachDoubleBookingDetector::holdersFor(
                $case['sessions'],
                $case['mainCoachesByTeam'],
                $case['blockedByCoach'] ?? [],
                $case['playingByCoach'] ?? [],
                $case['groups'] ?? [],
            ), \sprintf('PARITÉ DES TENUES ROMPUE (« %s »).', $case['name']));
        }
    }

    /** @return list<array{name: string, a: array<string, mixed>, b: array<string, mixed>, collide: bool}> */
    private function cases(): array
    {
        /** @var list<array{name: string, a: array<string, mixed>, b: array<string, mixed>, collide: bool}> $list */
        $list = $this->decoded()['cases'] ?? [];
        self::assertNotEmpty($list, 'coachDoubleBooking.parity.json ne porte plus aucun cas.');

        return $list;
    }

    /** @return list<array{name: string, a: array<string, mixed>, b: array<string, mixed>, toleranceMinutes: int, baremeMinutes: int, tolerated: bool}> */
    private function toleranceCases(): array
    {
        /** @var list<array{name: string, a: array<string, mixed>, b: array<string, mixed>, toleranceMinutes: int, baremeMinutes: int, tolerated: bool}> $list */
        $list = $this->decoded()['toleranceCases'] ?? [];
        self::assertNotEmpty($list, 'coachDoubleBooking.parity.json ne porte aucun cas de tolérance.');

        return $list;
    }

    /** @return list<array{name: string, coachIdsA: list<string>, coachIdsB: list<string>, shares: bool}> */
    private function multiCoachCases(): array
    {
        /** @var list<array{name: string, coachIdsA: list<string>, coachIdsB: list<string>, shares: bool}> $list */
        $list = $this->decoded()['multiCoachCases'] ?? [];
        self::assertNotEmpty($list, 'coachDoubleBooking.parity.json ne porte aucun cas multi-coach.');

        return $list;
    }

    /** @return array<string, mixed> */
    private function decoded(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
