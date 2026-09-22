<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\ApiResource\ScheduleDiagnosticResource;
use App\Dto\DiagnosticCause;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\DiagnosticMessageBuilder;
use App\Service\EngineClient;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleDiagnosticsRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class ContractSchemaTest extends TestCase
{
    private const CLUB_ID = '11111111-1111-1111-1111-111111111111';

    private const SEASON_ID = '22222222-2222-2222-2222-222222222222';

    /**
     * D-17 — cette constante était une COPIE de celle d'`EngineClient`, et le test
     * s'en servait pour poster PUIS pour vérifier l'URL reçue : une tautologie. Passer
     * `EngineClient::ENGINE_URL` à un autre port laissait ces assertions vertes.
     * Elle est désormais LUE sur le client réel — la seule façon d'en faire un garde.
     */
    private static function engineUrl(): string
    {
        $url = new ReflectionClass(EngineClient::class)->getConstant('ENGINE_URL');
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
            self::assertSame(self::engineUrl(), $url);
            self::assertArrayHasKey('body', $options);
            self::assertIsString($options['body']);
            $capturedPayload = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);

            // Mock aligné sur la VRAIE forme 2.6 : nb_conflicts est désormais réellement
            // émis par l'engine (extra=forbid l'interdisait avant P5-10), et les métriques
            // de capacité (total_wall_time_ms, cpu_time_ms, workers, budget_seconds,
            // solver_status_detail, peak_rss_mb, rss_before_mb, engine_wait_ms) l'accompagnent.
            return new MockResponse('{"status":"completed","score":0,"slots":[],"diagnostics":[],"metrics":{"solver_version":"test","nb_variables":0,"nb_constraints":0,"nb_conflicts":0,"wall_time_ms":0,"total_wall_time_ms":0,"cpu_time_ms":0,"workers":1,"budget_seconds":60,"solver_status_detail":"OPTIMAL","peak_rss_mb":128.0,"rss_before_mb":96.0,"engine_wait_ms":0}}', [
                'http_code' => 200,
            ]);
        });

        $response = $client->request('POST', self::engineUrl(), ['json' => $payload]);

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

        $client = HttpClient::create(['timeout' => 3]);

        try {
            $response = $client->request('POST', self::engineUrl(), ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            if ($this->isEngineUnavailable($exception)) {
                self::markTestSkipped('Engine not available');
            }

            throw $exception;
        }

        self::assertSame('completed', $data['status']);
        self::assertArrayHasKey('slots', $data);
        self::assertArrayHasKey('diagnostics', $data);
        self::assertArrayHasKey('metrics', $data);
    }

    /**
     * NR — axe §7.1 « backend↔engine contract » : la CAUSE mesurée émise par l'engine
     * (contrat 2.8, `session_below_effective_min.causes` + `openCandidates`) traverse l'import
     * du backend jusqu'à l'API, sans se perdre. Le mock engine renvoie le diagnostic ; le
     * recorder le PERSISTE (constraintId suffixé `:teamId` normalisé en UUID nu) ; la resource
     * l'EXPOSE. Sans cette garde, les deux champs restaient mort-nés (le recorder ne mappait
     * que ce qu'il connaissait) — l'objet même du lot.
     */
    #[Group('phase1')]
    public function testEngineSessionCausesArePersistedAndExposed(): void
    {
        // Le mock engine renvoie un `session_below_effective_min` portant la cause structurée +
        // openCandidates. Le constraintId est SUFFIXÉ `:team-1` comme le builder le produit en
        // éclatant une contrainte CLUB en N contraintes TEAM.
        $engineBody = json_encode([
            'status' => 'completed',
            'score' => 0,
            'slots' => [],
            'metrics' => ['solver_version' => 'test', 'nb_variables' => 0, 'nb_constraints' => 0, 'wall_time_ms' => 0],
            'diagnostics' => [[
                'type' => 'session_below_effective_min',
                'severity' => 'WARNING',
                'teamId' => 'team-1',
                'message' => 'Une séance manque à cette équipe.',
                'causes' => [
                    ['kind' => 'venue_forbidden', 'constraintId' => 'constraint-1:team-1', 'label' => 'Gymnase interdit', 'count' => 3],
                ],
                'openCandidates' => 2,
            ]],
        ], \JSON_THROW_ON_ERROR);

        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse($engineBody, ['http_code' => 200]));
        $result = $client->request('POST', self::engineUrl(), ['json' => $this->buildPayload()])->toArray(false);

        // Import : le recorder persiste. EM mocké (findBy => [] pour les name-maps), on capture
        // l'entité persistée — la persistance se PROUVE sur l'entité, pas via une DB ici.
        $captured = [];
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$captured): void {
            $captured[] = $entity;
        });

        $schedule = new Schedule;
        $schedule->setClubId(self::CLUB_ID)->setSeasonId(self::SEASON_ID);

        new ScheduleDiagnosticsRecorder($entityManager, new DiagnosticMessageBuilder)->record($schedule, $result);

        self::assertCount(1, $captured);
        $entity = $captured[0];
        self::assertInstanceOf(ScheduleDiagnostic::class, $entity);

        // Persistance : la cause survit, l'id suffixé est normalisé en UUID nu, openCandidates aussi.
        self::assertSame(2, $entity->getOpenCandidates());
        $causes = $entity->getCauses();
        self::assertCount(1, $causes);
        self::assertSame('venue_forbidden', $causes[0]['kind']);
        self::assertSame('constraint-1', $causes[0]['constraintId'], 'Le suffixe :team-1 est coupé — le deep-link wizard résoudra.');
        self::assertSame(3, $causes[0]['count']);

        // Exposition : la resource porte les deux champs. P4-101 — chaque cause est désormais
        // un OBJET `DiagnosticCause` et non plus un tableau nu : c'est ce typage qui rend le
        // snapshot OpenAPI juste (`$ref` au lieu d'un objet libre qui annonçait `count` en
        // string). La forme SÉRIALISÉE est identique — seule la description change — et cette
        // assertion garde le passage tableau → objet contre un retour en arrière silencieux.
        $dto = ScheduleDiagnosticResource::fromEntity($entity);
        self::assertSame(2, $dto->openCandidates);
        self::assertInstanceOf(DiagnosticCause::class, $dto->causes[0]);
        self::assertSame('venue_forbidden', $dto->causes[0]->kind);
        self::assertSame('constraint-1', $dto->causes[0]->constraintId);
        self::assertSame(3, $dto->causes[0]->count);
    }

    /**
     * NR — axes §7.1 « backend↔engine contract » + « constraint semantics » : les coordonnées du
     * créneau préféré déplacé (contrat 2.23, `soft_lock_moved.dayOfWeek`/`startTime`/
     * `durationMinutes`) traversent l'import du backend jusqu'à l'API, et le message français
     * reconstruit porte LEQUEL des créneaux a bougé (jour + plage horaire). Le mock engine émet
     * les champs (message vidé — un texte anglais y serait mort) ; le recorder PERSISTE les
     * colonnes jour/heure et bâtit le message FR ; la resource EXPOSE le tout. Sans EM réelle
     * (noms → ids), la preuve porte sur le jour + l'heure, l'objet du lot.
     */
    #[Group('phase1')]
    public function testEngineSoftLockMovedCoordinatesArePersistedAndExposed(): void
    {
        $engineBody = json_encode([
            'status' => 'completed',
            'score' => 0,
            'slots' => [],
            'metrics' => ['solver_version' => 'test', 'nb_variables' => 0, 'nb_constraints' => 0, 'wall_time_ms' => 0],
            'diagnostics' => [[
                'type' => 'soft_lock_moved',
                'severity' => 'WARNING',
                'teamId' => 'team-1',
                'venueId' => 'venue-1',
                'dayOfWeek' => 2,
                'startTime' => '18:00',
                'durationMinutes' => 90,
                'message' => '',
                'suggestions' => [],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $entity = $this->recordSingleDiagnostic($engineBody);
        self::assertInstanceOf(ScheduleDiagnostic::class, $entity);
        self::assertSame('soft_lock_moved', $entity->getType());

        // Persistance : les coordonnées entrent dans les colonnes dédiées (mapping générique).
        self::assertSame(2, $entity->getDayOfWeek());
        self::assertSame('18:00', $entity->getStartTime());

        // Le message FR reconstruit porte le jour et la plage horaire, l'anglais a disparu.
        self::assertSame(
            'Le créneau préféré de team-1 (venue-1, mardi de 18:00 à 19:30) a été déplacé par le solveur pour un meilleur ajustement global.',
            $entity->getMessage(),
        );

        // Exposition : la resource porte le message enrichi et les coordonnées.
        $dto = ScheduleDiagnosticResource::fromEntity($entity);
        self::assertSame(2, $dto->dayOfWeek);
        self::assertSame('18:00', $dto->startTime);
        self::assertStringContainsString('mardi de 18:00 à 19:30', $dto->message);
        self::assertStringNotContainsString('preferred slot', $dto->message);
    }

    /**
     * NR — tolérance : un moteur PLUS ANCIEN qui n'émet pas les coordonnées laisse le message
     * EXACTEMENT celui d'avant le lot (à l'octet près) et les colonnes jour/heure NULL.
     */
    #[Group('phase1')]
    public function testEngineSoftLockMovedWithoutCoordinatesKeepsTodaysMessage(): void
    {
        $engineBody = json_encode([
            'status' => 'completed',
            'score' => 0,
            'slots' => [],
            'metrics' => ['solver_version' => 'test', 'nb_variables' => 0, 'nb_constraints' => 0, 'wall_time_ms' => 0],
            'diagnostics' => [[
                'type' => 'soft_lock_moved',
                'severity' => 'WARNING',
                'teamId' => 'team-1',
                'venueId' => 'venue-1',
                'message' => 'The preferred slot for team was moved.',
            ]],
        ], \JSON_THROW_ON_ERROR);

        $entity = $this->recordSingleDiagnostic($engineBody);
        self::assertInstanceOf(ScheduleDiagnostic::class, $entity);
        self::assertSame(
            'Le créneau préféré de team-1 (venue-1) a été déplacé par le solveur pour un meilleur ajustement global.',
            $entity->getMessage(),
        );
        self::assertNull($entity->getDayOfWeek(), 'Sans coordonnées, la colonne jour reste NULL.');
        self::assertNull($entity->getStartTime());
    }

    /**
     * Poste le body engine mocké, importe via le recorder (EM mocké : `findBy => []` pour les
     * name-maps, `persist` capturé — la persistance se PROUVE sur l'entité, pas via une DB ici)
     * et rend l'unique entité persistée. Miroir de {@see testEngineSessionCausesArePersistedAndExposed}.
     */
    private function recordSingleDiagnostic(string $engineBody): ScheduleDiagnostic
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse($engineBody, ['http_code' => 200]));
        $result = $client->request('POST', self::engineUrl(), ['json' => $this->buildPayload()])->toArray(false);

        $captured = [];
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$captured): void {
            $captured[] = $entity;
        });

        $schedule = new Schedule;
        $schedule->setClubId(self::CLUB_ID)->setSeasonId(self::SEASON_ID);

        new ScheduleDiagnosticsRecorder($entityManager, new DiagnosticMessageBuilder)->record($schedule, $result);

        self::assertCount(1, $captured);
        self::assertInstanceOf(ScheduleDiagnostic::class, $captured[0]);

        return $captured[0];
    }

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

        return new ScheduleConstraintBuilder(new NullLogger)->buildPayload(
            clubId: self::CLUB_ID,
            seasonId: self::SEASON_ID,
            venues: [$venue],
            teams: [$team],
            coaches: [$coach],
            constraints: [$constraint],
        );
    }

    private function assertPayloadShape(array $payload): void
    {
        // Version DÉRIVÉE de la source (le builder l'attribue depuis sa constante) ;
        // l'égalité constante⇄fichier est gardée par PayloadVersionMatchesContractVersionTest.
        self::assertSame(ScheduleConstraintBuilder::CONTRACT_VERSION, $payload['version']);
        self::assertSame(self::CLUB_ID, $payload['clubId']);
        self::assertSame(self::SEASON_ID, $payload['seasonId']);
        self::assertIsInt($payload['solverSeed']);

        foreach (['version', 'clubId', 'seasonId', 'teams', 'venues', 'coaches', 'constraints'] as $key) {
            self::assertArrayHasKey($key, $payload);
        }

        foreach (['venues', 'teams', 'coaches', 'constraints', 'slotTemplates'] as $key) {
            self::assertArrayHasKey($key, $payload);
            self::assertIsArray($payload[$key]);
        }

        self::assertArrayNotHasKey('venueAvailabilities', $payload);

        self::assertArrayHasKey('id', $payload['venues'][0]);
        self::assertIsString($payload['venues'][0]['id']);
        self::assertSame('manual', $payload['venues'][0]['source']);
        self::assertIsBool($payload['venues'][0]['isActive']);
        self::assertArrayHasKey('trainingSlots', $payload['venues'][0]);
        self::assertIsArray($payload['venues'][0]['trainingSlots']);
        // D-44 — cette branche NE S'EXÉCUTE JAMAIS ici : le fixture construit le builder sans
        // `VenueTrainingSlotRepository` (mode léger, sans DB), donc `trainingSlots` est
        // toujours vide. Elle donnait l'illusion d'une couverture que ce test n'apporte pas.
        // Le contenu des créneaux est réellement gardé là où une DB existe :
        // `ScheduleConstraintBuilderOverlayTest` (blocking, compte les créneaux et leurs jours),
        // `OverlayGenerationTest` (un gymnase fermé sort du payload) et `PeriodPlanBirthTest`.
        // Elle est conservée — et non supprimée — parce qu'elle documente la FORME attendue
        // d'un créneau ; le `if` reste donc, mais son inutilité ici est écrite.
        if ([] !== $payload['venues'][0]['trainingSlots']) {
            $slot = $payload['venues'][0]['trainingSlots'][0];
            self::assertArrayHasKey('dayOfWeek', $slot);
            self::assertArrayHasKey('startTime', $slot);
            self::assertArrayHasKey('durationMinutes', $slot);
            self::assertArrayHasKey('capacity', $slot);
            self::assertArrayNotHasKey('endTime', $slot);
        }
        self::assertArrayNotHasKey('availability', $payload['venues'][0]);

        self::assertArrayHasKey('sportCategoryId', $payload['teams'][0]);
        self::assertSame(1, $payload['teams'][0]['priorityTierId']);
        self::assertSame(2, $payload['teams'][0]['sessionsPerWeek']);
        self::assertIsBool($payload['teams'][0]['isActive']);

        self::assertArrayHasKey('firstName', $payload['coaches'][0]);
        self::assertArrayHasKey('lastName', $payload['coaches'][0]);
        self::assertIsBool($payload['coaches'][0]['isActive']);

        self::assertArrayHasKey('scopeTargetId', $payload['constraints'][0]);
        self::assertSame('TEAM', $payload['constraints'][0]['scope']);
        self::assertSame('TIME', $payload['constraints'][0]['family']);
        self::assertSame('PREFERRED', $payload['constraints'][0]['ruleType']);
        self::assertIsArray($payload['constraints'][0]['config']);
        self::assertArrayNotHasKey('teamId', $payload['constraints'][0]);
        self::assertArrayNotHasKey('type', $payload['constraints'][0]);
        self::assertArrayNotHasKey('severity', $payload['constraints'][0]);
        self::assertArrayNotHasKey('value', $payload['constraints'][0]);
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
