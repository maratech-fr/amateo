<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * NR d'axe *generation pipeline* (§7.1) — la garde de redélivrance du handler.
 *
 * Une redélivrance Messenger (worker tué APRÈS le flush COMPLETED, AVANT l'ack) ne doit
 * JAMAIS re-solver : re-solver écraserait un planning déjà livré, retouches manuelles
 * comprises. SEUL le statut COMPLETED bloque ; tout autre statut passe (une génération
 * légitime pose PENDING avant le dispatch — CLAUDE.md §6, gestes vérifiés dans les trois
 * dispatchers). Deux témoins pour que le test ne puisse pas passer pour la mauvaise raison :
 * l'APPEL moteur (le travail interdit) et le DÉGÂT (une retouche manuelle écrasée).
 *
 * Harnais dupliqué de {@see GenerateScheduleFailureTest} (base réelle, MockHttpClient) —
 * duplication assumée : l'extraire serait un refactor hors scope.
 */
#[Group('phase1')]
#[Group('integration')]
final class RedeliveredGenerationTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string SENTINEL_TEAM = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private const string SENTINEL_VENUE = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    /**
     * Témoin d'APPEL — le travail interdit. Sur un planning DÉJÀ COMPLETED, la redélivrance
     * n'appelle PAS le moteur : le compteur de requêtes vaut 0. Sans la garde, le handler
     * re-solve et le compteur passe à 1.
     */
    public function testRedeliveredCompletedScheduleNeverCallsTheEngine(): void
    {
        [$em, $club, $schedule] = $this->seedSchedule('redeliver-call', ScheduleStatus::COMPLETED);
        $scheduleId = $schedule->getId();

        $requestCount = $this->runHandler($em, $club->getId(), $scheduleId);

        self::assertSame(0, $requestCount, 'une redélivrance d\'un COMPLETED n\'appelle JAMAIS le moteur');

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::COMPLETED, $reloaded->getStatus(), 'le planning livré reste COMPLETED');
    }

    /**
     * Témoin de DÉGÂT — une séance placée « à la main » (NONE, absente du résultat mocké) sur
     * un planning DÉJÀ COMPLETED survit à l'identique à la redélivrance. Sans la garde, l'import
     * du résultat (slots vides) balaie cette sentinelle NONE.
     */
    public function testRedeliveredCompletedSchedulePreservesManualEdits(): void
    {
        [$em, $club, $schedule] = $this->seedSchedule('redeliver-damage', ScheduleStatus::COMPLETED);
        $scheduleId = $schedule->getId();

        $this->scopeGucToClub($club->getId());
        $sentinel = new ScheduleSlotTemplate;
        $sentinel->setClubId($club->getId());
        $sentinel->setSeasonId($schedule->getSeasonId());
        $sentinel->setScheduleId($scheduleId);
        $sentinel->setTeamId(self::SENTINEL_TEAM);
        $sentinel->setVenueId(self::SENTINEL_VENUE);
        $sentinel->setDayOfWeek(3);
        $sentinel->setStartTime(new DateTimeImmutable('19:30'));
        $sentinel->setDurationMinutes(90);
        $sentinel->setLockLevel(LockLevel::NONE);
        $em->persist($sentinel);
        $em->flush();
        $sentinelId = $sentinel->getId();
        $em->clear();
        $this->clearGuc();

        $this->runHandler($em, $club->getId(), $scheduleId);

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $survivor = $em->getRepository(ScheduleSlotTemplate::class)->find($sentinelId);
        self::assertInstanceOf(ScheduleSlotTemplate::class, $survivor, 'la retouche manuelle survit à la redélivrance');
        self::assertSame(self::SENTINEL_TEAM, $survivor->getTeamId());
        self::assertSame(self::SENTINEL_VENUE, $survivor->getVenueId());
        self::assertSame(3, $survivor->getDayOfWeek());
        self::assertSame('19:30', $survivor->getStartTime()->format('H:i'));
        self::assertSame(LockLevel::NONE, $survivor->getLockLevel());
    }

    /**
     * Preuve que la garde bloque le SEUL COMPLETED : un FAILED, un GENERATING orphelin
     * (SIGKILL sans catch → la redélivrance est sa reprise) et un PENDING re-solvent tous —
     * le moteur est appelé une fois. Bloquer l'un d'eux casserait un usage légitime.
     */
    public function testFailedScheduleReSolves(): void
    {
        $this->assertStatusReSolves(ScheduleStatus::FAILED, 'failed-resolve');
    }

    public function testGeneratingOrphanReSolves(): void
    {
        $this->assertStatusReSolves(ScheduleStatus::GENERATING, 'generating-resolve');
    }

    public function testPendingScheduleReSolves(): void
    {
        $this->assertStatusReSolves(ScheduleStatus::PENDING, 'pending-resolve');
    }

    private function assertStatusReSolves(ScheduleStatus $seededStatus, string $slugPrefix): void
    {
        [$em, $club, $schedule] = $this->seedSchedule($slugPrefix, $seededStatus);
        $scheduleId = $schedule->getId();

        $requestCount = $this->runHandler($em, $club->getId(), $scheduleId);

        self::assertSame(1, $requestCount, \sprintf('un planning %s doit re-solver (le moteur est appelé)', $seededStatus->value));

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::COMPLETED, $reloaded->getStatus(), 'le re-solve mène à COMPLETED');
    }

    /**
     * @return array{0: EntityManagerInterface, 1: Club, 2: Schedule}
     */
    private function seedSchedule(string $slugPrefix, ScheduleStatus $status): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Redeliver Club');
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
        $schedule->setName('Redeliver schedule');
        $schedule->setStatus($status);
        // En prod toute version est liée à son plan à la création (POST → linkSchedule) ;
        // sans plan le site « socle ? » du handler lèverait au build. Lot D : plan NOT NULL.
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $schedule->setSchedulePlanId($provisioner->ensureSeasonPlanId($season->getId()));
        $em->persist($schedule);
        $em->flush();
        $provisioner->linkSchedule($schedule);
        // linkSchedule ne touche pas au statut ; on ré-affirme le statut voulu par le test.
        $schedule->setStatus($status);
        $em->flush();
        $em->clear();

        // Contexte worker : aucun GUC posé quand le handler démarre.
        $this->clearGuc();

        return [$em, $club, $schedule];
    }

    private function runHandler(EntityManagerInterface $em, string $clubId, string $scheduleId): int
    {
        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $engineResult = json_encode(['status' => 'completed', 'score' => 0, 'slots' => [], 'diagnostics' => []], \JSON_THROW_ON_ERROR);

        $requestCount = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($engineResult, &$requestCount): MockResponse {
            ++$requestCount;

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

        return $requestCount;
    }
}
