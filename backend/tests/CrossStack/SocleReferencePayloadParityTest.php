<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
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
 * NR BLOQUANT — axes generation pipeline + backend↔engine contract (§7.1).
 *
 * PR-3 (comblement référencé au socle) : en mode COMBLEMENT UNIQUEMENT, le handler ÉMET au solveur
 * la RÉFÉRENCE de comblement — les placements de la version POINTÉE du socle (plan SEASON), sous la
 * forme `{teamId, dayOfWeek, startTime}` SANS `venueId` (le gymnase est libre). Ce test PROUVE, sur
 * le VRAI EngineClient (corps de la requête HTTP capturé), que le bloc `socleReferenceAssignments` :
 *
 *   (a) en comblement, VAUT EXACTEMENT les placements de la version pointée du socle (parité stricte
 *       bidirectionnelle, dédup par (équipe, jour, heure)), SANS `venueId` — et lit bien le SOCLE
 *       (cross-plan), jamais la lignée du plan de période (falsifié : un jour propre à la source de
 *       période NE fuit PAS) ;
 *   (b) HORS comblement, N'EST PAS émis (une génération ordinaire garde le chemin historique) ;
 *   (c) N'ENTRE PAS dans le hash de snapshot (injecté APRÈS, comme `previousAssignments` — décision
 *       B : préférence de convergence, jamais donnée de structure ; sinon `snapshotHash` divergerait
 *       de `currentStructureHash`).
 *
 * Chaque assertion est falsifiable dans les deux sens (muter un placement du socle rend (a) rouge ;
 * émettre le bloc hors fill rend (b) rouge ; le faire entrer dans le snapshot rend (c) rouge).
 */
#[Group('phase1')]
#[Group('integration')]
final class SocleReferencePayloadParityTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const SPORT_CATEGORY_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private EntityManagerInterface $em;

    /**
     * (a) + (c) : en comblement, le bloc == les placements de la version POINTÉE du socle
     * (déduplication (équipe, jour, heure), SANS venueId), lus cross-plan depuis le plan SEASON ;
     * et le bloc n'entre pas dans le snapshot.
     */
    public function testFillEmitsSocleReferenceWithoutVenueAndOutsideSnapshot(): void
    {
        [$club, $season, $venueA, $venueB, $teamIds] = $this->seed('sr-fill');

        // Socle : plan SEASON + une version COMPLETED, POINTÉE. Ses placements sont la référence.
        $seasonPlanId = $this->seasonPlanIdOf($season);
        $socle = $this->schedule($club, $season, $seasonPlanId, ScheduleStatus::COMPLETED, 1);
        $this->slot($socle, $teamIds[0], $venueA, 1, '18:00:00');
        $this->slot($socle, $teamIds[1], $venueA, 3, '19:30:00');
        // MÊME (équipe, jour, heure) sur un AUTRE gymnase → dédup : une seule entrée de référence.
        $this->slot($socle, $teamIds[0], $venueB, 1, '18:00:00');
        $this->em->flush();
        $this->pointSeasonPlanAt($seasonPlanId, $socle->getId());

        // Période de FERMETURE + son plan + une source COMPLETED de comblement (jour DISTINCT du
        // socle : jour 5) — c'est la source figée HARD, jamais la référence socle.
        $entry = $this->closurePeriod($club, $season);
        $overlayPlanId = $this->planIdOf($entry);
        $fillSource = $this->schedule($club, $season, $overlayPlanId, ScheduleStatus::COMPLETED, 1);
        $this->slot($fillSource, $teamIds[0], $venueA, 5, '20:00:00');
        $this->em->flush();

        $target = $this->schedule($club, $season, $overlayPlanId, ScheduleStatus::PENDING, 2);

        $run = $this->runCapture($club->getId(), $target->getId(), $fillSource->getId());
        $payload = $run['payload'];
        self::assertIsArray($payload);
        self::assertArrayHasKey('socleReferenceAssignments', $payload, 'le comblement doit ÉMETTRE la référence socle');

        // (a) parité stricte : le bloc == les placements DÉDUPLIQUÉS du socle, {teamId, dayOfWeek, startTime}.
        $expected = [
            ['teamId' => $teamIds[0], 'dayOfWeek' => 1, 'startTime' => '18:00:00'],
            ['teamId' => $teamIds[1], 'dayOfWeek' => 3, 'startTime' => '19:30:00'],
        ];
        self::assertEqualsCanonicalizing(
            $expected,
            $payload['socleReferenceAssignments'],
            'la référence émise == les placements pointés du socle (dédup (équipe,jour,heure), falsifié dans les deux sens)',
        );

        // … SANS venueId (le gymnase est libre) : aucune entrée ne porte cette clé.
        foreach ($payload['socleReferenceAssignments'] as $entry) {
            self::assertArrayNotHasKey('venueId', $entry, 'la référence socle NE porte PAS de gymnase');
        }

        // Cross-plan : la référence est le SOCLE, pas la lignée de période. Le jour 5 (propre à la
        // source de comblement) ne fuit JAMAIS dans la référence.
        $days = array_column($payload['socleReferenceAssignments'], 'dayOfWeek');
        self::assertNotContains(5, $days, 'un jour propre à la source de période ne fuit pas dans la référence socle');

        // (c) le bloc n'entre NI dans snapshotData NI dans snapshotHash.
        $reloaded = $run['schedule'];
        $snapshot = $reloaded->getSnapshotData();
        self::assertArrayNotHasKey('socleReferenceAssignments', $snapshot, 'la référence ne doit pas polluer le snapshot');
        self::assertSame(
            hash('sha256', json_encode($snapshot, \JSON_THROW_ON_ERROR)),
            $reloaded->getSnapshotHash(),
            'le hash porte sur le snapshot structure-only (référence exclue)',
        );
    }

    /**
     * (b) : une génération ORDINAIRE (hors comblement — plan de saison, pas de fillSource) n'émet
     * AUCUN bloc `socleReferenceAssignments`. Le régime socle est réservé au fill.
     */
    public function testNonFillGenerationEmitsNoSocleReference(): void
    {
        [$club, $season, $venueA, , $teamIds] = $this->seed('sr-nonfill');

        // Socle POINTÉ existe bel et bien — mais on n'est PAS en comblement.
        $seasonPlanId = $this->seasonPlanIdOf($season);
        $socle = $this->schedule($club, $season, $seasonPlanId, ScheduleStatus::COMPLETED, 1);
        $this->slot($socle, $teamIds[0], $venueA, 1, '18:00:00');
        $this->em->flush();
        $this->pointSeasonPlanAt($seasonPlanId, $socle->getId());

        // Cible : une nouvelle version du plan de SAISON (génération ordinaire, aucun fillSource).
        $target = $this->schedule($club, $season, $seasonPlanId, ScheduleStatus::PENDING, 2);

        $run = $this->runCapture($club->getId(), $target->getId(), null);
        $payload = $run['payload'];
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('socleReferenceAssignments', $payload, 'hors comblement : aucune référence socle émise');
        self::assertArrayNotHasKey('socleReferenceAssignments', $run['schedule']->getSnapshotData());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Lance le handler avec un EngineClient RÉEL sur un MockHttpClient qui CAPTURE le corps de la
     * requête POST /generate et renvoie un résultat vide (le solveur n'importe rien — la structure
     * reste stable pour la garde de hash). `$fillSourceScheduleId` non-null ⇒ mode comblement.
     *
     * @return array{payload: array<string, mixed>|null, schedule: Schedule}
     */
    private function runCapture(string $clubId, string $scheduleId, ?string $fillSourceScheduleId): array
    {
        $this->em->flush();
        $this->em->clear();
        $this->clearGuc();

        $captured = null;
        $engineResult = json_encode(['status' => 'completed', 'score' => 0, 'slots' => [], 'diagnostics' => []], \JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured, $engineResult): MockResponse {
            $body = $options['body'] ?? null;
            if (\is_string($body)) {
                $decoded = json_decode($body, true);
                if (\is_array($decoded)) {
                    $captured = $decoded;
                }
            }

            return new MockResponse($engineResult, ['http_code' => 200]);
        });

        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $handler = new GenerateScheduleHandler(
            $this->em,
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(ScheduleResultImporter::class),
            new EngineClient($client, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new ScheduleDiagnosticsRecorder($this->em, $container->get(DiagnosticMessageBuilder::class)),
            new SolverMetricsMapper,
            $container->get(ClubGenerationLock::class),
            $container->get(TenantConnectionContext::class),
            $container->get(StructureSnapshotter::class),
            $container->get(SchedulePlanProvisioner::class),
        );

        $handler(new GenerateScheduleMessage(
            scheduleId: $scheduleId,
            clubId: $clubId,
            fillSourceScheduleId: $fillSourceScheduleId,
        ));

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $reloaded = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);

        return ['payload' => $captured, 'schedule' => $reloaded];
    }

    /** Pointe le plan SEASON sur sa version choisie (le socle en vigueur — SocleGuard en prod). */
    private function pointSeasonPlanAt(string $seasonPlanId, string $scheduleId): void
    {
        $plan = $this->em->getRepository(SchedulePlan::class)->find($seasonPlanId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        $plan->setChosenScheduleId($scheduleId);
        $this->em->flush();
    }

    private function schedule(Club $club, Season $season, string $planId, ScheduleStatus $status, int $versionNumber): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setName('V' . $versionNumber)
            ->setStatus($status)
            ->setVersionNumber($versionNumber);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function slot(Schedule $schedule, string $teamId, string $venueId, int $dayOfWeek, string $startTime): void
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new DateTimeImmutable($startTime))
            ->setDurationMinutes(90);
        $this->em->persist($slot);
    }

    private function closurePeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Fermeture gymnase');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * @return array{0: Club, 1: Season, 2: string, 3: string, 4: array<int, string>}
     */
    private function seed(string $prefix): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Socle Ref Club');
        $club->setSlug($prefix . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $venueIds = [];
        foreach (['A', 'B'] as $name) {
            $venue = new Venue;
            $venue->setClubId($club->getId());
            $venue->setSeasonId($season->getId());
            $venue->setName('Gymnase ' . $name);
            $venue->setCanSplit(false);
            $venue->setSource('manual');
            $this->em->persist($venue);
            $venueIds[] = $venue->getId();
        }

        $teamIds = [];
        foreach (['A', 'B'] as $name) {
            $team = new Team;
            $team->setClubId($club->getId());
            $team->setSeasonId($season->getId());
            $team->setName($name);
            $team->setSportCategoryId(self::SPORT_CATEGORY_ID);
            $team->setPriorityTierId(1);
            $this->em->persist($team);
            $teamIds[] = $team->getId();
        }
        $this->em->flush();

        return [$club, $season, $venueIds[0], $venueIds[1], $teamIds];
    }
}
