<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
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
use App\Service\StructureSnapshotter;
use App\Service\TenantConnectionContext;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * Generation pipeline NR (palier B): an overlay generation carries the closed
 * venue as per-team forbiddenVenueId constraints into the frozen snapshot, never
 * becomes the season baseline, and fails cleanly if its period vanished.
 */
#[Group('phase1')]
#[Group('integration')]
final class OverlayGenerationTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;

    use TenantGucTrait;

    private const VENUE_CLOSED = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testClosureOverlaySnapshotRemovesClosedVenueSlotsAndSkipsBaseline(): void
    {
        [$em, $club, $season, $entry] = $this->seedClosureOverlay('ov-gen');

        // P2-5 5b : le gym fermé (toute la fenêtre — config sans dates) perd ses
        // créneaux du snapshot gelé. Un venue + créneau saisonnier pour le vérifier.
        $venue = new Venue;
        $venue->setId(self::VENUE_CLOSED);
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Gym fermé');
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $em->persist($venue);
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId(self::VENUE_CLOSED);
        $slot->setDayOfWeek(1);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $slot->setSchedulePlanId(null);
        $em->persist($slot);
        $em->flush();

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Overlay');
        $schedule->setStatus(ScheduleStatus::PENDING);
        // Une version d'overlay est TOUJOURS liée à son plan en prod (linkSchedule au POST) ;
        // buildForOverlay l'exige depuis le lot C2 — sans plan il ne sait pas quels réglages
        // appliquer et refuse de bâtir plutôt que d'en ignorer.
        $schedule->setSchedulePlanId($this->planIdOf($entry));
        $em->persist($schedule);
        $em->flush();
        $scheduleId = $schedule->getId();
        $em->clear();
        $this->clearGuc();

        $engineResult = json_encode(['status' => 'completed', 'score' => 5, 'slots' => [], 'diagnostics' => []], \JSON_THROW_ON_ERROR);
        $this->runHandler($em, $club->getId(), $scheduleId, $engineResult);

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::COMPLETED, $reloaded->getStatus());
        // P5-10 — le handler pose solveStartedAt au flush GENERATING, chemin overlay compris.
        self::assertNotNull($reloaded->getSolveStartedAt(), 'the GENERATING flush stamps solveStartedAt on an overlay too');

        // 5b : le snapshot gelé ne porte plus de forbiddenVenueId (mécanisme supprimé)…
        $snapshot = $reloaded->getSnapshotData();
        $forbidden = array_filter($snapshot['constraints'] ?? [], static fn (array $c): bool => isset($c['config']['forbiddenVenueId']));
        self::assertCount(0, $forbidden, 'le forbid tous-jours est remplacé par le retrait de créneaux');
        // …et le gym fermé n'a plus aucun créneau dans le snapshot.
        $closedVenue = array_values(array_filter($snapshot['venues'] ?? [], static fn (array $v): bool => self::VENUE_CLOSED === $v['id']));
        self::assertNotEmpty($closedVenue, 'le gym fermé reste listé (sans créneau)');
        self::assertSame([], $closedVenue[0]['trainingSlots'], 'ses créneaux sont retirés du payload gelé');

        // An overlay lives in the PERIOD's plan — it must never end up pointed at
        // by the SEASON plan (inv. 2: nothing points automatically anyway).
        self::assertNull(
            $em->getConnection()->fetchOne('SELECT chosen_schedule_id FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'', ['sid' => $season->getId()]) ?: null,
            'an overlay must not become the season plan\'s chosen version',
        );
    }

    /**
     * P2-44 PR-3 (comblement) — le mode fill épingle HARD les placements de la version SOURCE
     * DANS le snapshot gelé de la V+1 (le solve partiel les fige), et NE greffe PAS
     * `previousAssignments` (un HARD n'a pas de variable — le terme de stabilité serait un no-op).
     * Falsifié dans les deux sens : sans le mode, aucune épingle et le précédent revient.
     */
    public function testFillModePinsSourcePlacementsIntoTheSnapshotAndSkipsPreviousAssignments(): void
    {
        [$em, $club, $season, $entry] = $this->seedClosureOverlay('ov-fill');

        // Un gymnase ACTIF (dans le roster de la période) pour porter le placement copié.
        $activeVenueId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $venue = new Venue;
        $venue->setId($activeVenueId);
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Gym ouvert');
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $em->persist($venue);

        $teamId = $em->getRepository(Team::class)->findOneBy(['clubId' => $club->getId()])?->getId();
        self::assertIsString($teamId);

        $planId = $this->planIdOf($entry);

        // Un comblement n'existe QU'avec un socle en vigueur (SocleGuard en amont, CLAUDE.md §6) :
        // le handler lève sur un socle sans version pointée plutôt que de combler sans référence.
        // La fixture doit donc être réaliste — une version COMPLETED du plan SEASON, pointée.
        $socle = new Schedule;
        $socle->setClubId($club->getId());
        $socle->setSeasonId($season->getId());
        $socle->setName('Socle');
        $socle->setStatus(ScheduleStatus::COMPLETED);
        $socle->setSchedulePlanId($this->seasonPlanIdOf($season));
        $socle->setVersionNumber(1);
        $em->persist($socle);
        $em->flush();
        $seasonPlan = $em->getRepository(SchedulePlan::class)->find($this->seasonPlanIdOf($season));
        self::assertInstanceOf(SchedulePlan::class, $seasonPlan);
        $seasonPlan->setChosenScheduleId($socle->getId());
        $em->flush();

        // La version SOURCE (COMPLETED) et SON placement — c'est lui qu'on doit retrouver épinglé.
        $source = new Schedule;
        $source->setClubId($club->getId());
        $source->setSeasonId($season->getId());
        $source->setName('Source');
        $source->setStatus(ScheduleStatus::COMPLETED);
        $source->setSchedulePlanId($planId);
        $source->setVersionNumber(1); // en prod linkSchedule numérote ; ici on fixe deux V distinctes
        $em->persist($source);
        $em->flush();

        $placement = new ScheduleSlotTemplate;
        $placement->setClubId($club->getId());
        $placement->setSeasonId($season->getId());
        $placement->setScheduleId($source->getId());
        $placement->setTeamId($teamId);
        $placement->setVenueId($activeVenueId);
        $placement->setDayOfWeek(2);
        $placement->setStartTime(new DateTimeImmutable('18:00'));
        $placement->setDurationMinutes(90);
        $placement->setLockLevel(LockLevel::NONE); // la source peut être NONE : le fill FIGE en HARD
        $em->persist($placement);

        // La V+1 à combler.
        $target = new Schedule;
        $target->setClubId($club->getId());
        $target->setSeasonId($season->getId());
        $target->setName('Comblement');
        $target->setStatus(ScheduleStatus::PENDING);
        $target->setSchedulePlanId($planId);
        $target->setVersionNumber(2);
        $em->persist($target);
        $em->flush();
        $sourceId = $source->getId();
        $targetId = $target->getId();
        $em->clear();
        $this->clearGuc();

        $engineResult = json_encode(['status' => 'completed', 'score' => 1, 'slots' => [], 'diagnostics' => []], \JSON_THROW_ON_ERROR);
        $sentPayload = $this->runHandler($em, $club->getId(), $targetId, $engineResult, $sourceId);

        // Le payload RÉELLEMENT envoyé au moteur porte l'épingle en HARD, et AUCUN previousAssignments.
        $pins = array_values(array_filter(
            $sentPayload['slotTemplates'] ?? [],
            static fn (array $s): bool => ($s['teamId'] ?? null) === $teamId && ($s['venueId'] ?? null) === $activeVenueId,
        ));
        self::assertCount(1, $pins, 'le placement de la source doit être épinglé dans le payload du comblement');
        self::assertSame(LockLevel::HARD->value, $pins[0]['lockLevel'], 'le comblement FIGE le placé en HARD');
        self::assertArrayNotHasKey('previousAssignments', $sentPayload, 'le comblement épingle déjà tout en HARD — pas de terme de stabilité');

        // Et le snapshot GELÉ porte l'épingle (elle EST l'entrée figée du solve partiel).
        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($targetId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::COMPLETED, $reloaded->getStatus());
        $snapshotPins = array_values(array_filter(
            $reloaded->getSnapshotData()['slotTemplates'] ?? [],
            static fn (array $s): bool => ($s['teamId'] ?? null) === $teamId && ($s['venueId'] ?? null) === $activeVenueId,
        ));
        self::assertCount(1, $snapshotPins, 'l\'épingle du comblement vit dans le snapshot gelé');
        self::assertSame(LockLevel::HARD->value, $snapshotPins[0]['lockLevel']);
    }

    public function testOverlayWithMissingPeriodFailsCleanly(): void
    {
        [$em, $club, $season, $entry] = $this->seedClosureOverlay('ov-missing');

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Overlay orphan');
        $schedule->setStatus(ScheduleStatus::PENDING);
        // Une version d'overlay est TOUJOURS liée à son plan en prod (linkSchedule au POST) ;
        // buildForOverlay l'exige depuis le lot C2 — sans plan il ne sait pas quels réglages
        // appliquer et refuse de bâtir plutôt que d'en ignorer.
        $schedule->setSchedulePlanId($this->planIdOf($entry));
        $em->persist($schedule);
        $em->flush();
        $scheduleId = $schedule->getId();

        // The period is deleted between queueing and running.
        $em->remove($entry);
        $em->flush();
        $em->clear();
        $this->clearGuc();

        $engineResult = json_encode(['status' => 'completed', 'score' => 0, 'slots' => [], 'diagnostics' => []], \JSON_THROW_ON_ERROR);
        $this->runHandler($em, $club->getId(), $scheduleId, $engineResult);

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertSame(ScheduleStatus::FAILED, $reloaded?->getStatus());
        $types = array_map(
            static fn (ScheduleDiagnostic $d): string => $d->getType(),
            $em->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $scheduleId]),
        );
        self::assertContains('overlay_entry_missing', $types);
    }

    /**
     * @return array{0: EntityManagerInterface, 1: Club, 2: Season, 3: CalendarEntry}
     */
    private function seedClosureOverlay(string $prefix): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('OVGEN Club');
        $club->setSlug($prefix . '-' . $uid);
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

        foreach (['U11', 'U13'] as $name) {
            $team = new Team;
            $team->setClubId($club->getId());
            $team->setSeasonId($season->getId());
            $team->setName($name);
            $team->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
            $team->setPriorityTierId(1);
            $em->persist($team);
        }

        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $em->persist($entry);

        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Salle fermée');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setScopeTargetId(self::VENUE_CLOSED);
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setCalendarEntryId($entry->getId());
        $em->persist($dated);
        $em->flush();

        return [$em, $club, $season, $entry];
    }

    /**
     * @return array<string, mixed> le payload RÉELLEMENT envoyé au moteur (pour l'inspecter)
     */
    private function runHandler(EntityManagerInterface $em, string $clubId, string $scheduleId, string $engineResult, ?string $fillSourceScheduleId = null): array
    {
        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

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
        );

        $handler(new GenerateScheduleMessage(scheduleId: $scheduleId, clubId: $clubId, fillSourceScheduleId: $fillSourceScheduleId));

        return $sent;
    }
}
