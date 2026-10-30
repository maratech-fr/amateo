<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
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
use App\Tests\ChoosesPlanVersionTrait;
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
 * NR BLOQUANT — « une réservation n'est épinglée QU'UNE FOIS au moteur » (axe §7.1 generation
 * pipeline ; corollaire planning lifecycle « structure inchangée »).
 *
 * Après une génération, l'import du résultat écrit des `ScheduleSlotTemplate` HARD d'origine
 * RESERVATION ({@see ScheduleResultImporter}). Au build suivant, le builder reprenait
 * ces templates EN PLUS des `Reservation` durables dont ils sont nés : chaque réservation partait
 * DEUX fois au solveur (26 → 52 épingles au BCCL/ALAP), et surtout l'empreinte de structure
 * ({@see SchedulePlanProvisioner::structureHashOfPlan}) divergeait du `snapshotHash` de la version
 * fraîchement générée — un faux « périmé » juste après génération, et le risque d'envoyer la même
 * réservation deux fois au second solve.
 *
 * La correction vit à la SOURCE, dans `buildPayload` : un template HARD d'origine RESERVATION est
 * SAUTÉ (base ET période), car la réservation durable le porte déjà. Les HARD MANUAL (verrou de
 * work-loop) et UNKNOWN (indécidable) restent repris — c'est le filet contre le sur-filtrage.
 *
 * Ce fichier épingle les quatre falsifications :
 *   - base : la réservation figure UNE fois, pas deux, après import → ROUGE avant la correction ;
 *   - période : idem sur `buildForPeriodPlan` (prouve que le saut vit dans `buildPayload`, partagé,
 *     et non dans le seul `findBaseSlotTemplates`) → ROUGE avant la correction ;
 *   - empreinte : `structureHashOfPlan` juste après un import == celle d'avant → ROUGE avant ;
 *   - sur-filtrage : un HARD UNKNOWN sans réservation à l'origine RESTE au payload (le MANUAL, lui,
 *     est gardé par le jumeau {@see HardLockSurvivesPayloadTest}, step bloquant) → ne doit JAMAIS
 *     tomber ; il rougit si la correction filtre trop large.
 *
 * Étendu le 2026-10-30 — le même faux « périmé » venait aussi des séances placées par le solveur :
 * leur template `lockLevel: NONE` entrait dans le payload recalculé. De bout en bout par le VRAI
 * handler (moteur simulé, import réel), l'empreinte reste égale au `snapshotHash` après une première
 * génération, après une seconde, et sur un plan de période.
 */
#[Group('phase1')]
#[Group('integration')]
final class ReservationPinsNotDoubledTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const string VENUE_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string TEAM_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /** Mardi (ISO) — jour de la réservation et de l'épingle importée qui en est née. */
    private const int DAY = 2;
    private const string START = '18:00';
    /** Le payload sérialise l'heure en H:i:s (`ScheduleConstraintBuilder::formatTime`). */
    private const string START_SERIALIZED = '18:00:00';
    private const int DURATION = 90;

    private const string FREE_TEAM_ID = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    /** Jeudi : la case de la séance que le solveur place librement, hors réservation. */
    private const int FREE_DAY = 4;

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    private SchedulePlanProvisioner $provisioner;

    /**
     * BASE : une réservation d'équipe + l'épingle HARD/RESERVATION que l'import a écrite au MÊME
     * placement ne doivent produire qu'UNE entrée `slotTemplates` — la réservation. Avant la
     * correction, les deux partaient : le placement sortait en double.
     */
    public function testAnImportedReservationPinIsNotDoubledInTheBasePayload(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season);
        $this->venue($club, $season);
        $base = $this->baseSchedule($club, $season);
        $this->reservation($club, $season, null);
        // Ce que l'import du résultat écrit : un verrou HARD d'origine RESERVATION, au placement
        // exact de la réservation ci-dessus.
        $this->importedReservationPin($base);
        $this->em->flush();

        $this->purgeCache($club, $season);
        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertCount(
            1,
            $this->pinsAtReservationPlacement($payload),
            'une réservation épinglée une fois à l’import ne doit partir qu’UNE fois au moteur — '
            . 'le template HARD/RESERVATION né de l’import est redondant avec la réservation durable',
        );
    }

    /**
     * PÉRIODE : même promesse sur le rail overlay. Prouve que le saut est dans `buildPayload`
     * (partagé par les deux rails), pas seulement dans `findBaseSlotTemplates`.
     */
    public function testAnImportedReservationPinIsNotDoubledInThePeriodPayload(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season);
        $this->venue($club, $season);
        $entry = $this->closurePeriod($club, $season);
        $this->em->flush();

        $planId = $this->planIdOf($entry);
        $overlay = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Overlay')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($overlay, $entry->getId());
        // Réservation de PÉRIODE (ancrée au plan) + l'épingle importée au même placement.
        $this->reservation($club, $season, $planId);
        $this->importedReservationPin($overlay);
        $this->em->flush();

        $payload = $this->builder->buildForPeriodPlan(
            $club->getId(),
            $season->getId(),
            $planId,
            $entry,
            scheduleId: $overlay->getId(),
        );

        self::assertCount(
            1,
            $this->pinsAtReservationPlacement($payload),
            'en période aussi, la réservation ne part qu’UNE fois : le saut vit dans buildPayload (partagé)',
        );
    }

    /**
     * L'empreinte de structure d'un plan SEASON juste après un import est IDENTIQUE à celle
     * d'avant l'import — c'est le cœur du faux « périmé ». Avant la correction, les templates
     * HARD/RESERVATION écrits par l'import faisaient diverger le hash de `snapshotHash`.
     */
    /**
     * Pas de faux « périmé » : le coach stocké sur un verrou manuel est un RÉSULTAT du solve, pas une
     * donnée de structure. Une régénération qui l'écrit ou le change ne déplace pas l'empreinte.
     */
    public function testTheStoredCoachOfAManualLockStaysOutOfTheStructureHash(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season);
        $this->venue($club, $season);
        $base = $this->baseSchedule($club, $season);
        $this->importedPin($base, LockOrigin::MANUAL);
        $this->em->flush();

        $this->purgeCache($club, $season);
        $before = $this->provisioner->structureHashOfPlan($this->seasonPlanIdOf($season));

        $pin = $this->em->getRepository(ScheduleSlotTemplate::class)->findOneBy(['scheduleId' => $base->getId(), 'teamId' => self::TEAM_ID]);
        self::assertInstanceOf(ScheduleSlotTemplate::class, $pin);
        $pin->setCoachId('99999999-9999-4999-8999-999999999999');
        $this->em->flush();

        $this->purgeCache($club, $season);
        self::assertNotNull($before);
        self::assertSame($before, $this->provisioner->structureHashOfPlan($this->seasonPlanIdOf($season)), 'le coach stocké n\'entre pas dans l\'empreinte');
    }

    public function testStructureHashIsStableAcrossAnImportedGeneration(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season);
        $this->venue($club, $season);
        $base = $this->baseSchedule($club, $season);
        $this->reservation($club, $season, null);
        $this->em->flush();

        // Empreinte AU MOMENT de la génération : la réservation seule (l'import n'a pas encore écrit).
        $this->purgeCache($club, $season);
        $hashAtGeneration = $this->provisioner->structureHashOfPlan($this->seasonPlanIdOf($season));

        // L'import du résultat écrit l'épingle HARD/RESERVATION.
        $this->importedReservationPin($base);
        $this->em->flush();

        $this->purgeCache($club, $season);
        $hashAfterImport = $this->provisioner->structureHashOfPlan($this->seasonPlanIdOf($season));

        self::assertNotNull($hashAtGeneration);
        self::assertSame(
            $hashAtGeneration,
            $hashAfterImport,
            'l’empreinte de structure ne doit pas bouger juste parce que l’import a réalimenté la '
            . 'réservation en template HARD — sinon le cockpit crie « périmé » sur un plan intact',
        );
    }

    /**
     * FILET anti-sur-filtrage : un HARD d'origine UNKNOWN (indécidable — aucune réservation à
     * l'origine) RESTE au payload. La correction ne saute QUE les HARD/RESERVATION. Le HARD/MANUAL
     * est gardé par le jumeau {@see HardLockSurvivesPayloadTest} (step bloquant) — on ne le
     * duplique pas ici. Ce test rougit si la correction filtre trop large.
     */
    public function testAHardUnknownTemplateWithoutABackingReservationStaysInThePayload(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season);
        $this->venue($club, $season);
        $base = $this->baseSchedule($club, $season);
        // AUCUNE réservation : le verrou est HARD/UNKNOWN. Le builder doit le reprendre.
        $this->importedPin($base, LockOrigin::UNKNOWN);
        $this->em->flush();

        $this->purgeCache($club, $season);
        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        self::assertCount(
            1,
            $this->pinsAtReservationPlacement($payload),
            'un HARD UNKNOWN (indécidable, sans réservation) doit rester une épingle du payload — '
            . 'la correction ne saute QUE les HARD d’origine RESERVATION',
        );
    }

    /** SOCLE : une réservation + une séance placée par le solveur ; rien n'a bougé, donc rien n'est périmé. */
    public function testASolverPlacedSessionDoesNotMakeTheSeasonPlanLookStale(): void
    {
        [$club, $season] = $this->seed();
        $this->seedReservedAndFreeTeams($club, $season, null);
        $schedule = $this->pendingVersion($club, $season, $this->seasonPlanIdOf($season), null);
        $this->em->flush();

        $this->assertNotStaleAfterGenerating($club, $season, $schedule->getId(), $this->seasonPlanIdOf($season));
    }

    /** SOCLE, deuxième génération : la version précédente et ses séances libres ne font pas diverger. */
    public function testASecondGenerationStaysNotStale(): void
    {
        [$club, $season] = $this->seed();
        $this->seedReservedAndFreeTeams($club, $season, null);
        $planId = $this->seasonPlanIdOf($season);
        $first = $this->pendingVersion($club, $season, $planId, null);
        $this->em->flush();
        $this->assertNotStaleAfterGenerating($club, $season, $first->getId(), $planId);

        $this->scopeGucToClub($club->getId());
        $club = $this->em->getRepository(Club::class)->find($club->getId());
        $season = $this->em->getRepository(Season::class)->find($season->getId());
        self::assertInstanceOf(Club::class, $club);
        self::assertInstanceOf(Season::class, $season);
        $second = $this->pendingVersion($club, $season, $planId, null);
        $this->em->flush();

        $this->assertNotStaleAfterGenerating($club, $season, $second->getId(), $planId);
    }

    /** PÉRIODE : même promesse sur le rail overlay. */
    public function testASolverPlacedSessionDoesNotMakeAPeriodPlanLookStale(): void
    {
        [$club, $season] = $this->seed();
        $entry = $this->closurePeriod($club, $season);
        $this->em->flush();
        $planId = $this->planIdOf($entry);
        $this->seedReservedAndFreeTeams($club, $season, $planId);
        $schedule = $this->pendingVersion($club, $season, $planId, $entry->getId());
        $this->em->flush();

        $this->assertNotStaleAfterGenerating($club, $season, $schedule->getId(), $planId);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $this->provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
    }

    private function assertNotStaleAfterGenerating(Club $club, Season $season, string $scheduleId, string $planId): void
    {
        $this->generate($club->getId(), $scheduleId);

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $generated = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $generated);
        self::assertSame(ScheduleStatus::COMPLETED, $generated->getStatus());
        // Témoin : le NONE existe bien, sans quoi le test ne prouverait rien.
        self::assertNotEmpty(
            $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId, 'lockLevel' => LockLevel::NONE]),
            'la séance placée par le solveur doit avoir été importée',
        );

        $this->purgeCache($club, $season);
        self::assertSame(
            $generated->getSnapshotHash(),
            $this->provisioner->structureHashOfPlan($planId),
            'structure inchangée : une séance placée par le solveur ne rend pas le planning périmé',
        );
    }

    private function generate(string $clubId, string $scheduleId): void
    {
        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $slot = fn (string $id, string $teamId, int $day, string $lock): array => [
            'id' => $id, 'teamId' => $teamId, 'venueId' => self::VENUE_ID, 'coachId' => null, 'dayOfWeek' => $day,
            'startTime' => self::START_SERIALIZED, 'durationMinutes' => self::DURATION, 'lockLevel' => $lock, 'pendingConstraintSuggestion' => null,
        ];
        $engineResult = json_encode([
            'status' => 'completed',
            'score' => 0,
            'slots' => [$slot('s-reserved', self::TEAM_ID, self::DAY, 'HARD'), $slot('s-free', self::FREE_TEAM_ID, self::FREE_DAY, 'NONE')],
            'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse($engineResult, ['http_code' => 200]));

        $handler = new GenerateScheduleHandler(
            $this->em,
            $this->builder,
            $container->get(ScheduleResultImporter::class),
            new EngineClient($client, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new ScheduleDiagnosticsRecorder($this->em, $container->get(DiagnosticMessageBuilder::class)),
            new SolverMetricsMapper,
            $container->get(ClubGenerationLock::class),
            $container->get(TenantConnectionContext::class),
            $container->get(StructureSnapshotter::class),
            $this->provisioner,
            null,
            null,
            $container->get(SolverMetricsRecorder::class),
        );

        $handler(new GenerateScheduleMessage(scheduleId: $scheduleId, clubId: $clubId));
    }

    private function seedReservedAndFreeTeams(Club $club, Season $season, ?string $schedulePlanId): void
    {
        $this->team($club, $season);
        $free = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName('SM2')
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
        $free->setId(self::FREE_TEAM_ID);
        $this->em->persist($free);
        $this->venue($club, $season);
        $this->reservation($club, $season, $schedulePlanId);
    }

    private function pendingVersion(Club $club, Season $season, string $planId, ?string $calendarEntryId): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Version')->setStatus(ScheduleStatus::PENDING);
        if (null === $calendarEntryId) {
            $schedule->setSchedulePlanId($planId);
            $this->em->persist($schedule);
            $this->provisioner->linkSchedule($schedule);
        } else {
            $this->linkSeededSchedule($schedule, $calendarEntryId);
        }

        return $schedule;
    }

    /**
     * Les entrées de `slotTemplates` au placement de la réservation (team/venue/jour/heure/durée).
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function pinsAtReservationPlacement(array $payload): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter((array) ($payload['slotTemplates'] ?? []), 'is_array'));

        return array_values(array_filter($rows, static fn (array $s): bool => ($s['teamId'] ?? null) === self::TEAM_ID
            && ($s['venueId'] ?? null) === self::VENUE_ID
            && ($s['dayOfWeek'] ?? null) === self::DAY
            && ($s['startTime'] ?? null) === self::START_SERIALIZED
            && ($s['durationMinutes'] ?? null) === self::DURATION));
    }

    private function purgeCache(Club $club, Season $season): void
    {
        self::getContainer()->get('cache.schedule')->deleteItem(
            ScheduleConstraintBuilder::cacheKey($club->getId(), $season->getId()),
        );
    }

    private function baseSchedule(Club $club, Season $season): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Socle')->setStatus(ScheduleStatus::COMPLETED);
        // C4 : une version de BASE est ancrée au plan SEASON — findBaseSlotTemplates ne prend
        // QUE les créneaux des versions de ce plan.
        $schedule->setSchedulePlanId($this->seasonPlanIdOf($season));
        $this->em->persist($schedule);

        return $schedule;
    }

    private function importedReservationPin(Schedule $schedule): void
    {
        $this->importedPin($schedule, LockOrigin::RESERVATION);
    }

    private function importedPin(Schedule $schedule, LockOrigin $origin): void
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId(self::TEAM_ID)
            ->setVenueId(self::VENUE_ID)
            ->setDayOfWeek(self::DAY)
            ->setStartTime(new DateTimeImmutable(self::START))
            ->setDurationMinutes(self::DURATION)
            ->setLockLevel(LockLevel::HARD)
            ->setLockOrigin($origin);
        $this->em->persist($slot);
    }

    private function reservation(Club $club, Season $season, ?string $schedulePlanId): void
    {
        $reservation = (new Reservation)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($schedulePlanId)
            ->setTeamId(self::TEAM_ID)
            ->setVenueId(self::VENUE_ID)
            ->setDayOfWeek(self::DAY)
            ->setStartTime(new DateTimeImmutable(self::START))
            ->setDurationMinutes(self::DURATION);
        $this->em->persist($reservation);
    }

    private function team(Club $club, Season $season): void
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName('SM1')
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
        $team->setId(self::TEAM_ID);
        $this->em->persist($team);
    }

    private function venue(Club $club, Season $season): void
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase')
            ->setCanSplit(false)->setSource('manual');
        $venue->setId(self::VENUE_ID);
        $this->em->persist($venue);
    }

    private function closurePeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = (new CalendarEntry)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)
            ->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setTitle('Fermeture')
            ->setStartDate(new DateTimeImmutable('2025-12-20'))
            ->setEndDate(new DateTimeImmutable('2026-01-04'));
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = (new Club)->setName('Doublon Club')->setSlug('doublon-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode('DBL' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = (new User)->setEmail('doublon-' . $uid . '@test.com')->setFirstName('D')->setLastName('B');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = (new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($cu);

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
