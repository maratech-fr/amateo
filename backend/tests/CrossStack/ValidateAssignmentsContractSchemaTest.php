<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\EngineClient;
use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * NR de l'axe contrat backend⇄engine pour le TROISIÈME endpoint (P2-2 F2a,
 * contrat 2.4) : le jumeau de `ContractSchemaTest` pour `/validate-assignments`.
 *
 * Même dispositif que les deux autres gardes : la FORME du payload (groupe
 * phase1, sans engine, step bloquant) et le POST au VRAI moteur (groupe
 * contract, joué par `engine-semantics`). Le vocabulaire du contexte est CELUI
 * de `/generate` (on ne réinvente pas un dialecte) — c'est `ScheduleConstraintBuilder`
 * qui le produit — plus un `candidate` et une `version` recalée sur le contrat
 * courant. Un endpoint hors garde est une dérive future garantie.
 */
final class ValidateAssignmentsContractSchemaTest extends TestCase
{
    private const CLUB_ID = '11111111-1111-1111-1111-111111111111';

    private const SEASON_ID = '22222222-2222-2222-2222-222222222222';

    /** Lue par réflexion sur le client réel — jamais une copie (cf. D-17). */
    private static function validateUrl(): string
    {
        $url = new ReflectionClass(EngineClient::class)->getConstant('VALIDATE_URL');
        self::assertIsString($url);

        return $url;
    }

    #[Group('phase1')]
    public function testPhase1PayloadShapeIsValidWithoutEngine(): void
    {
        $payload = $this->buildPayload();

        $capturedPayload = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedPayload): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::validateUrl(), $url);
            self::assertArrayHasKey('body', $options);
            self::assertIsString($options['body']);
            $capturedPayload = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse('{"valid":false,"violations":[{"rule":"slot_unavailable","message":"x"}],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}', [
                'http_code' => 200,
            ]);
        });

        $response = $client->request('POST', self::validateUrl(), ['json' => $payload]);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($capturedPayload);
        self::assertSame($payload, $capturedPayload);

        $this->assertPayloadShape($capturedPayload);
    }

    #[Group('contract')]
    public function testContractPostsToRealEngineOrSkipsWhenUnavailable(): void
    {
        $payload = $this->buildPayload();
        $this->assertPayloadShape($payload);

        $client = HttpClient::create(['timeout' => 5]);

        try {
            $response = $client->request('POST', self::validateUrl(), ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            if ($this->isEngineUnavailable($exception)) {
                self::markTestSkipped('Engine not available');
            }

            throw $exception;
        }

        // Le contrat = la FORME de la réponse (pas un verdict précis) : le moteur
        // ACCEPTE le payload (schéma `extra="forbid"`) et rend valid/violations/metrics.
        self::assertIsBool($data['valid']);
        self::assertArrayHasKey('violations', $data);
        self::assertIsArray($data['violations']);
        // P2-32 — le verdict porte `compromises` (le DELTA de confort) ; forme = tableau, jamais
        // absent (vide sur un refus). Le contrat = la présence + le type, pas un verdict précis.
        self::assertArrayHasKey('compromises', $data);
        self::assertIsArray($data['compromises']);
        self::assertArrayHasKey('metrics', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $venue = (new Venue)
            ->setId('venue-1')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setName('Venue 1')
            ->setSource('manual')
            ->setIsActive(true);

        $coach = (new Coach)
            ->setId('coach-1')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setFirstName('Coach')
            ->setLastName('One')
            ->setIsActive(true);

        $team = (new Team)
            ->setId('team-1')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setSportCategoryId('sport-category-1')
            ->setPriorityTierId(1)
            ->setName('Team 1')
            ->setSessionsPerWeek(2)
            ->setIsActive(true);

        $constraint = (new Constraint)
            ->setId('constraint-1')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setName('Preferred slot')
            ->setScope(ConstraintScope::TEAM)
            ->setScopeTargetId($team->getId())
            ->setFamily(ConstraintFamily::TIME)
            ->setRuleType(ConstraintRuleType::PREFERRED)
            ->setConfig(['type' => 'preferred']);

        // Le CONTEXTE vient du vrai builder (« même vocabulaire que /generate »).
        $payload = new ScheduleConstraintBuilder(new NullLogger)->buildPayload(
            clubId: self::CLUB_ID,
            seasonId: self::SEASON_ID,
            venues: [$venue],
            teams: [$team],
            coaches: [$coach],
            constraints: [$constraint],
        );

        // Le bloc `implicitRules` (P2-28) fait partie du contrat de verdict : parité
        // génération ⇄ verdict — le schéma validate le porte (optionnel) et `MoveSlotService`
        // le transmet tel quel. Il reste donc dans le payload envoyé ici au moteur réel.

        // Recalage sur le contrat du nouvel endpoint : version courante, budget
        // COURT (le défaut /generate dépasse le plafond du schéma validate), et le
        // candidat — la seule chose libre.
        // La version se LIT du fichier source de vérité : un littéral y dérivait en
        // silence (le moteur ne compare que le MAJOR, le test restait vert à 2.10
        // alors que le contrat était passé à 2.11).
        $payload['version'] = $this->currentContractVersion();
        $payload['solverTimeoutSeconds'] = 2;
        // Contrat 2.18 — `candidates` est une LISTE (P2-51 PR-5b) : le verdict juge N déplacements
        // sous UN verdict. Ici un seul (le rail simple `MoveSlotService::move()`).
        $payload['candidates'] = [[
            'teamId' => 'team-1',
            'venueId' => 'venue-1',
            'dayOfWeek' => 4,
            'startTime' => '20:00',
            'durationMinutes' => 90,
        ]];
        // P2-32 — `references` (l'état « avant » de chaque candidat, même forme que `candidates`,
        // appariée par index) fait partie du contrat de verdict : `MoveSlotService::move()` la pose
        // pour le DELTA de compromis. Vide pour une création ; ici on prouve que le moteur RÉEL
        // l'accepte (schéma `extra="forbid"`).
        $payload['references'] = [[
            'teamId' => 'team-1',
            'venueId' => 'venue-1',
            'dayOfWeek' => 2,
            'startTime' => '18:00',
            'durationMinutes' => 90,
        ]];

        return $payload;
    }

    /** La source de vérité du contrat — le même fichier que `PayloadVersionMatchesContractVersionTest`. */
    private function currentContractVersion(): string
    {
        $raw = file_get_contents(__DIR__ . '/../../../engine/CONTRACT_VERSION');
        self::assertIsString($raw, 'engine/CONTRACT_VERSION illisible');

        return trim($raw);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertPayloadShape(array $payload): void
    {
        self::assertSame($this->currentContractVersion(), $payload['version']);
        self::assertSame(self::CLUB_ID, $payload['clubId']);
        self::assertSame(self::SEASON_ID, $payload['seasonId']);
        self::assertLessThanOrEqual(10, $payload['solverTimeoutSeconds']);

        foreach (['venues', 'teams', 'coaches', 'constraints', 'slotTemplates'] as $key) {
            self::assertArrayHasKey($key, $payload);
            self::assertIsArray($payload[$key]);
        }

        // Contrat 2.18 — `candidates` est une LISTE (un déplacement simple = une liste à 1 élément).
        self::assertArrayHasKey('candidates', $payload);
        self::assertIsArray($payload['candidates']);
        self::assertCount(1, $payload['candidates']);
        $candidate = $payload['candidates'][0];
        self::assertIsArray($candidate);
        foreach (['teamId', 'venueId', 'dayOfWeek', 'startTime', 'durationMinutes'] as $key) {
            self::assertArrayHasKey($key, $candidate);
        }
        self::assertSame('team-1', $candidate['teamId']);

        // P2-32 / 2.18 — `references` a la MÊME forme (liste appariée par index), l'état « avant ».
        self::assertArrayHasKey('references', $payload);
        self::assertIsArray($payload['references']);
        self::assertCount(1, $payload['references']);
        $reference = $payload['references'][0];
        self::assertIsArray($reference);
        foreach (['teamId', 'venueId', 'dayOfWeek', 'startTime', 'durationMinutes'] as $key) {
            self::assertArrayHasKey($key, $reference);
        }
    }

    private function isEngineUnavailable(TransportExceptionInterface $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'connection refused')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'could not connect')
            || str_contains($message, 'unable to connect');
    }
}
