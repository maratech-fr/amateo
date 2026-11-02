<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\PreSolvePreventionWarnings;
use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Lot 3B PR B' — LE GARDE de parité du MIROIR « groupe sans case commune servable »
 * ({@see PreSolvePreventionWarnings::detect}), même doctrine que {@see CapacityMirrorParityTest} :
 * une partie de l'algèbre du moteur est RÉPLIQUÉE en PHP pour PRÉVENIR au récap, AVANT de générer.
 * Deux copies de la même règle = un jour quelqu'un change l'une sans l'autre et LE RÉCAP MENT.
 *
 * La règle répliquée : une séance de bloc est servable sur une case dès qu'UN coach de l'UNION des
 * coachs principaux de ses membres y est disponible (« un coach suffit », Q4B — PR A, moteur). Un
 * bloc dont les cases servables sont STRICTEMENT moins nombreuses que ses séances communes est
 * INFEASIBLE côté moteur ; le miroir doit alors AVERTIR, et SEULEMENT alors.
 *
 * Falsifiable dans les deux sens sur le MÊME scénario, bascule coordonnée :
 *   - coachs dispo un AUTRE jour que l'unique créneau → le miroir AVERTIT et le moteur sort `failed` ;
 *   - coachs dispo LE jour du créneau → le miroir SE TAIT et le moteur sort `completed`.
 * Si l'une des deux copies dérive, une assertion rougit.
 *
 * Même régime que les autres gates cross-stack : moteur réel sur le réseau docker, skip propre s'il
 * est indisponible. Tourne dans le job « Engine semantics » (groupe `contract`) ET comme step
 * bloquant (groupe `phase1`).
 */
#[Group('phase1')]
#[Group('contract')]
final class SharedBlockPreSolveMirrorParityTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';

    private PreSolvePreventionWarnings $warnings;

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function forcedDayClosers(): iterable
    {
        yield 'indisponibilité' => [['id' => 'off', 'scope' => 'COACH', 'scopeTargetId' => 'cA', 'family' => 'COACH_AVAILABILITY', 'ruleType' => 'HARD', 'name' => 'Indispo', 'config' => ['unavailableDays' => [4]], 'sortOrder' => 0, 'isActive' => true]];
        yield 'fenêtre' => [['id' => 'win', 'scope' => 'TEAM', 'scopeTargetId' => 't1', 'family' => 'TIME', 'ruleType' => 'HARD', 'name' => 'Pas avant 20h', 'config' => ['minStartTime' => '20:00'], 'sortOrder' => 0, 'isActive' => true]];
    }

    /** @return iterable<string, array{list<int>, int, list<array{string, int}>, list<int>, string}> */
    public static function partlyPinnedBlocks(): iterable
    {
        yield '1 case réservée et aucun créneau' => [[2, 3], 2, [['t1', 2], ['t2', 2]], [4], '1 case réservée et aucun créneau'];
        yield '1 case réservée et seulement 1 créneau' => [[2, 3, 4], 3, [['t1', 2], ['t2', 2]], [3], '1 case réservée et seulement 1 créneau'];
        yield 'un seul membre réservé, case sans coach' => [[2], 1, [['t1', 2]], [3], 'aucun créneau'];
    }

    /** NR (axe constraint semantics) : jeudi fermé par une fenêtre, vendredi par l'indisponibilité : le vendredi se relaie, le miroir ne bloque pas ET le moteur produit le planning. */
    public function testAForcedDayMixingAWindowAndAnAbsenceIsNotBlockedAndCompletes(): void
    {
        $payload = $this->forcedDayPayload(['id' => 'win', 'scope' => 'TEAM', 'scopeTargetId' => 't1', 'family' => 'TIME', 'ruleType' => 'HARD', 'name' => 'Pas avant 20h', 'config' => ['minStartTime' => '20:00'], 'sortOrder' => 0, 'isActive' => true], withGroup: true);
        $venue = $payload['venues'][0];
        $late = $venue['trainingSlots'][1];
        $venue['trainingSlots'][] = [...$late, 'dayOfWeek' => 5, 'startTime' => '20:30:00'];
        $payload['venues'] = [$venue];
        foreach ($payload['constraints'] as $index => $constraint) {
            if ('forced' === $constraint['id']) {
                $payload['constraints'][$index]['config'] = ['forcedDays' => [4, 5]];
            }
        }
        $payload['constraints'][] = ['id' => 'off', 'scope' => 'COACH', 'scopeTargetId' => 'cA', 'family' => 'COACH_AVAILABILITY', 'ruleType' => 'HARD', 'name' => 'Indispo', 'config' => ['unavailableDays' => [5]], 'sortOrder' => 0, 'isActive' => true];

        self::assertSame([], $this->warnings->detectBlockers($payload), 'PARITÉ ROMPUE : le miroir bloque un jour que le relais du groupe tient.');
        self::assertSame('completed', $this->solve($payload)['status'], 'PARITÉ ROMPUE : le miroir laisse passer mais le moteur échoue.');
    }

    public function testBlockWithoutUnionCoachIsWarnedAndInfeasibleWhileServableBlockIsSilentAndCompleted(): void
    {
        // (1) MIROIR AVERTIT ⇒ MOTEUR INFEASIBLE — gymnase ouvert le mardi (jour 2) SEUL ; cA (t1) et
        // cB (t2) disponibles le mercredi (jour 3) seulement : aucun coach de l'union le mardi. Le
        // bloc n'a aucune case servable → le moteur ferme sa séance (Σb ne vaut pas commonSessions).
        $infeasible = $this->payload(venueDays: [2], aDays: [3], bDays: [3]);
        $warned = $this->blockWarnings($this->warnings->detectBlockers($infeasible));
        self::assertNotEmpty($warned, 'le miroir doit AVERTIR : aucun créneau n\'a de coach de l\'union pour le bloc');
        self::assertSame(
            'failed',
            $this->solve($infeasible)['status'],
            'PARITÉ ROMPUE : le miroir annonce un bloc inservable mais le moteur a résolu — '
            . 'son algèbre « un coach suffit » pour les blocs a changé. '
            . 'Aligner App\Service\PreSolvePreventionWarnings, sinon le récap crie à tort (ou se tait à tort).',
        );

        // (2) TÉMOIN — MIROIR SILENCIEUX ⇒ MOTEUR COMPLETED : les MÊMES coachs disponibles le mardi
        // (jour du créneau). Le bloc est servable ; les DEUX copies basculent ensemble.
        $feasible = $this->payload(venueDays: [2], aDays: [2], bDays: [2]);
        self::assertSame(
            [],
            $this->blockWarnings($this->warnings->detectBlockers($feasible)),
            'PARITÉ ROMPUE : le miroir avertit alors que le bloc est servable (coach de l\'union le mardi).',
        );
        self::assertSame(
            'completed',
            $this->solve($feasible)['status'],
            'témoin : un bloc servé par un coach de l\'union doit être résolu par le moteur',
        );
    }

    /**
     * NR (P4-319) — « un coach suffit » PAR ÉQUIPE : une équipe membre du bloc peut avoir
     * PLUSIEURS coachs principaux, et le second peut à lui seul rendre la case servable. Le
     * miroir réunit la LISTE des principaux de chaque membre ({@see PreSolvePreventionWarnings}
     * `teamMainCoaches`) ; cette parité épingle que le moteur fait de même.
     *
     * Bascule coordonnée sur le MÊME gymnase (ouvert mardi seul), t1 ayant deux principaux :
     *   - le 2ᵉ principal de t1 couvre le mardi → miroir SILENCIEUX et moteur `completed` ;
     *   - aucun principal (de t1 NI de t2) ne couvre le mardi → miroir AVERTIT et moteur `failed`.
     * Une régression qui ne garderait qu'un principal par équipe romprait l'une des deux.
     */
    public function testBlockServableThroughATeamsSecondMainCoachMatchesTheEngine(): void
    {
        // t1 : cA1 (mercredi) + cA2 (mardi) ; t2 : cB (mercredi). Seul cA2 couvre la case du mardi.
        $feasible = $this->payloadTwoCoachesOnFirstTeam(venueDays: [2], a1Days: [3], a2Days: [2], bDays: [3]);
        self::assertSame(
            [],
            $this->blockWarnings($this->warnings->detectBlockers($feasible)),
            'PARITÉ ROMPUE : le miroir avertit alors que le 2ᵉ coach principal de t1 couvre la case du mardi.',
        );
        self::assertSame(
            'completed',
            $this->solve($feasible)['status'],
            'témoin : un bloc servi par le 2ᵉ coach principal d\'un membre doit être résolu par le moteur',
        );

        // Le 2ᵉ principal de t1 bascule au mercredi lui aussi : plus aucun coach de l'union le mardi.
        $infeasible = $this->payloadTwoCoachesOnFirstTeam(venueDays: [2], a1Days: [3], a2Days: [3], bDays: [3]);
        self::assertNotEmpty(
            $this->blockWarnings($this->warnings->detectBlockers($infeasible)),
            'le miroir doit AVERTIR : aucun coach de l\'union n\'est disponible le mardi',
        );
        self::assertSame(
            'failed',
            $this->solve($infeasible)['status'],
            'PARITÉ ROMPUE : le miroir annonce un bloc inservable mais le moteur a résolu.',
        );
    }

    /**
     * NR (groupe réservé) — un groupe réservé entier, sans coach au début, n'est pas un échec : le miroir
     * se tait ET le moteur produit le planning en nommant l'écart. Le TÉMOIN du scénario sans réservation
     * (miroir avertit, moteur `failed`) est {@see testBlockWithoutUnionCoachIsWarnedAndInfeasibleWhileServableBlockIsSilentAndCompleted}.
     */
    public function testAFullyReservedBlockIsNeitherAnnouncedAsFailureNorFailed(): void
    {
        $payload = [
            ...$this->payload(venueDays: [2], aDays: [3], bDays: [3]),
            'slotTemplates' => [$this->pin('t1', 2), $this->pin('t2', 2)],
        ];

        self::assertSame(
            [],
            array_values(array_filter($this->warnings->detectBlockers($payload), static fn (string $w): bool => str_contains($w, 'Groupe'))),
            'PARITÉ ROMPUE : le miroir annonce un échec pour un groupe entièrement réservé.',
        );
        $result = $this->solve($payload);
        self::assertSame('completed', $result['status'], 'PARITÉ ROMPUE : le miroir se tait mais le moteur échoue.');
        self::assertContains('rule_sacrificed', array_column($result['diagnostics'], 'type'), 'la réservation gagne, et l\'écart est nommé');
    }

    /** NR (groupe réservé) — plus de cases réservées que de séances communes : le miroir avertit ET le moteur échoue. */
    public function testAnOverpinnedBlockIsWarnedAndFails(): void
    {
        $payload = [
            ...$this->payload(venueDays: [2, 3], aDays: [2, 3], bDays: [2, 3]),
            'slotTemplates' => [$this->pin('t1', 2), $this->pin('t2', 2), $this->pin('t1', 3), $this->pin('t2', 3)],
        ];

        self::assertNotEmpty(
            array_filter($this->warnings->detectBlockers($payload), static fn (string $w): bool => str_contains($w, 'réservé sur 2 cases')),
            'le miroir doit AVERTIR : ces réservations se contredisent',
        );
        self::assertSame('failed', $this->solve($payload)['status'], 'PARITÉ ROMPUE : le miroir annonce un échec mais le moteur a résolu.');
    }

    /** NR (groupe réservé) — deux groupes imbriqués réunis par une seule case réservée : le miroir avertit ET le moteur échoue. */
    public function testNestedBlocksOnOneReservedCaseAreWarnedAndFail(): void
    {
        $payload = [
            ...$this->payload(venueDays: [2], aDays: [2], bDays: [2]),
            'teams' => [$this->team('t1'), $this->team('t2'), $this->team('t3')],
            'sharedBlocks' => [
                ['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1],
                ['id' => 'c', 'teamIds' => ['t1', 't2', 't3'], 'commonSessions' => 1],
            ],
            'slotTemplates' => [$this->pin('t1', 2), $this->pin('t2', 2), $this->pin('t3', 2)],
        ];

        self::assertNotEmpty(
            array_filter($this->warnings->detectBlockers($payload), static fn (string $w): bool => str_contains($w, 'une seule case réservée')),
            'le miroir doit AVERTIR : une seule case pour deux séances communes',
        );
        self::assertSame('failed', $this->solve($payload)['status'], 'PARITÉ ROMPUE : le miroir annonce un échec mais le moteur a résolu.');
    }

    /**
     * NR (axe constraint semantics) : jour imposé vidé par l'indisponibilité du principal ou une fenêtre, équipe HORS de
     * tout groupe : le miroir BLOQUE et le moteur sort `failed`. Si l'une des deux copies dérive, le Récap bloquerait un
     * planning faisable, ou enverrait au moteur un échec certain.
     *
     * @param array<string, mixed> $closing
     */
    #[DataProvider('forcedDayClosers')]
    public function testAForcedDayEmptiedForATeamAloneBlocksAndFails(array $closing): void
    {
        $payload = $this->forcedDayPayload($closing, withGroup: false);

        self::assertNotSame([], $this->warnings->detectBlockers($payload), 'le miroir doit BLOQUER : aucune case ne tient ce jour');
        self::assertSame('failed', $this->solve($payload)['status'], 'PARITÉ ROMPUE : le miroir bloque mais le moteur a résolu.');
    }

    /** NR (axe constraint semantics) : même jour vidé pour un membre de groupe, un coach du groupe relaie : le miroir ne bloque pas ET le moteur produit le planning. */
    public function testAForcedDayEmptiedForAGroupMemberIsNotBlockedAndCompletes(): void
    {
        $payload = $this->forcedDayPayload(['id' => 'off', 'scope' => 'COACH', 'scopeTargetId' => 'cA', 'family' => 'COACH_AVAILABILITY', 'ruleType' => 'HARD', 'name' => 'Indispo', 'config' => ['unavailableDays' => [4]], 'sortOrder' => 0, 'isActive' => true], withGroup: true);

        self::assertSame([], $this->warnings->detectBlockers($payload), 'PARITÉ ROMPUE : le miroir bloque un jour que le relais du groupe tient.');
        self::assertSame('completed', $this->solve($payload)['status'], 'PARITÉ ROMPUE : le miroir laisse passer mais le moteur échoue.');
    }

    /** NR (axe constraint semantics) : jour vidé par une FENÊTRE sur un membre de groupe : aucun coach ne la lève, le miroir BLOQUE et le moteur échoue. */
    public function testAForcedDayEmptiedByAWindowForAGroupMemberBlocksAndFails(): void
    {
        $payload = $this->forcedDayPayload(['id' => 'win', 'scope' => 'TEAM', 'scopeTargetId' => 't1', 'family' => 'TIME', 'ruleType' => 'HARD', 'name' => 'Pas avant 20h', 'config' => ['minStartTime' => '20:00'], 'sortOrder' => 0, 'isActive' => true], withGroup: true);

        self::assertNotSame([], $this->warnings->detectBlockers($payload), 'le miroir doit BLOQUER : la fenêtre ferme le jour, quel que soit le coach');
        self::assertSame('failed', $this->solve($payload)['status'], 'PARITÉ ROMPUE : le miroir bloque mais le moteur a résolu.');
    }

    /**
     * NR (axe constraint semantics) : formes du groupe en partie réservé : le miroir BLOQUE et le moteur échoue. Le groupe
     * a un coach seulement les jours `$coachDays`.
     *
     * @param list<int>                $venueDays
     * @param list<array{string, int}> $pins
     * @param list<int>                $coachDays
     */
    #[DataProvider('partlyPinnedBlocks')]
    public function testAPartlyPinnedBlockThatCannotBeServedBlocksAndFails(array $venueDays, int $commonSessions, array $pins, array $coachDays, string $form): void
    {
        $payload = [
            ...$this->payload(venueDays: $venueDays, aDays: $coachDays, bDays: $coachDays),
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => $commonSessions]],
            'teams' => [$this->teamWithSessions('t1', $commonSessions), $this->teamWithSessions('t2', $commonSessions)],
            'slotTemplates' => array_map(fn (array $pin): array => $this->pin($pin[0], $pin[1]), $pins),
        ];

        self::assertNotSame([], array_filter($this->warnings->detectBlockers($payload), static fn (string $w): bool => str_contains($w, $form)), 'le miroir doit BLOQUER sous cette forme');
        self::assertSame('failed', $this->solve($payload)['status'], 'PARITÉ ROMPUE : le miroir bloque mais le moteur a résolu.');
    }

    protected function setUp(): void
    {
        $this->warnings = new PreSolvePreventionWarnings;
    }

    /**
     * Une réservation de l'équipe sur l'unique gymnase, ce jour à 18:00.
     *
     * @return array<string, mixed>
     */
    private function pin(string $teamId, int $day): array
    {
        return ['id' => 'pin-' . $teamId . '-' . $day, 'teamId' => $teamId, 'venueId' => '11111111-1111-4111-8111-111111111111', 'dayOfWeek' => $day, 'startTime' => '18:00', 'durationMinutes' => 90, 'lockLevel' => 'HARD'];
    }

    /**
     * Les avertissements du miroir qui concernent le bloc (marqueur stable « coach du groupe »).
     *
     * @param list<string> $warnings
     *
     * @return list<string>
     */
    private function blockWarnings(array $warnings): array
    {
        return array_values(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'coach du groupe')));
    }

    /**
     * Bloc {t1,t2} (1 séance commune), un gymnase capacité 1 ouvert les ``venueDays``, et deux
     * coachs requis (cA pour t1, cB pour t2) chacun disponible UNIQUEMENT ses ``aDays`` / ``bDays``.
     * MÊME forme que {@see SharedBlockCoachRelayGateTest} — la face moteur de la même règle.
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
            'clubId' => 'club-block-presolve-mirror',
            'seasonId' => 'season-block-presolve-mirror',
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

    /**
     * MÊME forme que {@see payload}, mais t1 a DEUX coachs principaux (cA1, cA2) et t2 un seul
     * (cB), chacun disponible UNIQUEMENT ses jours — pour la parité « un coach suffit PAR équipe ».
     *
     * @param list<int> $venueDays
     * @param list<int> $a1Days
     * @param list<int> $a2Days
     * @param list<int> $bDays
     *
     * @return array<string, mixed>
     */
    private function payloadTwoCoachesOnFirstTeam(array $venueDays, array $a1Days, array $a2Days, array $bDays): array
    {
        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-block-presolve-mirror',
            'seasonId' => 'season-block-presolve-mirror',
            'solverSeed' => 42,
            'teams' => [$this->team('t1'), $this->team('t2')],
            'venues' => [$this->venue('11111111-1111-4111-8111-111111111111', $venueDays)],
            'coaches' => [],
            'constraints' => [
                $this->teamCoach('tcA1', 't1', 'cA1'),
                $this->teamCoach('tcA2', 't1', 'cA2'),
                $this->teamCoach('tcB', 't2', 'cB'),
                $this->coachAvailable('avA1', 'cA1', $a1Days),
                $this->coachAvailable('avA2', 'cA2', $a2Days),
                $this->coachAvailable('avB', 'cB', $bDays),
            ],
            'slotTemplates' => [],
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]],
        ];
    }

    /**
     * t1 (cA) a le jeudi imposé, créneaux lundi et jeudi, capacité 2, la règle donnée ferme le jeudi. Avec groupe : t2 (cB) rejoint t1.
     *
     * @param array<string, mixed> $closing
     *
     * @return array<string, mixed>
     */
    private function forcedDayPayload(array $closing, bool $withGroup): array
    {
        $venue = $this->venue('11111111-1111-4111-8111-111111111111', [1, 4]);
        $venue['trainingSlots'] = array_map(static fn (array $slot): array => [...$slot, 'capacity' => 2], $venue['trainingSlots']);

        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-block-presolve-mirror',
            'seasonId' => 'season-block-presolve-mirror',
            'solverSeed' => 42,
            'teams' => $withGroup ? [$this->team('t1'), $this->team('t2')] : [$this->team('t1')],
            'venues' => [$venue],
            'coaches' => [],
            'constraints' => [
                $this->teamCoach('tcA', 't1', 'cA'),
                ...($withGroup ? [$this->teamCoach('tcB', 't2', 'cB')] : []),
                ['id' => 'forced', 'scope' => 'TEAM', 'scopeTargetId' => 't1', 'family' => 'DAY', 'ruleType' => 'HARD', 'name' => 'Jeudi imposé', 'config' => ['forcedDays' => [4]], 'sortOrder' => 0, 'isActive' => true],
                $closing,
            ],
            'slotTemplates' => [],
            'sharedBlocks' => $withGroup ? [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function teamWithSessions(string $id, int $sessions): array
    {
        return [...$this->team($id), 'sessionsPerWeek' => $sessions];
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
     * COACH_AVAILABILITY v2 — le coach n'est disponible QUE ces jours.
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
