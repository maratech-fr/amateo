<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Lot 9 — GATE NR, axes *constraint semantics* ET *planning lifecycle* (§7.1) : un bloc de
 * mutualisation ANCRÉ À UNE CASE par le geste « mutualiser depuis la génération » reste sur sa case
 * à la RÉGÉNÉRATION comme au COMBLEMENT, et le VRAI solveur l'honore sans le signaler en conflit.
 *
 * Ce que le geste écrit (lot 9) : un `sharedBlocks` {t1,t2} commonSessions 1 + les séances des
 * membres co-localisées sur la case ET verrouillées HARD (niveau que `model._extract_hard_locks`
 * lit). Le solveur fait alors tenir la co-présence du bloc EN UNE occupation (dé-comptage
 * `shared_block_room_relief` / fold post-solve `_fold_case_occupant_identity`), là où deux séances
 * co-localisées SANS bloc saturent une case de capacité 1.
 *
 * Deux chemins de génération, UNE promesse — « la case est gardée » :
 *  1. RÉGÉNÉRER : le payload complet porte le bloc + ses verrous HARD → les deux membres restent
 *     sur la case, aucun conflit de sur-capacité, aucun `shared_block_not_honored`.
 *  2. COMBLER : même bloc épinglé HARD (ce que `withPinnedAssignments` fige lors d'un solve
 *     partiel) + une équipe à placer dans un trou → le bloc NE BOUGE PAS de sa case, le trou est
 *     comblé ailleurs.
 *
 * TÉMOIN falsifiable — le même payload de régénération SANS la déclaration de bloc : les deux
 * séances HARD co-localisées sur une case capacité 1 DÉCLENCHENT le conflit de sur-capacité
 * post-solve. C'est la déclaration de bloc, et elle seule, qui légitime la case partagée ; sans le
 * témoin, un scénario où rien ne saturerait passerait au vert sans rien prouver.
 *
 * Tourne contre le VRAI moteur `/generate` (groupes `phase1` + `contract`) ; skip propre s'il est
 * indisponible. Gate CI : step nommé du job `blocking-tests` + ligne `docs/testing/blocking-tests.md`.
 */
#[Group('phase1')]
#[Group('contract')]
final class SharedBlockAnchoredCaseGateTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';

    /** La case d'ANCRAGE : gymnase V1, lundi (1), 18:00, capacité 1. */
    private const string V1 = '11111111-1111-4111-8111-111111111111';

    /** Un gymnase LIBRE pour le trou à combler (scénario COMBLER). */
    private const string V2 = '22222222-2222-4222-8222-222222222222';

    private const string ANCHOR_CASE = self::V1 . '|1|18:00';

    /** RÉGÉNÉRER — le bloc ancré garde sa case, honoré et sans conflit de sur-capacité. */
    public function testRegenerateKeepsTheBlockAnchoredToItsCase(): void
    {
        $result = $this->solve($this->anchoredPayload(withBlock: true));

        self::assertSame('completed', $result['status'], 'le scénario doit rester résoluble');
        self::assertSame(self::ANCHOR_CASE, $this->caseOf($result, 't1'));
        self::assertSame(self::ANCHOR_CASE, $this->caseOf($result, 't2'), 'les deux membres doivent rester sur la case d\'ancrage');
        self::assertSame([], $this->venueConflicts($result), 'un bloc ancré ne sur-occupe pas sa case (dé-comptage)');
        self::assertSame([], $this->notHonoured($result), 'le bloc ancré ne doit jamais être signalé non honoré');
    }

    /** COMBLER — combler un trou ne déloge pas le bloc ancré de sa case. */
    public function testFillKeepsTheBlockAnchoredToItsCase(): void
    {
        $result = $this->solve($this->fillPayload());

        self::assertSame('completed', $result['status'], 'le comblement doit rester résoluble');
        self::assertSame(self::ANCHOR_CASE, $this->caseOf($result, 't1'));
        self::assertSame(self::ANCHOR_CASE, $this->caseOf($result, 't2'), 'combler un trou ne déplace pas le bloc ancré');
        self::assertNotNull($this->caseOf($result, 't3'), 'l\'équipe du trou doit être placée');
        self::assertSame([], $this->venueConflicts($result), 'le bloc ancré ne sur-occupe pas sa case après comblement');
        self::assertSame([], $this->notHonoured($result), 'le bloc ancré reste honoré après comblement');
    }

    /**
     * TÉMOIN — SANS la déclaration de bloc, les DEUX séances HARD co-localisées sur une case
     * capacité 1 déclenchent le conflit de sur-capacité post-solve. C'est ce que la déclaration de
     * bloc efface dans les deux tests ci-dessus : sans ce témoin, « aucun conflit » ne prouverait rien.
     */
    public function testWithoutTheBlockTheCoLocatedLocksOverbookTheCase(): void
    {
        $result = $this->solve($this->anchoredPayload(withBlock: false));

        self::assertNotSame(
            [],
            $this->venueConflicts($result),
            'témoin cassé : deux séances HARD co-localisées sur une case capacité 1 passent sans conflit même SANS bloc — le scénario ne prouve rien',
        );
    }

    /**
     * Le payload que le geste LAISSE : t1 et t2 verrouillés HARD sur la case d'ancrage (capacité 1),
     * et — avec le bloc — la déclaration `sharedBlocks` {t1,t2} 1 séance commune qui fait tenir leur
     * co-présence en UNE occupation.
     *
     * @return array<string, mixed>
     */
    private function anchoredPayload(bool $withBlock): array
    {
        $payload = [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-anchored-block-gate',
            'seasonId' => 'season-anchored-block-gate',
            'solverSeed' => 42,
            'teams' => [$this->team('t1'), $this->team('t2')],
            'venues' => [$this->venue(self::V1, [[1, '18:00', 1]])],
            'coaches' => [],
            'constraints' => [],
            'slotTemplates' => [
                $this->hardLock('lock-t1', 't1', self::V1, 1, '18:00'),
                $this->hardLock('lock-t2', 't2', self::V1, 1, '18:00'),
            ],
        ];

        if ($withBlock) {
            $payload['sharedBlocks'] = [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]];
        }

        return $payload;
    }

    /**
     * Le scénario COMBLER : le bloc ancré épinglé HARD sur sa case (ce que fige un solve partiel) +
     * une équipe t3 à placer dans un trou (un créneau libre en V2). Le bloc ne doit pas bouger.
     *
     * @return array<string, mixed>
     */
    private function fillPayload(): array
    {
        $payload = $this->anchoredPayload(withBlock: true);
        $payload['teams'][] = $this->team('t3');
        $payload['venues'][] = $this->venue(self::V2, [[1, '18:00', 1]]);

        return $payload;
    }

    /**
     * La case « venue|day|HH:MM » où une équipe est placée dans le résultat, ou null si absente.
     *
     * @param array<string, mixed> $result
     */
    private function caseOf(array $result, string $teamId): ?string
    {
        foreach ($result['slots'] ?? [] as $slot) {
            if (($slot['teamId'] ?? null) === $teamId) {
                return $slot['venueId'] . '|' . $slot['dayOfWeek'] . '|' . substr((string) $slot['startTime'], 0, 5);
            }
        }

        return null;
    }

    /**
     * Les diagnostics de SUR-CAPACITÉ de gymnase (`diag-conflict-venue-*`) — ce que le fold de
     * co-présence d'un bloc efface, et qu'une case sur-occupée fait apparaître.
     *
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private function venueConflicts(array $result): array
    {
        return array_values(array_filter(
            $result['diagnostics'] ?? [],
            static fn (array $d): bool => str_starts_with((string) ($d['id'] ?? ''), 'diag-conflict-venue-'),
        ));
    }

    /**
     * Les diagnostics de mutualisation par bloc NON honorée.
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

    /** @return array<string, mixed> */
    private function hardLock(string $id, string $teamId, string $venueId, int $dayOfWeek, string $startTime): array
    {
        return [
            'id' => $id,
            'teamId' => $teamId,
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
            'durationMinutes' => 90,
            'lockLevel' => 'HARD',
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
