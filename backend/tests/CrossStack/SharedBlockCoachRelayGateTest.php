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
 * De bout en bout contre le VRAI moteur (`/generate`, contrat 1.5), payload construit à la main :
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

        // Lot 3B PR B — l'échec NOMME désormais le groupe : un shared_block_not_honored cite t1 et t2
        // et porte les indisponibilités de coach en cause (kind coach_unavailability, contrat 1.5),
        // de quoi composer l'origine côté backend. Sans le rail de preuve, l'infaisabilité
        // retomberait au message générique muet.
        $named = $this->notHonoured($noCoach);
        self::assertNotEmpty($named, 'PR B : l\'échec d\'un bloc sans coach de l\'union doit NOMMER le groupe');
        self::assertStringContainsString('T1', (string) $named[0]['message']);
        self::assertStringContainsString('T2', (string) $named[0]['message']);
        $causeKinds = array_column($named[0]['causes'] ?? [], 'kind');
        self::assertContains(
            'coach_unavailability',
            $causeKinds,
            'PR B : les indisponibilités de coach en cause doivent être portées en causes[] (kind existant)',
        );
    }

    /**
     * P4-322 — le même bloc, infaisable pour DEUX raisons distinctes. De bout en bout contre le VRAI
     * moteur, aucun bump de contrat :
     *   (1) un VERROU HARD d'une équipe NON membre (t3) sur la seule case commune. ⚠ AMENDÉ le
     *       2026-10-30 (GO fondateur, « la réservation gagne envers tout — ADR-0001 amendée ») : TOUT
     *       verrou HARD est désormais SOUVERAIN (réservation comme épingle manuelle, l'origine n'est
     *       PAS transmise au moteur). Le verrou est donc PRODUIT et le bloc qu'il rend insatisfiable
     *       est SACRIFIÉ et NOMMÉ (``rule_sacrificed``) — là où, avant la réservation souveraine,
     *       la génération sortait `failed` (INFEASIBLE, `shared_block_not_honored` cause `hard_lock`).
     *   (2) une FENÊTRE HORAIRE dure d'un membre (t1, début au plus tard 18:00) qui exclut la seule
     *       case (20:00), SANS aucun verrou : rule-vs-grille, aucune réservation en cause → la
     *       génération reste `failed` et NOMME la règle (``time_window``). C'est le pendant de la
     *       règle fondateur « la réservation est la SEULE exception » : sans verrou, l'échec demeure.
     */
    public function testNonMemberLockAndHardTimeWindowNameTheGroup(): void
    {
        // (1) VERROU non-membre — V ouvert lundi 18:00 (capacité 1), t3 (hors bloc) épinglé HARD là.
        $lockPayload = [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-block-lock',
            'seasonId' => 'season-block-lock',
            'solverSeed' => 42,
            'teams' => [$this->team('t1'), $this->team('t2'), $this->team('t3')],
            'venues' => [$this->oneSlotVenue('22222222-2222-4222-8222-222222222222', 1, '18:00')],
            'coaches' => [],
            'constraints' => [],
            'slotTemplates' => [$this->hardLock('t3', '22222222-2222-4222-8222-222222222222', 1, '18:00')],
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]],
        ];
        // ADR-0001 amendée (GO fondateur 2026-10-30) : le verrou non-membre t3 est SOUVERAIN → le
        // planning est PRODUIT et le bloc rendu insatisfiable est SACRIFIÉ et NOMMÉ.
        $produced = $this->solve($lockPayload);
        self::assertSame('completed', $produced['status'], 'un verrou HARD est souverain : le planning est PRODUIT (ADR-0001 amendée 30/10)');
        $sacrificed = $this->sacrificed($produced);
        self::assertNotEmpty($sacrificed, 'P4-322 amendé : le bloc rendu insatisfiable par le verrou non-membre doit être SACRIFIÉ et NOMMÉ (rule_sacrificed)');
        self::assertSame('WARNING', $sacrificed[0]['severity'] ?? null, 'un sacrifice est une ALERTE, pas une erreur bloquante');
        self::assertStringContainsString('T1', (string) $sacrificed[0]['message'], 'la règle sacrifiée (le bloc) nomme le groupe');
        self::assertStringContainsString('T2', (string) $sacrificed[0]['message'], 'la règle sacrifiée (le bloc) nomme le groupe');

        // (2) FENÊTRE HORAIRE dure — V ouvert lundi 20:00, t1 ne peut pas commencer après 18:00.
        $windowPayload = [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-block-window',
            'seasonId' => 'season-block-window',
            'solverSeed' => 42,
            'teams' => [$this->team('t1'), $this->team('t2')],
            'venues' => [$this->oneSlotVenue('33333333-3333-4333-8333-333333333333', 1, '20:00')],
            'coaches' => [],
            'constraints' => [$this->hardTimeWindow('win-late', 't1', '18:00')],
            'slotTemplates' => [],
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]],
        ];
        $windowed = $this->solve($windowPayload);
        self::assertSame('failed', $windowed['status'], 'la fenêtre horaire ferme la seule case commune');
        $windowDiag = $this->firstWithCauseKind($this->notHonoured($windowed), 'time_window');
        self::assertNotNull($windowDiag, 'P4-322 : une fenêtre horaire dure doit NOMMER le groupe (cause time_window)');
        self::assertStringContainsString('T2', (string) $windowDiag['message'], 'le groupe est nommé');
        // Q3 : le message NOMME l'équipe dont la fenêtre ferme les cases (t1 → « T1 ») ET le libellé.
        self::assertStringContainsString('« fenêtre horaire t1 » de T1', (string) $windowDiag['message']);
        $timeCause = $this->firstCauseOfKind($windowDiag, 'time_window');
        self::assertSame('win-late', $timeCause['constraintId'] ?? null, 'la contrainte est portée pour composer son origine');
        self::assertSame('fenêtre horaire t1', $timeCause['label'] ?? null, 'le libellé de la règle est porté');
    }

    /**
     * Le premier diagnostic de bloc portant une cause du ``$kind`` donné, ou ``null``.
     *
     * @param list<array<string, mixed>> $diagnostics
     *
     * @return array<string, mixed>|null
     */
    private function firstWithCauseKind(array $diagnostics, string $kind): ?array
    {
        foreach ($diagnostics as $diagnostic) {
            if (\in_array($kind, array_column($diagnostic['causes'] ?? [], 'kind'), true)) {
                return $diagnostic;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $diagnostic
     *
     * @return array<string, mixed>
     */
    private function firstCauseOfKind(array $diagnostic, string $kind): array
    {
        foreach ($diagnostic['causes'] ?? [] as $cause) {
            if (\is_array($cause) && ($cause['kind'] ?? null) === $kind) {
                return $cause;
            }
        }

        return [];
    }

    /**
     * Un gymnase avec UN unique créneau (capacité 1).
     *
     * @return array<string, mixed>
     */
    private function oneSlotVenue(string $id, int $day, string $start): array
    {
        return [
            'id' => $id, 'name' => 'V-' . substr($id, 0, 4),
            'trainingSlots' => [['dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => 90, 'capacity' => 1]],
        ];
    }

    /**
     * Un verrou HARD (``slotTemplates``) posé sur une équipe précise.
     *
     * @return array<string, mixed>
     */
    private function hardLock(string $teamId, string $venueId, int $day, string $start): array
    {
        return [
            'id' => \sprintf('lock-%s-%d-%s', $teamId, $day, $start),
            'teamId' => $teamId,
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'startTime' => $start,
            'durationMinutes' => 90,
            'lockLevel' => 'HARD',
        ];
    }

    /**
     * Une fenêtre horaire dure (famille TIME) : l'équipe ne peut pas COMMENCER après ``$maxStart``.
     *
     * @return array<string, mixed>
     */
    private function hardTimeWindow(string $id, string $teamId, string $maxStart): array
    {
        return [
            'id' => $id,
            'scope' => 'TEAM',
            'scopeTargetId' => $teamId,
            'family' => 'TIME',
            'ruleType' => 'HARD',
            'name' => 'fenêtre horaire ' . $teamId,
            'config' => ['maxStartTime' => $maxStart],
            'sortOrder' => 0,
            'isActive' => true,
        ];
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
     * Diagnostics « règle sacrifiée » (réservation souveraine, ADR-0001 amendée 2026-10-30).
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
