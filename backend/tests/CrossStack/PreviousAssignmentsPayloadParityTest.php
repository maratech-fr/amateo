<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use App\Service\ClubGenerationLock;
use App\Service\DiagnosticMessageBuilder;
use App\Service\EngineClient;
use App\Service\PeriodPlanTranscriber;
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
 * P3-21 PR B : le handler ÉMET au solveur le placement de la version « précédente » (terme de
 * stabilité, convergence d'une régénération). Ce test PROUVE que le bloc `previousAssignments`
 * REÇU PAR LE MOTEUR (EngineClient réel, corps de la requête HTTP capturé) est EXACTEMENT les
 * placements de la version source en base — falsifié dans les DEUX sens (un placement en base
 * absent du bloc échoue ; un placement du bloc absent en base échoue).
 *
 * Cas couverts : source EXPLICITE (la version regardée), repli sur la dernière COMPLETED du
 * MÊME plan, première génération (aucun bloc — chemin byte-identique), overlay (sa PROPRE
 * lignée, jamais le socle — ADR-0002), HARD inclus (le moteur saute naturellement les pins).
 *
 * + garde de NON-RÉGRESSION du hash (décision B) : injecter le précédent APRÈS le snapshot ne
 * doit PAS faire entrer `previousAssignments` dans `snapshotData`/`snapshotHash`, sous peine de
 * faire diverger `snapshotHash` de `currentStructureHash` (recalculé sans lui) à CHAQUE
 * régénération — ce qui casserait le garde « structure inchangée » (bouton Régénérer grisé).
 */
#[Group('phase1')]
#[Group('integration')]
final class PreviousAssignmentsPayloadParityTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const SPORT_CATEGORY_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    /**
     * Source EXPLICITE : le bloc émis == les placements de la source, HARD compris, dans les
     * deux sens ; ET le snapshot reste structure-only (le hash ne diverge pas).
     */
    public function testExplicitSourceMatchesSourcePlacementsAndKeepsSnapshotStructureOnly(): void
    {
        [$club, $season, $venueId, $teamIds] = $this->seed('pa-explicit');
        $planId = $this->seasonPlanIdOf($season);

        $source = $this->schedule($club, $season, $planId, ScheduleStatus::COMPLETED, 1);
        $this->slot($source, $teamIds[0], $venueId, 1, '18:00:00');
        $this->slot($source, $teamIds[1], $venueId, 3, '19:30:00');
        // Un placement HARD DOIT figurer dans le bloc (le moteur le saute de lui-même : pas de variable).
        $this->slot($source, $teamIds[0], $venueId, 5, '20:00:00', LockLevel::HARD);
        $this->em->flush();

        $target = $this->schedule($club, $season, $planId, ScheduleStatus::PENDING, 2);

        $run = $this->runCapture($club->getId(), $target->getId(), $source->getId());
        $payload = $run['payload'];
        self::assertIsArray($payload);
        self::assertArrayHasKey('previousAssignments', $payload, 'le bloc doit être ÉMIS au moteur');

        // Parité stricte, bidirectionnelle (multiset) : émis == placements de la source en base.
        $expected = array_map($this->slotToBlock(...), $this->sourceSlots($source->getId()));
        self::assertEqualsCanonicalizing(
            $expected,
            $payload['previousAssignments'],
            'le bloc émis doit être EXACTEMENT les placements de la source (falsifié dans les deux sens)',
        );
        self::assertContains(
            ['teamId' => $teamIds[0], 'venueId' => $venueId, 'dayOfWeek' => 5, 'startTime' => '20:00:00'],
            $payload['previousAssignments'],
            'un placement HARD de la source est bien inclus',
        );

        // Garde de non-régression du hash : le précédent n'entre NI dans snapshotData NI dans snapshotHash.
        $reloaded = $run['schedule'];
        $snapshot = $reloaded->getSnapshotData();
        self::assertArrayNotHasKey('previousAssignments', $snapshot, 'le précédent ne doit pas polluer le snapshot');
        self::assertSame(
            ScheduleConstraintBuilder::structureHash($snapshot),
            $reloaded->getSnapshotHash(),
            'le hash porte sur le snapshot structure-only',
        );
        // … et ce hash reste ÉGAL à currentStructureHash (recalculé SANS previousAssignments) :
        // la régression que PR B a évitée. Le solveur n'a rien importé (résultat vide), donc la
        // structure recalculée est identique à celle gelée.
        self::getContainer()->get('cache.schedule')->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $season->getId()));
        $currentStructureHash = ScheduleConstraintBuilder::structureHash($this->builder->buildForClubSeason($club->getId(), $season->getId()));
        self::assertSame(
            $currentStructureHash,
            $reloaded->getSnapshotHash(),
            'régénérer ne fait PAS diverger snapshotHash de currentStructureHash',
        );
    }

    /**
     * Sans source explicite : repli sur la DERNIÈRE version COMPLETED du même plan (versionNumber
     * le plus haut), jamais une plus ancienne.
     */
    public function testFallbackUsesLatestCompletedOfSamePlan(): void
    {
        [$club, $season, $venueId, $teamIds] = $this->seed('pa-fallback');
        $planId = $this->seasonPlanIdOf($season);

        $older = $this->schedule($club, $season, $planId, ScheduleStatus::COMPLETED, 1);
        $this->slot($older, $teamIds[0], $venueId, 1, '18:00:00'); // jour 1 (ancienne)
        $latest = $this->schedule($club, $season, $planId, ScheduleStatus::COMPLETED, 2);
        $this->slot($latest, $teamIds[0], $venueId, 2, '18:00:00'); // jour 2 (dernière)
        $this->em->flush();

        $target = $this->schedule($club, $season, $planId, ScheduleStatus::PENDING, 3);

        $run = $this->runCapture($club->getId(), $target->getId(), null);
        $payload = $run['payload'];
        self::assertIsArray($payload);
        self::assertArrayHasKey('previousAssignments', $payload);
        $days = array_column($payload['previousAssignments'], 'dayOfWeek');
        self::assertSame([2], $days, 'le repli prend la DERNIÈRE COMPLETED (jour 2), pas l\'ancienne (jour 1)');
    }

    /**
     * Première génération (aucune COMPLETED, pas de source) : aucun bloc — chemin byte-identique
     * à l'historique.
     */
    public function testFirstGenerationEmitsNoBlock(): void
    {
        [$club, $season] = $this->seed('pa-first');
        $planId = $this->seasonPlanIdOf($season);

        $target = $this->schedule($club, $season, $planId, ScheduleStatus::PENDING, 1);

        $run = $this->runCapture($club->getId(), $target->getId(), null);
        $payload = $run['payload'];
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('previousAssignments', $payload, 'première génération : aucun bloc émis');
        self::assertArrayNotHasKey('previousAssignments', $run['schedule']->getSnapshotData());
    }

    /**
     * Overlay : la source est TOUJOURS de sa propre lignée (le même plan de période), JAMAIS le
     * socle (ADR-0002). Une source explicite hors lignée (le socle) est rejetée.
     */
    public function testOverlayUsesItsOwnLineageNeverTheSocle(): void
    {
        [$club, $season, $venueId, $teamIds] = $this->seed('pa-overlay');

        // Socle : plan SEASON + une version COMPLETED au placement DISTINCT (jour 1).
        $seasonPlanId = $this->seasonPlanIdOf($season);
        $socle = $this->schedule($club, $season, $seasonPlanId, ScheduleStatus::COMPLETED, 1);
        $this->slot($socle, $teamIds[0], $venueId, 1, '18:00:00');

        // Overlay : période + son propre plan + une version COMPLETED source (jour 3).
        $entry = $this->holidayPeriod($club, $season);
        $overlayPlanId = $this->planIdOf($entry);
        $overlaySource = $this->schedule($club, $season, $overlayPlanId, ScheduleStatus::COMPLETED, 1);
        $this->slot($overlaySource, $teamIds[0], $venueId, 3, '18:00:00');
        $this->em->flush();

        // (a) source EXPLICITE de l'overlay → bloc = placements overlay (jour 3), jamais le socle (jour 1).
        $targetA = $this->schedule($club, $season, $overlayPlanId, ScheduleStatus::PENDING, 2);
        $runA = $this->runCapture($club->getId(), $targetA->getId(), $overlaySource->getId());
        self::assertIsArray($runA['payload']);
        $days = array_column($runA['payload']['previousAssignments'] ?? [], 'dayOfWeek');
        self::assertSame([3], $days, 'l\'overlay converge vers SA lignée (jour 3), jamais le socle (jour 1)');

        // (b) source du SOCLE fournie par erreur → REJETÉE (lignée différente) : aucun bloc.
        $targetB = $this->schedule($club, $season, $overlayPlanId, ScheduleStatus::PENDING, 3);
        $runB = $this->runCapture($club->getId(), $targetB->getId(), $socle->getId());
        self::assertIsArray($runB['payload']);
        self::assertArrayNotHasKey('previousAssignments', $runB['payload'], 'une source hors lignée (le socle) est rejetée');
    }

    /**
     * P2-44 PR-4 — un plan de PÉRIODE dont la dernière (et seule) COMPLETED est une V1 TRANSCRITE
     * (marqueur produit {@see PeriodPlanTranscriber::TRANSCRIPTION_MARKER}) : le solve COMPLET qui
     * suit émet SES placements en `previousAssignments`. Falsifié dans les DEUX sens :
     *   (1) chaque copie de la V1 apparaît — parité stricte, muter une copie rend le test rouge ;
     *   (2) une séance FILTRÉE « à replacer » (absente de la V1) et un slot du SOCLE ne fuient
     *       JAMAIS — le précédent est de la lignée du plan de période, pas du socle (ADR-0002).
     */
    public function testTranscribedV1IsPreviousAndNeitherToReplaceNorSocleLeaks(): void
    {
        [$club, $season, $venueId, $teamIds] = $this->seed('pa-transcribed');

        // Socle (plan SEASON, COMPLETED). Il porte DEUX séances : l'une (jour 1) sera copiée dans
        // la V1 transcrite, l'autre (jour 6) est la séance « à replacer » que la transcription
        // FILTRE. Aucune ne doit fuir DEPUIS le socle dans le précédent d'un overlay de période.
        $seasonPlanId = $this->seasonPlanIdOf($season);
        $socle = $this->schedule($club, $season, $seasonPlanId, ScheduleStatus::COMPLETED, 1);
        $this->slot($socle, $teamIds[0], $venueId, 1, '18:00:00');
        $this->slot($socle, $teamIds[1], $venueId, 6, '20:00:00'); // « à replacer » — filtrée à la transcription

        // Période + son plan. Sa DERNIÈRE (et unique) COMPLETED est la V1 TRANSCRITE : elle ne porte
        // QUE la séance copiée (jour 1, verrouillée HARD), jamais la « à replacer » (jour 6).
        $entry = $this->holidayPeriod($club, $season);
        $overlayPlanId = $this->planIdOf($entry);
        $transcribedV1 = $this->schedule($club, $season, $overlayPlanId, ScheduleStatus::COMPLETED, 1);
        $transcribedV1->setSolverVersion(PeriodPlanTranscriber::TRANSCRIPTION_MARKER);
        $this->slot($transcribedV1, $teamIds[0], $venueId, 1, '18:00:00', LockLevel::HARD);
        $this->em->flush();
        // La source EST bien une V1 transcrite (marqueur produit) — c'est le scénario gardé.
        self::assertSame(PeriodPlanTranscriber::TRANSCRIPTION_MARKER, $transcribedV1->getSolverVersion());

        // Solve COMPLET sous le plan de PÉRIODE, SANS source explicite : le repli prend la dernière
        // COMPLETED du plan = la V1 transcrite.
        $target = $this->schedule($club, $season, $overlayPlanId, ScheduleStatus::PENDING, 2);
        $run = $this->runCapture($club->getId(), $target->getId(), null);
        $payload = $run['payload'];
        self::assertIsArray($payload);
        self::assertArrayHasKey('previousAssignments', $payload, 'la V1 transcrite (dernière COMPLETED) fournit le précédent');

        // (1) parité stricte bidirectionnelle : émis == EXACTEMENT les placements de la V1 transcrite.
        $expected = array_map($this->slotToBlock(...), $this->sourceSlots($transcribedV1->getId()));
        self::assertEqualsCanonicalizing(
            $expected,
            $payload['previousAssignments'],
            'le précédent émis == les placements de la V1 transcrite (falsifié dans les deux sens)',
        );

        // (2) la séance « à replacer » (jour 6) et le slot du socle qui la porte ne fuient JAMAIS.
        $days = array_column($payload['previousAssignments'], 'dayOfWeek');
        self::assertSame([1], $days, 'le précédent est exactement la copie de la V1 transcrite (jour 1)');
        self::assertNotContains(6, $days, 'la séance « à replacer », filtrée à la transcription, n\'entre pas dans le précédent');
        self::assertNotContains(
            ['teamId' => $teamIds[1], 'venueId' => $venueId, 'dayOfWeek' => 6, 'startTime' => '20:00:00'],
            $payload['previousAssignments'],
            'un slot du socle ne fuit pas dans le précédent de l\'overlay',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
    }

    /**
     * Lance le handler avec un EngineClient RÉEL sur un MockHttpClient qui CAPTURE le corps de la
     * requête POST /generate (le payload effectivement émis au moteur) et renvoie un résultat vide
     * (le solveur n'importe rien — la structure reste stable pour la garde de hash).
     *
     * @return array{payload: array<string, mixed>|null, schedule: Schedule}
     */
    private function runCapture(string $clubId, string $scheduleId, ?string $sourceScheduleId): array
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

        $handler(new GenerateScheduleMessage(scheduleId: $scheduleId, clubId: $clubId, sourceScheduleId: $sourceScheduleId));

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $reloaded = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);

        return ['payload' => $captured, 'schedule' => $reloaded];
    }

    /** @return array<ScheduleSlotTemplate> */
    private function sourceSlots(string $scheduleId): array
    {
        return $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId], ['id' => 'ASC']);
    }

    /** @return array{teamId: string, venueId: string, dayOfWeek: int, startTime: string} */
    private function slotToBlock(ScheduleSlotTemplate $slot): array
    {
        return [
            'teamId' => $slot->getTeamId(),
            'venueId' => $slot->getVenueId(),
            'dayOfWeek' => $slot->getDayOfWeek(),
            'startTime' => $slot->getStartTime()->format('H:i:s'),
        ];
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

    private function slot(Schedule $schedule, string $teamId, string $venueId, int $dayOfWeek, string $startTime, LockLevel $lockLevel = LockLevel::NONE): void
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new DateTimeImmutable($startTime))
            ->setDurationMinutes(90)
            ->setLockLevel($lockLevel);
        $this->em->persist($slot);
    }

    private function holidayPeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Reprise');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * @return array{0: Club, 1: Season, 2: string, 3: array<int, string>}
     */
    private function seed(string $prefix): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Prev Parity Club');
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

        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Gymnase');
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);

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

        return [$club, $season, $venue->getId(), $teamIds];
    }
}
