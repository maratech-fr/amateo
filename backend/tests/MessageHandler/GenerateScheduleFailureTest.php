<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SolverMetric;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use App\Service\ClubGenerationLock;
use App\Service\DiagnosticMessageBuilder;
use App\Service\EngineClient;
use App\Service\RequestIdContext;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleDiagnosticsRecorder;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Service\ScheduleResultImporter;
use App\Service\SolverMetricsMapper;
use App\Service\SolverMetricsRecorder;
use App\Service\StructureSnapshotter;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * BCK-01 non-regression on the generation handler's failure semantics.
 */
#[Group('phase1')]
#[Group('integration')]
final class GenerateScheduleFailureTest extends KernelTestCase
{
    use TenantGucTrait;

    /**
     * Mercure is best-effort: a valid COMPLETED solve must survive a publish
     * failure. Before the fix, a Mercure blip on the post-solve publish threw
     * out of generate() and the ~650 s solve was discarded (marked FAILED /
     * left frozen). The result is persisted before the publish, and the publish
     * is swallowed → the schedule must end COMPLETED.
     */
    public function testMercurePublishFailureDoesNotDiscardACompletedSolve(): void
    {
        [$em, $club, $schedule] = $this->seedScheduleReadyToGenerate('mercure-blip');
        $scheduleId = $schedule->getId();

        $engineResult = json_encode([
            'status' => 'completed',
            'score' => 0,
            'slots' => [],
            'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR);

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update): string {
            throw new RuntimeException('mercure unavailable');
        });

        $this->runHandler($em, $club->getId(), $scheduleId, new MockHttpClient(new MockResponse($engineResult, ['http_code' => 200])), $hub);

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(
            ScheduleStatus::COMPLETED,
            $reloaded->getStatus(),
            'a persisted COMPLETED solve must survive a best-effort Mercure publish failure',
        );
        self::assertSame(1, $em->getRepository(SolverMetric::class)->count(['scheduleId' => $scheduleId]));

        // P5-10 — the handler stamped solveStartedAt at the GENERATING flush, and the
        // recorder copied both lifecycle instants + the payload size onto the metric row.
        self::assertNotNull($reloaded->getSolveStartedAt(), 'the handler stamps solveStartedAt at the GENERATING flush');
        $metric = $em->getRepository(SolverMetric::class)->findOneBy(['scheduleId' => $scheduleId]);
        self::assertInstanceOf(SolverMetric::class, $metric);
        self::assertNotNull($metric->getQueuedAt(), 'queuedAt copied from the schedule onto the metric');
        self::assertNotNull($metric->getSolveStartedAt(), 'solveStartedAt copied onto the metric');
        self::assertNotNull($metric->getPayloadBytes());
        self::assertGreaterThan(0, $metric->getPayloadBytes(), 'payload_bytes = strlen of the serialized snapshot');
    }

    /**
     * A genuine error inside generate() (here: the importer rejecting a malformed
     * solver slot) must leave a *clean* terminal FAILED — never a half-success.
     * In particular the season baseline must NOT be designated off a run that
     * failed, and a client-safe diagnostic must be recorded.
     */
    public function testUncaughtGenerationErrorLeavesCleanFailed(): void
    {
        [$em, $club, $schedule, $season] = $this->seedScheduleReadyToGenerate('gen-error');
        $scheduleId = $schedule->getId();
        $seasonId = $season->getId();

        // A HARD slot with an unparseable startTime → ScheduleResultImporter throws.
        $engineResult = json_encode([
            'status' => 'completed',
            'score' => 10,
            'slots' => [[
                'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'teamId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'venueId' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'dayOfWeek' => 1,
                'startTime' => 'not-a-time',
                'durationMinutes' => 60,
                'lockLevel' => 'HARD',
            ]],
            'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR);

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $this->runHandler($em, $club->getId(), $scheduleId, new MockHttpClient(new MockResponse($engineResult, ['http_code' => 200])), $hub);

        $this->scopeGucToClub($club->getId());
        $em->clear();

        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::FAILED, $reloaded->getStatus(), 'a genuine generation error must leave the schedule FAILED, never frozen');

        $reloadedSeason = $em->getRepository(Season::class)->find($seasonId);
        self::assertInstanceOf(Season::class, $reloadedSeason);
        self::assertNull(
            $em->getConnection()->fetchOne('SELECT chosen_schedule_id FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'', ['sid' => $seasonId]) ?: null,
            'a failed run must NOT be pointed at by the season plan',
        );

        $types = array_map(
            static fn (ScheduleDiagnostic $diagnostic): string => $diagnostic->getType(),
            $em->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $scheduleId]),
        );
        self::assertContains('internal_error', $types, 'a failure diagnostic must be recorded');
        self::assertSame(1, $em->getRepository(SolverMetric::class)->count(['scheduleId' => $scheduleId]));
    }

    /**
     * Geste 2 (greffe de convergence) — le corps RÉELLEMENT envoyé au moteur est
     * reconstituable depuis la base : il vaut `Schedule::engineInput()` (snapshot + greffe)
     * rechargé. Ici en RÉGÉNÉRATION : une V1 COMPLETED du même plan porte un placement, greffé
     * en `previousAssignments` APRÈS le hash de snapshot. La greffe est persistée dans
     * `payload_graft`, jamais recalculée. Falsification : retirer `setPayloadGraft` dans le
     * handler → la greffe relue serait vide et la parité tomberait.
     */
    public function testEngineBodyEqualsPersistedEngineInputInRegeneration(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Graft Club');
        $club->setSlug('graft-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $em->persist($club);
        $em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $em->persist($season);
        $em->flush();

        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $planId = $provisioner->ensureSeasonPlanId($season->getId());

        // La version SOURCE (V1 COMPLETED du plan) et son placement : repli de
        // `resolvePreviousAssignmentSlots` sur la dernière COMPLETED → greffe non vide.
        $source = new Schedule;
        $source->setClubId($club->getId());
        $source->setSeasonId($season->getId());
        $source->setName('Source V1');
        $source->setStatus(ScheduleStatus::COMPLETED);
        $source->setSchedulePlanId($planId);
        $source->setVersionNumber(1);
        $em->persist($source);
        $em->flush();

        $placement = new ScheduleSlotTemplate;
        $placement->setClubId($club->getId());
        $placement->setSeasonId($season->getId());
        $placement->setScheduleId($source->getId());
        $placement->setTeamId('11111111-1111-4111-8111-111111111111');
        $placement->setVenueId('22222222-2222-4222-8222-222222222222');
        $placement->setDayOfWeek(2);
        $placement->setStartTime(new DateTimeImmutable('18:00'));
        $placement->setDurationMinutes(90);
        $placement->setLockLevel(LockLevel::NONE);
        $em->persist($placement);

        // La V2 à régénérer.
        $target = new Schedule;
        $target->setClubId($club->getId());
        $target->setSeasonId($season->getId());
        $target->setName('Cible V2');
        $target->setStatus(ScheduleStatus::PENDING);
        $target->setSchedulePlanId($planId);
        $target->setVersionNumber(2);
        $target->setQueuedAt(new DateTimeImmutable('2026-08-13 09:00:00'));
        $em->persist($target);
        $em->flush();
        $targetId = $target->getId();
        $em->clear();
        $this->clearGuc();

        $body = $this->runHandlerCapturingBody($em, $club->getId(), $targetId);

        self::assertArrayHasKey('previousAssignments', $body, 'la régénération greffe le placement précédent');

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($targetId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::COMPLETED, $reloaded->getStatus());
        $graft = $reloaded->getPayloadGraft();
        self::assertIsArray($graft, 'la greffe est persistée (non-null quand la source existe)');
        self::assertArrayHasKey('previousAssignments', $graft, 'la greffe persistée porte previousAssignments, PAS le snapshot');
        self::assertArrayNotHasKey('previousAssignments', $reloaded->getSnapshotData(), 'la greffe n\'entre jamais dans le snapshot gelé');
        self::assertEquals($body, $reloaded->engineInput(), 'le corps moteur == engineInput() (snapshot + greffe) rechargé');
    }

    /** @return array<string, mixed> le corps RÉELLEMENT envoyé au moteur (décodé) */
    private function runHandlerCapturingBody(EntityManagerInterface $em, string $clubId, string $scheduleId): array
    {
        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $engineResult = json_encode(['status' => 'completed', 'score' => 0, 'slots' => [], 'diagnostics' => []], \JSON_THROW_ON_ERROR);

        $sent = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($engineResult, &$sent): MockResponse {
            $sent = json_decode((string) ($options['body'] ?? '{}'), true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse($engineResult, ['http_code' => 200]);
        });

        $handler = new GenerateScheduleHandler(
            $em,
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(ScheduleResultImporter::class),
            new EngineClient($client, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new ScheduleDiagnosticsRecorder($em, $container->get(DiagnosticMessageBuilder::class)),
            new SolverMetricsMapper,
            $container->get(ClubGenerationLock::class),
            $container->get(TenantConnectionContext::class),
            $container->get(StructureSnapshotter::class),
            $container->get(SchedulePlanProvisioner::class),
            null,
            null,
            $container->get(SolverMetricsRecorder::class),
        );

        $handler(new GenerateScheduleMessage(scheduleId: $scheduleId, clubId: $clubId));

        return $sent;
    }

    /**
     * @return array{0: EntityManagerInterface, 1: Club, 2: Schedule, 3: Season}
     */
    private function seedScheduleReadyToGenerate(string $slugPrefix): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('BCK01 Club');
        $club->setSlug($slugPrefix . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $em->persist($club);
        $em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $em->persist($season);
        $em->flush();

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('BCK01 schedule');
        $schedule->setStatus(ScheduleStatus::PENDING);
        // P5-10 — le dispatch (contrôleur) pose queuedAt ; on le simule ici pour prouver
        // qu'il est recopié sur la métrique. solveStartedAt, lui, est posé PAR le handler.
        $schedule->setQueuedAt(new DateTimeImmutable('2026-08-13 09:00:00'));
        // Prod links every version at creation (POST → linkSchedule) ; sans plan, le site
        // « socle ? » du handler lèverait dès le build (periodEntryIdOf) et transformerait ce
        // COMPLETED en FAILED. C4 : linkSchedule numérote — la version porte d'abord son plan.
        // Lot D : schedule_plan_id est NOT NULL — le plan est posé AVANT le persist/flush.
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $schedule->setSchedulePlanId($provisioner->ensureSeasonPlanId($season->getId()));
        $em->persist($schedule);
        $em->flush();
        $provisioner->linkSchedule($schedule);
        $em->flush();
        $em->clear();

        // Worker context: no GUC when the handler starts.
        $this->clearGuc();

        return [$em, $club, $schedule, $season];
    }

    private function runHandler(
        EntityManagerInterface $em,
        string $clubId,
        string $scheduleId,
        MockHttpClient $httpClient,
        HubInterface $hub,
    ): void {
        $container = self::getContainer();
        $handler = new GenerateScheduleHandler(
            $em,
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(ScheduleResultImporter::class),
            new EngineClient($httpClient, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new ScheduleDiagnosticsRecorder($em, $container->get(DiagnosticMessageBuilder::class)),
            new SolverMetricsMapper,
            $container->get(ClubGenerationLock::class),
            $container->get(TenantConnectionContext::class),
            $container->get(StructureSnapshotter::class),
            $container->get(SchedulePlanProvisioner::class),
            null,
            null,
            $container->get(SolverMetricsRecorder::class),
        );

        $handler(new GenerateScheduleMessage(scheduleId: $scheduleId, clubId: $clubId));
    }
}
