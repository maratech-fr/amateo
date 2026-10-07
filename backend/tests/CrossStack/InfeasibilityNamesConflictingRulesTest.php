<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * P4-96 — NR BLOQUANT (axes §7.1 *constraint semantics* + *backend↔engine contract*) : quand le
 * moteur dit NON, il NOMME les règles en conflit. De bout en bout contre le VRAI moteur
 * (`/generate`, contrat 1.4) : deux contraintes SOURCE qui se contredisent — coach INDISPONIBLE le
 * vendredi + équipe dont le vendredi est IMPOSÉ, sur un gymnase qui n'ouvre QUE le vendredi — font
 * sortir la génération INFEASIBLE (`status: "failed"`), et le diagnostic `diag-infeasible` CITE les
 * deux règles par leur libellé (comme dans l'écran de contraintes) via les hypothèses CP-SAT
 * (`SufficientAssumptionsForInfeasibility`) ; ses `causes[]` portent les kinds DÉDIÉS contrat 1.4
 * (`day_forced` pour le jour imposé, `coach_unavailability` pour l'indispo).
 *
 * TÉMOIN falsifiable : SANS la règle d'indispo coach, le MÊME payload redevient résoluble
 * (`completed`) — c'est bien le CONFLIT des deux règles qui est prouvé, pas un modèle vide.
 *
 * Avant P4-96 le message était générique (« contraintes impossibles à satisfaire toutes
 * ensemble ») et `causes` restait vide. Garde l'invariant ADR-0001 : INFEASIBLE échoue
 * BRUYAMMENT, jamais un repli relaxé silencieux.
 *
 * Étape NOMMÉE du job `blocking-tests` de `.github/workflows/ci.yml` (qui démarre déjà php-fpm +
 * engine) ET ligne de `docs/testing/blocking-tests.md` — gardé par `BlockingTestsListMatchesCiTest`.
 * Groupe `phase1` pour `--group phase1` ; `contract` pour le job engine-semantics. Payload construit
 * À LA MAIN (aucune base lue, comme `ConstraintKeysAreHonouredByEngineTest`) ; skip propre si le
 * moteur est absent, mêmes conventions que les autres tests de contrat cross-stack.
 */
#[Group('phase1')]
#[Group('contract')]
final class InfeasibilityNamesConflictingRulesTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';
    private const string TEAM = 'U21M1';
    private const string COACH = 'coach-marc';
    private const string VENUE = 'gymA';
    private const int FRIDAY = 5;
    private const string DAY_RULE = 'Vendredi imposé';
    private const string COACH_RULE = 'Indispo du vendredi';

    public function testInfeasibilityNamesBothConflictingRules(): void
    {
        $result = $this->solve($this->payload(withCoachUnavailability: true));

        self::assertSame('failed', $result['status'], 'deux règles dures contradictoires doivent sortir INFEASIBLE (failed)');

        $infeasible = $this->diagnostic($result, 'diag-infeasible');
        self::assertNotNull($infeasible, 'un diagnostic diag-infeasible doit être émis sur INFEASIBLE');

        // D1 — le message CITE les deux règles par leur libellé (écran de contraintes).
        $message = (string) ($infeasible['message'] ?? '');
        self::assertStringContainsString('se contredisent', $message, 'message générique inattendu : ' . $message);
        self::assertStringContainsString(self::DAY_RULE, $message, 'la règle de jour imposé doit être nommée : ' . $message);
        self::assertStringContainsString(self::COACH_RULE, $message, 'la règle d\'indispo coach doit être nommée : ' . $message);

        // D1 — causes STRUCTURÉES, kinds DÉDIÉS du contrat 1.4, les deux familles présentes.
        $causes = \is_array($infeasible['causes'] ?? null) ? $infeasible['causes'] : [];
        $kinds = array_column($causes, 'kind');
        self::assertContains('day_forced', $kinds, 'le jour imposé doit porter le kind dédié day_forced : ' . json_encode($causes));
        self::assertContains('coach_unavailability', $kinds, 'l\'indispo coach doit figurer dans les causes : ' . json_encode($causes));
        $labels = array_column($causes, 'label');
        self::assertContains(self::DAY_RULE, $labels, 'le libellé du jour imposé doit être porté par sa cause');
        self::assertContains(self::COACH_RULE, $labels, 'le libellé de l\'indispo coach doit être porté par sa cause');
    }

    public function testWithoutTheCoachRuleTheSamePayloadStaysFeasible(): void
    {
        // TÉMOIN — on retire la SEULE règle d'indispo coach : le vendredi redevient jouable et la
        // génération aboutit. Sans ce témoin, un scénario déjà infaisable pour une autre raison
        // passerait au vert sans rien prouver du CONFLIT des deux règles.
        $result = $this->solve($this->payload(withCoachUnavailability: false));

        self::assertSame('completed', $result['status'], 'témoin cassé : sans l\'indispo coach, le vendredi imposé doit rester résoluble');
        self::assertNull($this->diagnostic($result, 'diag-infeasible'), 'aucun diagnostic d\'infaisabilité ne doit subsister sans le conflit');
    }

    /**
     * Payload minimal : une équipe, son coach REQUIS, un gymnase ouvert UNIQUEMENT le vendredi, et
     * la règle de jour imposé (vendredi). La règle d'indispo coach (vendredi) est ajoutée au choix.
     *
     * @return array<string, mixed>
     */
    private function payload(bool $withCoachUnavailability): array
    {
        $constraints = [
            [
                'id' => 'link',
                'type' => 'TEAM_COACH',
                'teamId' => self::TEAM,
                'metadata' => ['coachId' => self::COACH, 'role' => 'MAIN', 'isRequired' => true],
                'isActive' => true,
            ],
            [
                'id' => 'day-1',
                'scope' => 'TEAM',
                'scopeTargetId' => self::TEAM,
                'family' => 'DAY',
                'ruleType' => 'HARD',
                'name' => self::DAY_RULE,
                'config' => ['forcedDays' => [self::FRIDAY]],
                'sortOrder' => 0,
                'isActive' => true,
            ],
        ];

        if ($withCoachUnavailability) {
            $constraints[] = [
                'id' => 'coach-1',
                'scope' => 'COACH',
                'scopeTargetId' => self::COACH,
                'family' => 'COACH_AVAILABILITY',
                'ruleType' => 'HARD',
                'name' => self::COACH_RULE,
                'config' => ['unavailableDays' => [self::FRIDAY]],
                'sortOrder' => 0,
                'isActive' => true,
            ];
        }

        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-proof',
            'seasonId' => 'season-proof',
            'solverSeed' => 42,
            'teams' => [['id' => self::TEAM, 'name' => self::TEAM, 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 1]],
            'venues' => [[
                'id' => self::VENUE,
                'name' => 'Gymnase A',
                'trainingSlots' => [['dayOfWeek' => self::FRIDAY, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1]],
            ]],
            'coaches' => [['id' => self::COACH, 'firstName' => 'Marc', 'lastName' => 'D.']],
            'constraints' => $constraints,
            'slotTemplates' => [],
        ];
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
