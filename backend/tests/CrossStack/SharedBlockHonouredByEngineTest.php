<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * P2-51 PR-3 — axe SÉMANTIQUE (§7.1) : un BLOC de mutualisation doit être HONORÉ par le VRAI
 * solveur, pas seulement accepté. Le bloc se comporte comme UNE équipe : ses `commonSessions`
 * séances lui appartiennent, le solveur les place comme celles d'une équipe.
 *
 * La preuve distingue le bloc du groupe {équipes, K} : le bloc fait tenir la co-présence de ses
 * membres dans une case de capacité 1 (dé-comptage). AVEC le bloc, deux équipes atterrissent sur
 * la MÊME case capacité 1 ; le TÉMOIN — le même payload SANS le bloc — les SÉPARE, parce que la
 * capacité 1 leur INTERDIT de la partager. Sans le témoin, un scénario où le solveur co-localise
 * déjà spontanément passerait au vert sans rien prouver.
 *
 * Tourne dans le job CI « Engine semantics » (groupe `contract`), moteur réel ; skip propre s'il
 * est indisponible, comme les autres tests de contrat.
 */
#[Group('contract')]
final class SharedBlockHonouredByEngineTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';
    private const string V1 = '11111111-1111-4111-8111-111111111111';
    private const string V2 = '22222222-2222-4222-8222-222222222222';
    private const string V3 = '33333333-3333-4333-8333-333333333333';

    public function testTwoBlockTeamsShareOneCapacityOneCaseHonouredByTheRealSolver(): void
    {
        // AVEC le bloc : la séance commune du bloc réunit t1 et t2 sur UNE case capacité 1 (le
        // dé-comptage la fait tenir), et le bloc n'est jamais signalé non honoré.
        $withBlock = $this->solve($this->payload(withBlock: true));
        self::assertTrue(
            $this->coLocated($withBlock),
            'les deux équipes d\'un bloc doivent partager une séance commune — le solveur ne l\'honore pas',
        );
        self::assertSame(
            [],
            $this->notHonoured($withBlock),
            'un bloc honoré ne doit produire aucun diagnostic de mutualisation par bloc non honorée',
        );

        // TÉMOIN — SANS le bloc, la capacité 1 INTERDIT le partage : les deux équipes se SÉPARENT.
        $withoutBlock = $this->solve($this->payload(withBlock: false));
        self::assertFalse(
            $this->coLocated($withoutBlock),
            'témoin cassé : le solveur co-localise déjà SANS la déclaration de bloc — le scénario ne prouve rien',
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function coLocated(array $result): bool
    {
        $cases = [];
        foreach ($result['slots'] as $slot) {
            $cases[$slot['teamId']] = $slot['venueId'] . '|' . $slot['dayOfWeek'] . '|' . substr((string) $slot['startTime'], 0, 5);
        }

        return isset($cases['t1'], $cases['t2']) && $cases['t1'] === $cases['t2'];
    }

    /**
     * Diagnostics de mutualisation par bloc non honorée.
     *
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private function notHonoured(array $result): array
    {
        return array_values(array_filter(
            $result['diagnostics'] ?? [],
            static fn (array $d): bool => 'shared_block_not_honored' === ($d['type'] ?? null),
        ));
    }

    /**
     * Deux équipes (1 séance chacune) et trois créneaux de capacité 1 (deux le lundi, un le mardi).
     * AVEC le bloc {t1,t2} 1 séance commune, elles se retrouvent sur une case (le dé-comptage la
     * rend partageable en capacité 1) ; SANS, la capacité 1 les force sur des créneaux séparés.
     *
     * @return array<string, mixed>
     */
    private function payload(bool $withBlock): array
    {
        $payload = [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-shared-block-proof',
            'seasonId' => 'season-shared-block-proof',
            'solverSeed' => 42,
            'teams' => [$this->team('t1'), $this->team('t2')],
            'venues' => [
                $this->venue(self::V1, [[1, '18:00', 1]]),
                $this->venue(self::V2, [[1, '20:00', 1]]),
                $this->venue(self::V3, [[2, '18:00', 1]]),
            ],
            'coaches' => [],
            'constraints' => [],
            'slotTemplates' => [],
        ];

        if ($withBlock) {
            $payload['sharedBlocks'] = [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function team(string $id): array
    {
        return ['id' => $id, 'name' => strtoupper($id), 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 1, 'isActive' => true];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}> $slots
     *
     * @return array<string, mixed>
     */
    private function venue(string $id, array $slots): array
    {
        return [
            'id' => $id, 'name' => 'V-' . substr($id, 0, 4), 'isActive' => true,
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
