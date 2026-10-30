<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * NR BLOQUANT (axes §7.1 *generation pipeline* + *constraint semantics* + *backend↔engine
 * contract*) — « la réservation gagne envers tout, mais c'est la SEULE exception » (besoin
 * fondateur 2026-10-30, Q1/Q3 ; amendement ADR-0001). Deux promesses JUMELLES, de bout en bout
 * contre le VRAI moteur (`/generate`), payload construit À LA MAIN (patron
 * `InfeasibilityNamesConflictingRulesTest` / `SharedBlockCoachRelayGateTest`) :
 *
 *   (1) RÉSERVATION SOUVERAINE — quand un VERROU HARD (réservation, épingle) rend une règle DURE
 *       insatisfiable, le moteur PRODUIT le planning et NOMME la règle sacrifiée (diagnostic
 *       `rule_sacrificed`, severity WARNING), jamais un INFEASIBLE muet. Cas réel constaté en production : un
 *       bloc mutualisé dont la seule case commune est RÉSERVÉE par une équipe TIERCE → aujourd'hui
 *       INFEASIBLE en 4 ms sans rien nommer. TÉMOIN de CAUSE : le MÊME décor SANS la réservation
 *       place le bloc (`completed`, zéro sacrifice) — c'est bien le verrou qui force le sacrifice.
 *
 *   (2) LA SEULE EXCEPTION — sans verrou en cause, deux règles DURES qui se contredisent restent un
 *       ÉCHEC, mais EXPLIQUÉ : coach indisponible le vendredi + équipe dont le vendredi est imposé,
 *       gymnase ouvert le vendredi SEULEMENT → `failed` + `diag-infeasible` qui NOMME les deux
 *       règles. Doublon ASSUMÉ (point sensible solveur) de la promesse de
 *       {@see InfeasibilityNamesConflictingRulesTest} — ICI l'angle est « la réservation est la
 *       SEULE exception », côte à côte avec (1), sur le même gate.
 *
 * Garde l'invariant amendé d'ADR-0001 : la réservation est souveraine, mais le sacrifice est
 * DIAGNOSTIQUÉ ; hors réservation, l'infaisabilité échoue BRUYAMMENT, jamais un repli relaxé.
 *
 * Étape NOMMÉE du job `blocking-tests` de `.github/workflows/ci.yml` (php-fpm + engine déjà
 * démarrés) ET ligne de `docs/testing/blocking-tests.md` — gardé par `BlockingTestsListMatchesCiTest`.
 * Groupe `phase1` (gate) + `contract` (job engine-semantics) ; skip propre si l'engine est absent.
 */
#[Group('phase1')]
#[Group('contract')]
final class SovereignReservationProducesScheduleTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';

    // Bloc {t1,t2} : UNIQUE case candidate = COMMON_VENUE mercredi 18:00 (capacité 2). Sans verrou,
    // le bloc y réunit ses deux membres (une occupation, capacité 2) ; avec la réservation d'une
    // équipe TIERCE, la case n'a plus la place de réunir t1 ET t2 → aujourd'hui INFEASIBLE.
    private const string COMMON_VENUE = '11111111-1111-4111-8111-111111111111';
    private const int WEDNESDAY = 3;

    public function testAReservationOnTheOnlyBlockCaseWinsAndNamesTheSacrificedRule(): void
    {
        // La seule case commune du bloc (mercredi 18:00) est RÉSERVÉE HARD par une équipe TIERCE
        // (t3) : le bloc ne peut plus réunir t1 et t2 nulle part → aujourd'hui INFEASIBLE muet. Le
        // verrou est souverain : le planning est PRODUIT, t3 reste sur sa case réservée, et le bloc
        // sacrifié est NOMMÉ.
        $result = $this->solve($this->blockPayload(withReservation: true));

        self::assertSame(
            'completed',
            $result['status'],
            'une réservation souveraine doit PRODUIRE le planning, jamais INFEASIBLE muet',
        );

        // t3 (l'équipe réservée) est bien posée sur sa case mercredi 18:00 — le verrou a gagné.
        $t3OnReservedCase = array_filter(
            $result['slots'] ?? [],
            static fn (array $slot): bool => 't3' === ($slot['teamId'] ?? null)
                && self::WEDNESDAY === ($slot['dayOfWeek'] ?? null)
                && self::COMMON_VENUE === ($slot['venueId'] ?? null),
        );
        self::assertNotEmpty($t3OnReservedCase, 'la réservation souveraine doit poser t3 sur sa case');

        // Un diagnostic rule_sacrificed (WARNING) NOMME le bloc sacrifié.
        $sacrificed = $this->sacrificed($result);
        self::assertNotEmpty($sacrificed, 'une règle dure rendue insatisfiable par le verrou doit être NOMMÉE (rule_sacrificed)');
        self::assertSame('WARNING', $sacrificed[0]['severity'] ?? null, 'un sacrifice est une ALERTE, pas une erreur bloquante');
        $named = (string) ($sacrificed[0]['message'] ?? '');
        self::assertTrue(
            str_contains($named, 'T1') && str_contains($named, 'T2'),
            'la règle sacrifiée (le bloc) doit NOMMER ses équipes : ' . $named,
        );
    }

    public function testWithoutTheReservationTheBlockIsPlacedAndNothingIsSacrificed(): void
    {
        // TÉMOIN de CAUSE — on retire la réservation de la case commune : le bloc réunit t1 et t2
        // sur la case mercredi, génération COMPLETED, AUCUN sacrifice. Sans ce témoin, un scénario
        // déjà infaisable pour une autre raison ferait passer (1) au vert sans rien prouver du verrou.
        $result = $this->solve($this->blockPayload(withReservation: false));

        self::assertSame('completed', $result['status'], 'sans le verrou, le bloc doit se placer normalement');
        self::assertEmpty($this->sacrificed($result), 'aucune règle n\'est « sacrifiée » quand aucun verrou ne la contredit');
    }

    public function testWithoutAReservationContradictoryRulesStillFailWithNamedRules(): void
    {
        // (2) LA SEULE EXCEPTION — rule-vs-rule SANS verrou : coach indispo vendredi + vendredi imposé
        // + gymnase ouvert vendredi seulement → la génération ÉCHOUE, en NOMMANT les règles. La
        // réservation est la SEULE exception ; deux règles contradictoires ne produisent jamais un
        // planning. Angle VOULU côte à côte avec (1) (point sensible solveur) ; promesse jumelle
        // prouvée aussi par {@see InfeasibilityNamesConflictingRulesTest}.
        $result = $this->solve($this->ruleVsRulePayload());

        self::assertSame('failed', $result['status'], 'sans verrou en cause, deux règles contradictoires doivent ÉCHOUER');
        self::assertEmpty($this->sacrificed($result), 'aucune règle « sacrifiée » sans verrou souverain — la réservation est la SEULE exception');

        $infeasible = $this->diagnostic($result, 'diag-infeasible');
        self::assertNotNull($infeasible, 'l\'échec sans verrou doit NOMMER les règles en conflit (diag-infeasible)');
        $message = (string) ($infeasible['message'] ?? '');
        self::assertStringContainsString('Indispo du vendredi', $message, 'la règle d\'indispo coach doit être nommée : ' . $message);
        self::assertStringContainsString('Vendredi imposé', $message, 'la règle de jour imposé doit être nommée : ' . $message);
    }

    /**
     * Bloc {t1,t2} (1 séance commune) : UNIQUE case candidate = COMMON_VENUE mercredi 18:00 (cap 2).
     * Avec `withReservation`, une équipe TIERCE (t3) est épinglée HARD sur cette case : la capacité
     * restante ne permet plus de réunir t1 ET t2 → le bloc n'a plus nulle part où co-localiser ses
     * membres (vérifié INFEASIBLE aujourd'hui, mêmes conditions que
     * {@see SharedBlockCoachRelayGateTest} P4-322).
     *
     * @return array<string, mixed>
     */
    private function blockPayload(bool $withReservation): array
    {
        $teams = [$this->team('t1'), $this->team('t2')];
        $slotTemplates = [];

        if ($withReservation) {
            $teams[] = $this->team('t3');
            $slotTemplates[] = [
                'id' => 'resa-common',
                'teamId' => 't3',
                'venueId' => self::COMMON_VENUE,
                'dayOfWeek' => self::WEDNESDAY,
                'startTime' => '18:00',
                'durationMinutes' => 90,
                'lockLevel' => 'HARD',
            ];
        }

        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-sovereign-block',
            'seasonId' => 'season-sovereign-block',
            'solverSeed' => 42,
            'teams' => $teams,
            'venues' => [
                [
                    'id' => self::COMMON_VENUE,
                    'name' => 'V-common',
                    'trainingSlots' => [['dayOfWeek' => self::WEDNESDAY, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 2]],
                ],
            ],
            'coaches' => [],
            'constraints' => [],
            'slotTemplates' => $slotTemplates,
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]],
        ];
    }

    /**
     * Rule-vs-rule SANS verrou : une équipe, son coach REQUIS indisponible le vendredi, un gymnase
     * ouvert le vendredi SEULEMENT, et la règle de jour imposé (vendredi). Aucun slotTemplate.
     *
     * @return array<string, mixed>
     */
    private function ruleVsRulePayload(): array
    {
        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-only-exception',
            'seasonId' => 'season-only-exception',
            'solverSeed' => 42,
            'teams' => [['id' => 'U21M1', 'name' => 'U21M1', 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 1]],
            'venues' => [[
                'id' => self::COMMON_VENUE,
                'name' => 'Gymnase A',
                'trainingSlots' => [['dayOfWeek' => 5, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1]],
            ]],
            'coaches' => [['id' => 'coach-marc', 'firstName' => 'Marc', 'lastName' => 'D.']],
            'constraints' => [
                [
                    'id' => 'link',
                    'type' => 'TEAM_COACH',
                    'teamId' => 'U21M1',
                    'metadata' => ['coachId' => 'coach-marc', 'role' => 'MAIN', 'isRequired' => true],
                    'isActive' => true,
                ],
                [
                    'id' => 'day-1',
                    'scope' => 'TEAM',
                    'scopeTargetId' => 'U21M1',
                    'family' => 'DAY',
                    'ruleType' => 'HARD',
                    'name' => 'Vendredi imposé',
                    'config' => ['forcedDays' => [5]],
                    'sortOrder' => 0,
                    'isActive' => true,
                ],
                [
                    'id' => 'coach-1',
                    'scope' => 'COACH',
                    'scopeTargetId' => 'coach-marc',
                    'family' => 'COACH_AVAILABILITY',
                    'ruleType' => 'HARD',
                    'name' => 'Indispo du vendredi',
                    'config' => ['unavailableDays' => [5]],
                    'sortOrder' => 0,
                    'isActive' => true,
                ],
            ],
            'slotTemplates' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function team(string $id): array
    {
        return ['id' => $id, 'name' => strtoupper($id), 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 1];
    }

    /**
     * Les diagnostics « règle sacrifiée ».
     *
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private function sacrificed(array $result): array
    {
        return array_values(array_filter(
            $result['diagnostics'] ?? [],
            static fn (array $d): bool => 'rule_sacrificed' === ($d['type'] ?? null),
        ));
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>|null
     */
    private function diagnostic(array $result, string $id): ?array
    {
        foreach ($result['diagnostics'] ?? [] as $diagnostic) {
            if (\is_array($diagnostic) && ($diagnostic['id'] ?? null) === $id) {
                return $diagnostic;
            }
        }

        return null;
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
