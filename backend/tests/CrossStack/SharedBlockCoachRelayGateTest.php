<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Lot 3B PR A — axes *constraint semantics* + *backend↔engine contract* : « un coach suffit »
 * (décision fondateur Q4B, 2026-10-10). Dès qu'une séance a plusieurs coachs requis, UN seul coach
 * disponible suffit — y compris pour une séance de BLOC, où l'UNION des coachs des membres est
 * considérée. Origine prouvée en prod (Toussaint BCCL) : un bloc U11 aux coachs mono-jour
 * incompatibles sortait INFEASIBLE alors qu'un coach de l'union couvre chaque jour.
 *
 * De bout en bout contre le VRAI moteur (`/generate`, contrat 1.4), payload construit à la main :
 *   (1) RELAIS — un bloc {t1,t2} dont AUCUN jour n'a les deux coachs, mais chaque jour a UN coach de
 *       l'union, place sa séance commune et co-localise les deux équipes (`completed`). Sous
 *       l'ANCIEN sens (« chaque coach requis ferme »), chaque jour fermait un membre → aucune case
 *       commune → INFEASIBLE : c'est donc bien le relais qui est prouvé.
 *   (2) TÉMOIN falsifiable — le MÊME bloc sur une case dont l'union n'a AUCUN coach disponible est
 *       FERMÉ : le moteur sort `failed`, les équipes ne sont PAS co-localisées. Sans ce témoin, un
 *       moteur qui IGNORERAIT la disponibilité des coachs pour les blocs passerait au vert.
 *
 * Tourne dans le job « Engine semantics » (groupe `contract`, `--group contract`) ET comme step
 * bloquant (groupe `phase1`) ; skip propre si l'engine est indisponible, comme les autres gates.
 */
#[Group('phase1')]
#[Group('contract')]
final class SharedBlockCoachRelayGateTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';

    public function testUnionCoachRelayPlacesTheBlockWhileNoUnionCoachClosesIt(): void
    {
        // (1) RELAIS — cA n'est libre que le mardi (jour 2), cB que le jeudi (jour 4). Aucun jour
        // n'a les DEUX coachs, mais chaque jour en a UN de l'union : le bloc place sa séance commune
        // et réunit t1 et t2 (le membre sans coach propre y est via le bloc).
        $relay = $this->solve($this->payload(venueDays: [2, 4], aDays: [2], bDays: [4]));
        self::assertSame('completed', $relay['status'], 'un coach de l\'union par jour suffit : le bloc est plaçable');
        self::assertTrue(
            $this->coLocated($relay),
            'le bloc doit réunir t1 et t2 : un coach de l\'union couvre la case, le membre sans coach y est via le bloc',
        );
        self::assertSame(
            [],
            $this->notHonoured($relay),
            'un bloc servi par un coach de l\'union ne doit produire aucun shared_block_not_honored',
        );

        // (2) TÉMOIN — le gymnase n'ouvre que le mardi, mais NI cA NI cB n'est libre ce jour-là
        // (tous deux disponibles le jeudi seulement) : l'union n'a aucun coach sur la seule case
        // candidate → le bloc est FERMÉ (b == 0 ⇒ Σb ne vaut pas commonSessions) → INFEASIBLE.
        $noCoach = $this->solve($this->payload(venueDays: [2], aDays: [4], bDays: [4]));
        self::assertSame(
            'failed',
            $noCoach['status'],
            'aucun coach de l\'union le seul jour ⇒ le bloc est fermé (témoin falsifiable)',
        );
        self::assertFalse(
            $this->coLocated($noCoach),
            'témoin cassé : sans coach de l\'union, le bloc ne doit JAMAIS être placé',
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function coLocated(array $result): bool
    {
        $cases = [];
        foreach ($result['slots'] ?? [] as $slot) {
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
     * Bloc {t1,t2} (1 séance commune), un gymnase capacité 1 ouvert les ``venueDays``, et deux
     * coachs requis (cA pour t1, cB pour t2) chacun disponible UNIQUEMENT ses ``aDays`` / ``bDays``.
     *
     * @param list<int> $venueDays
     * @param list<int> $aDays
     * @param list<int> $bDays
     *
     * @return array<string, mixed>
     */
    private function payload(array $venueDays, array $aDays, array $bDays): array
    {
        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-block-coach-relay',
            'seasonId' => 'season-block-coach-relay',
            'solverSeed' => 42,
            'teams' => [$this->team('t1'), $this->team('t2')],
            'venues' => [$this->venue('11111111-1111-4111-8111-111111111111', $venueDays)],
            'coaches' => [],
            'constraints' => [
                $this->teamCoach('tcA', 't1', 'cA'),
                $this->teamCoach('tcB', 't2', 'cB'),
                $this->coachAvailable('avA', 'cA', $aDays),
                $this->coachAvailable('avB', 'cB', $bDays),
            ],
            'slotTemplates' => [],
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    private function team(string $id): array
    {
        return ['id' => $id, 'name' => strtoupper($id), 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 1];
    }

    /**
     * @param list<int> $days
     *
     * @return array<string, mixed>
     */
    private function venue(string $id, array $days): array
    {
        return [
            'id' => $id, 'name' => 'V-' . substr($id, 0, 4),
            'trainingSlots' => array_map(
                static fn (int $day): array => ['dayOfWeek' => $day, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
                $days,
            ),
        ];
    }

    /**
     * TEAM_COACH v1 (forme ``serializeTeamCoachConstraints`` du backend) — coach requis MAIN.
     *
     * @return array<string, mixed>
     */
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
     * COACH_AVAILABILITY v2 (forme unifiée) — le coach n'est disponible QUE ces jours.
     *
     * @param list<int> $availableDays
     *
     * @return array<string, mixed>
     */
    private function coachAvailable(string $id, string $coachId, array $availableDays): array
    {
        return [
            'id' => $id,
            'scope' => 'COACH',
            'scopeTargetId' => $coachId,
            'family' => 'COACH_AVAILABILITY',
            'ruleType' => 'HARD',
            'name' => 'dispo ' . $coachId,
            'config' => ['availableDays' => $availableDays],
            'sortOrder' => 0,
            'isActive' => true,
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
