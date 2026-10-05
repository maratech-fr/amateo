<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Lot PASSERELLES PR-2 — axe SÉMANTIQUE (§7.1) : une passerelle MANDATORY doit être HONORÉE par le
 * VRAI solveur, pas seulement acceptée. Deux équipes que TOUT pousse à se chevaucher (même gymnase
 * capacité 2, même créneau, toutes deux le préfèrent) sont SÉPARÉES par le moteur dès que la
 * passerelle MANDATORY est déclarée ; le TÉMOIN — le même payload SANS la passerelle — les laisse
 * se CHEVAUCHER (l'objectif préfère leur gymnase commun). Sans le témoin, un scénario où le solveur
 * les sépare déjà spontanément passerait au vert sans rien prouver.
 *
 * Tourne dans le job CI « Engine semantics » (groupe `contract`), moteur réel ; skip propre s'il
 * est indisponible, comme les autres tests de contrat. Câblé comme `SharedTrainingHonouredByEngineTest`.
 */
#[Group('contract')]
final class TeamLinkHonouredByEngineTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';
    private const string V_SHARED = '11111111-1111-4111-8111-111111111111';
    private const string V_ALT = '22222222-2222-4222-8222-222222222222';

    public function testTwoBridgedTeamsAreSeparatedByTheRealSolver(): void
    {
        // AVEC la passerelle MANDATORY : le chevauchement est INTERDIT → les deux équipes se séparent.
        $withLink = $this->overlaps($this->solve($this->payload(withLink: true)));
        self::assertFalse(
            $withLink,
            'une passerelle MANDATORY doit empêcher le chevauchement — le solveur ne l\'honore pas',
        );

        // TÉMOIN — SANS la passerelle, leur gymnase préféré commun (capacité 2) les CO-LOCALISE.
        $withoutLink = $this->overlaps($this->solve($this->payload(withLink: false)));
        self::assertNotSame(
            $withLink,
            $withoutLink,
            'témoin cassé : le solveur les sépare déjà SANS la passerelle — le scénario ne prouve rien',
        );
    }

    /**
     * Deux séances se chevauchent-elles dans le temps (même jour, intervalles intersectés) ?
     *
     * @param array<string, mixed> $result
     */
    private function overlaps(array $result): bool
    {
        $spans = ['t1' => [], 't2' => []];
        foreach ($result['slots'] as $slot) {
            $team = $slot['teamId'];
            if (!isset($spans[$team])) {
                continue;
            }
            $start = (int) substr((string) $slot['startTime'], 0, 2) * 60 + (int) substr((string) $slot['startTime'], 3, 2);
            $spans[$team][] = [(int) $slot['dayOfWeek'], $start, $start + (int) $slot['durationMinutes']];
        }

        foreach ($spans['t1'] as [$aDay, $aStart, $aEnd]) {
            foreach ($spans['t2'] as [$bDay, $bStart, $bEnd]) {
                if ($aDay === $bDay && $aStart < $bEnd && $bStart < $aEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Un gymnase capacité 2 le lundi (préféré des deux équipes, la case tentante) et un gymnase
     * capacité 1 le mardi (l'échappatoire qui rend la séparation possible). Sans passerelle, les
     * deux vont à leur gymnase préféré commun (chevauchement) ; avec, MANDATORY les sépare.
     *
     * @return array<string, mixed>
     */
    private function payload(bool $withLink): array
    {
        $payload = [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-team-link-proof',
            'seasonId' => 'season-team-link-proof',
            'solverSeed' => 42,
            'teams' => [$this->team('t1'), $this->team('t2')],
            'venues' => [
                $this->venue(self::V_SHARED, [[1, '18:00', 2]]),
                $this->venue(self::V_ALT, [[2, '18:00', 1]]),
            ],
            'coaches' => [],
            'constraints' => [
                $this->preferVenue('pref1', 't1', self::V_SHARED),
                $this->preferVenue('pref2', 't2', self::V_SHARED),
            ],
            'slotTemplates' => [],
        ];

        if ($withLink) {
            $payload['teamLinks'] = [['id' => 'l', 'teamAId' => 't1', 'teamBId' => 't2', 'intensity' => 'MANDATORY']];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function preferVenue(string $id, string $teamId, string $venueId): array
    {
        return [
            'id' => $id,
            'scope' => 'TEAM',
            'scopeTargetId' => $teamId,
            'family' => 'FACILITY',
            'ruleType' => 'PREFERRED',
            'name' => 'préférence gymnase',
            'config' => ['preferredVenueId' => $venueId],
            'sortOrder' => 0,
            'isActive' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function team(string $id): array
    {
        return ['id' => $id, 'name' => strtoupper($id), 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 1];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}> $slots
     *
     * @return array<string, mixed>
     */
    private function venue(string $id, array $slots): array
    {
        return [
            'id' => $id, 'name' => 'V-' . substr($id, 0, 4),
            'trainingSlots' => array_map(
                static fn (array $s): array => ['dayOfWeek' => $s[0], 'startTime' => $s[1], 'durationMinutes' => 90, 'capacity' => $s[2]],
                $slots,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function solve(array $payload): array
    {
        $client = HttpClient::create(['timeout' => 30]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());
            $result = $response->toArray(false);
            self::assertSame('completed', $result['status'], 'le scénario doit rester résoluble');

            return $result;
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }
}
