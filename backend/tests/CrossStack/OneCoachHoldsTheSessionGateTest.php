<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Axes *constraint semantics* + *backend↔engine contract* : « tant qu'un coach tient la séance,
 * c'est OK » (fondateur 2026-10-30), de bout en bout contre le VRAI moteur (`/generate`).
 *
 * Seul, un coach principal est indispensable ; à plusieurs, un seul suffit. Les trois tests partagent
 * le même décor (deux gymnases, une seule case, donc deux séances simultanées) : seul le lien de la
 * seconde équipe, ou le verrou, change d'un test à l'autre.
 *
 * Doublon VOULU (point sensible solveur) de `engine/tests/semantic/test_one_coach_holds_the_session.py`,
 * qui porte le détail : ici, l'angle est le contrat HTTP et c'est ce step qui gate. Skip propre si
 * l'engine est absent.
 */
#[Group('phase1')]
#[Group('contract')]
final class OneCoachHoldsTheSessionGateTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';

    private const string GYM_ONE = '44444444-4444-4444-8444-444444444444';

    private const string GYM_TWO = '55555555-5555-4555-8555-555555555555';

    /** U15 = A + B, U13 = A : les deux séances sont placées, et le résultat nomme B sur les U15. */
    public function testTheSecondCoachHoldsTheSessionAndIsNamed(): void
    {
        $result = $this->solve($this->payload(['A', 'B']));

        self::assertSame(['U13' => 'A', 'U15' => 'B'], $this->coachByTeam($result), 'B tient les U15 pendant que A entraîne les U13');
    }

    /** TÉMOIN : A seul sur les deux équipes ne se dédouble pas, une seule séance est placée. */
    public function testACoachAloneStillCannotBeInTwoGyms(): void
    {
        $result = $this->solve($this->payload(['A']));

        self::assertCount(1, $this->coachByTeam($result), 'A ne peut pas tenir deux gymnases');
    }

    /** Deux réservations simultanées avec un second coach : le planning sort, aucun écart annoncé. */
    public function testTwoLocksWithASecondCoachAreNotAViolation(): void
    {
        $result = $this->solve($this->payload(['A', 'B'], locked: true));

        self::assertSame('completed', $result['status']);
        self::assertSame(['U13' => 'A', 'U15' => 'B'], $this->coachByTeam($result));
        self::assertSame([], array_values(array_filter(
            $result['diagnostics'] ?? [],
            static fn (array $d): bool => 'rule_sacrificed' === ($d['type'] ?? null),
        )), 'un autre coach tient la séance : rien n\'est sacrifié');
    }

    /**
     * @param list<string> $u15Coaches
     *
     * @return array<string, mixed>
     */
    private function payload(array $u15Coaches, bool $locked = false): array
    {
        $constraints = [$this->teamCoach('tc-u13', 'U13', 'A')];
        foreach ($u15Coaches as $coachId) {
            $constraints[] = $this->teamCoach('tc-u15-' . $coachId, 'U15', $coachId);
        }

        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-one-coach',
            'seasonId' => 'season-one-coach',
            'solverSeed' => 42,
            'teams' => [$this->team('U15'), $this->team('U13')],
            'venues' => [$this->venue(self::GYM_ONE), $this->venue(self::GYM_TWO)],
            'coaches' => [
                ['id' => 'A', 'firstName' => 'Coach', 'lastName' => 'A'],
                ['id' => 'B', 'firstName' => 'Coach', 'lastName' => 'B'],
            ],
            'constraints' => $constraints,
            'slotTemplates' => $locked ? [$this->lock('U15', self::GYM_ONE), $this->lock('U13', self::GYM_TWO)] : [],
        ];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed> équipe placée => coach nommé, trié par équipe
     */
    private function coachByTeam(array $result): array
    {
        $coaches = [];
        foreach ($result['slots'] ?? [] as $slot) {
            $coaches[$slot['teamId']] = $slot['coachId'] ?? null;
        }
        ksort($coaches);

        return $coaches;
    }

    /** @return array<string, mixed> */
    private function team(string $id): array
    {
        return ['id' => $id, 'name' => $id, 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 1];
    }

    /** @return array<string, mixed> */
    private function venue(string $id): array
    {
        return [
            'id' => $id, 'name' => 'V-' . substr($id, 0, 4),
            'trainingSlots' => [['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    private function lock(string $teamId, string $venueId): array
    {
        return [
            'id' => 'lock-' . $teamId,
            'teamId' => $teamId,
            'venueId' => $venueId,
            'dayOfWeek' => 1,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'lockLevel' => 'HARD',
        ];
    }

    /** @return array<string, mixed> */
    private function teamCoach(string $id, string $teamId, string $coachId): array
    {
        return [
            'id' => $id,
            'teamId' => $teamId,
            'type' => 'TEAM_COACH',
            'severity' => 'HARD',
            'value' => $coachId,
            'metadata' => ['coachId' => $coachId, 'role' => 'MAIN', 'isRequired' => true],
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

            return $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }
}
