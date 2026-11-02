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
use App\Entity\VenueTrainingSlot;
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
use App\Service\SeasonResolver;
use App\Service\SolverMetricsMapper;
use App\Service\SolverMetricsRecorder;
use App\Service\StructureSnapshotter;
use App\Service\TenantConnectionContext;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * NR BLOQUANT — « seuls les verrous de la version CHARGÉE partent au moteur » (axes generation
 * pipeline + planning lifecycle ; plan « version chargée », A1 et A2).
 *
 * Défaut d'origine, observé au rejeu : `findBaseSlotTemplates` prenait les séances de TOUTES les
 * versions du plan de saison. Un verrou retiré en V2 revenait depuis V1, un déplacement au mardi
 * laissait repartir l'ancien jeudi, et consulter V3 faisait partir ses verrous alors que V2 était
 * chargée.
 *
 * Moteur simulé qui RENVOIE les épingles reçues : ce qui compte ici est le contenu du payload (le
 * moteur fixe ce qu'on lui épingle, garanti par `HardLockSurvivesPayloadTest` et le smoke sémantique).
 * Le rail est réel : POST regenerate, message lu sur le transport, handler, import.
 *
 * Contrat attendu de l'implémentation :
 *  - `SchedulePlanProvisioner::lockSourceScheduleIdOfPlan` : plan SEASON = `Season.liveContextScheduleId`
 *    si la version existe, est COMPLETED et du plan ; sinon la dernière COMPLETED ; sinon null ;
 *  - `buildForClubSeason(..., ?string $lockVersionId = null)` : null = la résolution ci-dessus ;
 *  - `cacheKey($clubId, $seasonId, ?string $lockVersionId)` : trois arguments obligatoires ;
 *  - 202 de regenerate : `{id, status, sourceScheduleId}` ; `sourceScheduleId` = la version chargée.
 */
#[Group('phase1')]
#[Group('integration')]
final class LoadedVersionLocksOnlyTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const int MON = 1;
    private const int TUE = 2;
    private const int THU = 4;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    private string $venueA;

    private string $venueC;

    /** @var array<string, mixed>|null Le dernier payload reçu par le moteur simulé. */
    private ?array $enginePayload = null;

    private int $engineCalls = 0;

    /** Étoile cohérente (existante, COMPLETED, du plan) : c'est elle, même si une version est plus récente. */
    public function testACoherentStarIsTheLoadedVersion(): void
    {
        [$v1, $v2, $v3] = [$this->version(), $this->version(), $this->version()];
        $this->star($v2);

        self::assertSame($v2->getId(), $this->loaded());
        self::assertNotSame($v3->getId(), $this->loaded());
        self::assertNotSame($v1->getId(), $this->loaded());
    }

    /** Sans étoile, la dernière version terminée est chargée. */
    public function testWithoutStarTheLatestCompletedIsLoaded(): void
    {
        $this->version();
        $latest = $this->version();
        $this->version(ScheduleStatus::FAILED);

        self::assertSame($latest->getId(), $this->loaded());
    }

    /** Une étoile périmée (version en échec, ou disparue) retombe sur la dernière terminée. */
    public function testAStaleStarFallsBackToTheLatestCompleted(): void
    {
        $latest = $this->version();
        $failed = $this->version(ScheduleStatus::FAILED);

        $this->star($failed);
        self::assertSame($latest->getId(), $this->loaded(), 'étoile sur une version en échec');

        $this->season->setLiveContextScheduleId('99999999-9999-4999-8999-999999999999');
        $this->em->flush();
        self::assertSame($latest->getId(), $this->loaded(), 'étoile sur une version disparue');
    }

    /** Aucune version terminée : pas de version chargée, seules les réservations partent. */
    public function testWithoutCompletedVersionOnlyReservationsArePinned(): void
    {
        $team = $this->team('U15');
        $draft = $this->version(ScheduleStatus::DRAFT);
        $this->slot($draft, $team, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $reservation = (new Reservation)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId(null)->setTeamId($team->getId())->setVenueId($this->venueC)
            ->setDayOfWeek(self::TUE)->setStartTime(new DateTimeImmutable('19:00'))->setDurationMinutes(90);
        $this->em->persist($reservation);
        $this->em->flush();

        self::assertNull($this->loaded());
        self::assertSame(
            [$this->place($team, $this->venueC, self::TUE, '19:00')],
            $this->hardPins($this->build()),
        );
    }

    /** Seules les séances de la version chargée sortent : ni les verrous, ni les séances libres des autres. */
    public function testOnlyTheLoadedVersionRowsReachThePayload(): void
    {
        [$t1, $t2, $t3] = [$this->team('T1'), $this->team('T2'), $this->team('T3')];
        [$v1, $v2, $v3] = [$this->version(), $this->version(), $this->version()];
        $this->slot($v1, $t1, $this->venueA, self::MON, '19:00', LockLevel::HARD);
        $this->slot($v1, $t3, $this->venueC, self::THU, '20:30', LockLevel::NONE);
        $this->slot($v2, $t2, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $this->slot($v3, $t3, $this->venueC, self::TUE, '19:00', LockLevel::HARD);
        $this->em->flush();
        $this->star($v2);

        $payload = $this->build();

        self::assertSame([$this->place($t2, $this->venueA, self::THU, '19:00')], $this->hardPins($payload));
        self::assertCount(1, (array) $payload['slotTemplates'], 'aucune séance d’une autre version, même libre');
    }

    /** Le même placement verrouillé dans deux versions ne part qu'une fois. */
    public function testTheSamePlacementInTwoVersionsIsPinnedOnce(): void
    {
        $team = $this->team('U15');
        [$v1, $v2] = [$this->version(), $this->version()];
        $this->slot($v1, $team, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $this->slot($v2, $team, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $this->em->flush();

        self::assertCount(1, $this->hardPins($this->build()));
    }

    /** Une version nommée explicitement fait foi, et son payload ne se mélange pas à celui de la chargée en cache. */
    public function testAnExplicitLockVersionIsHonouredAndCachedApart(): void
    {
        [$t1, $t2] = [$this->team('T1'), $this->team('T2')];
        [$v1, $v2] = [$this->version(), $this->version()];
        $this->slot($v1, $t1, $this->venueA, self::MON, '19:00', LockLevel::HARD);
        $this->slot($v2, $t2, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $this->em->flush();
        $this->purge();

        $builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $byDefault = $builder->buildForClubSeason($this->club->getId(), $this->season->getId());
        $explicit = $builder->buildForClubSeason($this->club->getId(), $this->season->getId(), lockVersionId: $v1->getId());

        self::assertSame([$this->place($t2, $this->venueA, self::THU, '19:00')], $this->hardPins($byDefault));
        self::assertSame([$this->place($t1, $this->venueA, self::MON, '19:00')], $this->hardPins($explicit));
    }

    /** La clé de cache exige la version : un appelant qui l'oublie ne compile pas au lieu de lire le mauvais payload. */
    public function testTheCacheKeyRequiresTheVersion(): void
    {
        $method = new ReflectionMethod(ScheduleConstraintBuilder::class, 'cacheKey');

        self::assertSame(3, $method->getNumberOfRequiredParameters());
        self::assertNotSame(
            ScheduleConstraintBuilder::cacheKey('c', 's', 'v1'),
            ScheduleConstraintBuilder::cacheKey('c', 's', 'v2'),
        );
    }

    /** Rejeu : un verrou retiré en V2 ne revient pas depuis V1 à la régénération suivante. */
    public function testARemovedLockDoesNotComeBack(): void
    {
        $team = $this->team('U15');
        $v1 = $this->version();
        $this->slot($v1, $team, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $this->em->flush();

        $v2 = $this->regenerate($v1);
        $pinned = $this->hardRowOf($v2, $team);
        $this->client->request('POST', '/api/schedule-slots/' . $pinned->getId() . '/manual-edit/lock', [], [], $this->jsonHeaders(), '{"lockLevel":"NONE"}');
        self::assertResponseIsSuccessful();

        $this->regenerate($v2);

        self::assertSame([], $this->hardPins((array) $this->enginePayload), 'le verrou retiré ne doit plus partir au moteur');
    }

    /** Rejeu : une séance déplacée au mardi et reverrouillée part au mardi, le jeudi ne remonte pas. */
    public function testAMoveDoesNotBringBackTheOldPlacement(): void
    {
        $team = $this->team('U15');
        $v1 = $this->version();
        $this->slot($v1, $team, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $this->em->flush();

        $v2 = $this->regenerate($v1);
        $this->hardRowOf($v2, $team)->setDayOfWeek(self::TUE)->setVenueId($this->venueC);
        $this->em->flush();

        $this->regenerate($v2);

        self::assertSame(
            [$this->place($team, $this->venueC, self::TUE, '19:00')],
            $this->hardPins((array) $this->enginePayload),
        );
    }

    /** Rejeu : étoile sur V2, V3 consultée : régénérer repart de V2, le dit, et V3 ne pèse rien. */
    public function testRegeneratingWhileViewingAnotherVersionStartsFromTheLoadedOne(): void
    {
        [$t1, $t2, $t3] = [$this->team('T1'), $this->team('T2'), $this->team('T3')];
        [$v1, $v2, $v3] = [$this->version(), $this->version(), $this->version()];
        $this->slot($v1, $t1, $this->venueA, self::MON, '19:00', LockLevel::HARD);
        $this->slot($v2, $t2, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $this->slot($v3, $t3, $this->venueC, self::TUE, '19:00', LockLevel::HARD);
        $this->em->flush();
        $this->star($v2);

        $v4 = $this->regenerate($v3, expectedSource: $v2->getId());

        self::assertSame([$this->place($t2, $this->venueA, self::THU, '19:00')], $this->hardPins((array) $this->enginePayload));
        $previousTeams = array_map(static fn (array $p): mixed => $p['teamId'] ?? null, (array) ($this->enginePayload['previousAssignments'] ?? []));
        self::assertNotContains($t3->getId(), $previousTeams, 'la convergence se mesure à la version chargée, pas à la version consultée');

        self::assertSame($v4->getId(), $this->loaded(), 'la version née devient la chargée');
    }

    /** Un verrou posé sur une version non chargée ne rend pas le planning « périmé ». */
    public function testALockOnANonLoadedVersionDoesNotMoveTheStructureHash(): void
    {
        $team = $this->team('U15');
        [$v1, $v2] = [$this->version(), $this->version()];
        $this->em->flush();
        $this->purge();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $before = $provisioner->structureHashOfPlan($v2->getSchedulePlanId());

        $this->slot($v1, $team, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $this->em->flush();
        $this->purge();

        self::assertSame($before, $provisioner->structureHashOfPlan($v2->getSchedulePlanId()));
    }

    /** Worker : la source explicite d'un plan de saison a disparu : échec nommé, le moteur n'est pas appelé. */
    public function testTheWorkerFailsWhenTheSeasonSourceHasVanished(): void
    {
        $this->team('U15');
        $this->version();
        $pending = $this->version(ScheduleStatus::PENDING);

        $this->runWorker($pending, '99999999-9999-4999-8999-999999999999');

        self::assertSame(0, $this->engineCalls, 'sans source, repartir d’une autre version serait un mensonge');
        self::assertSame(ScheduleStatus::FAILED, $this->em->getRepository(Schedule::class)->find($pending->getId())?->getStatus());
    }

    /** Worker : sans source explicite (première génération d'un brouillon), le repli reste la version chargée. */
    public function testTheWorkerWithoutSourceStillUsesTheLoadedVersion(): void
    {
        $team = $this->team('U15');
        $v1 = $this->version();
        $this->slot($v1, $team, $this->venueA, self::THU, '19:00', LockLevel::HARD);
        $this->em->flush();
        $pending = $this->version(ScheduleStatus::PENDING);

        $this->runWorker($pending, null);

        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($pending->getId())?->getStatus());
        self::assertSame([$this->place($team, $this->venueA, self::THU, '19:00')], $this->hardPins((array) $this->enginePayload));
    }

    /** Worker : un plan de PÉRIODE garde son comportement quand sa source a disparu, il n'échoue pas. */
    public function testThePeriodWorkerIsUnchangedWhenItsSourceHasVanished(): void
    {
        $this->team('U15');
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setTitle('Fermeture')->setStartDate(new DateTimeImmutable('+30 days'))->setEndDate(new DateTimeImmutable('+40 days'));
        $this->em->persist($entry);
        $this->em->flush();
        $pending = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('P')->setStatus(ScheduleStatus::PENDING);
        $this->linkSeededSchedule($pending, $entry->getId());
        $this->em->flush();

        $this->runWorker($pending, '99999999-9999-4999-8999-999999999999');

        self::assertSame(1, $this->engineCalls);
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($pending->getId())?->getStatus());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Chargée ' . $uid)->setSlug('chargee-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('chargee' . $uid . '@test.com')->setFirstName('C')->setLastName('H');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season = (new Season)->setClubId($this->club->getId())->setName((string) $year)
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();
        $this->provisionSeasonPlan($this->season);

        $this->venueA = $this->venue('A', [[self::THU, '19:00'], [self::MON, '19:00']]);
        $this->venueC = $this->venue('C', [[self::TUE, '19:00'], [self::THU, '20:30']]);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function loaded(): ?string
    {
        return self::getContainer()->get(SchedulePlanProvisioner::class)
            ->lockSourceScheduleIdOfPlan((string) self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($this->season->getId()));
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $this->purge();

        return self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason($this->club->getId(), $this->season->getId());
    }

    private function purge(): void
    {
        self::getContainer()->get('cache.schedule')->invalidateTags([ScheduleConstraintBuilder::cacheTag($this->club->getId())]);
    }

    /**
     * Les placements HARD d'un payload, sous une forme comparable.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function hardPins(array $payload): array
    {
        $pins = [];
        foreach ((array) ($payload['slotTemplates'] ?? []) as $pin) {
            if (\is_array($pin) && 'HARD' === ($pin['lockLevel'] ?? null)) {
                $pins[] = \sprintf('%s|%s|%d|%s', $pin['teamId'], $pin['venueId'], $pin['dayOfWeek'], substr((string) $pin['startTime'], 0, 5));
            }
        }

        return $pins;
    }

    private function place(Team $team, string $venueId, int $day, string $time): string
    {
        return \sprintf('%s|%s|%d|%s', $team->getId(), $venueId, $day, $time);
    }

    /** Le vrai geste : POST regenerate, message lu sur le transport, handler réel, moteur simulé. */
    private function regenerate(Schedule $viewed, ?string $expectedSource = null): Schedule
    {
        $this->client->request('POST', '/api/schedules/' . $viewed->getId() . '/regenerate', [], [], $this->jsonHeaders());
        self::assertSame(202, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $newId = (string) $body['id'];
        if (null !== $expectedSource) {
            self::assertSame($expectedSource, $body['sourceScheduleId'] ?? null, 'la réponse nomme la version de départ');
        }

        $transport = self::getContainer()->get('messenger.transport.async');
        $message = null;
        for ($i = 0; $i < 20 && !$message instanceof GenerateScheduleMessage; ++$i) {
            foreach ($transport->get() as $envelope) {
                $candidate = $envelope->getMessage();
                $transport->ack($envelope);
                if ($candidate instanceof GenerateScheduleMessage && $candidate->getScheduleId() === $newId) {
                    $message = $candidate;
                }
            }
        }
        self::assertInstanceOf(GenerateScheduleMessage::class, $message);
        if (null !== $expectedSource) {
            self::assertSame($expectedSource, $message->getSourceScheduleId(), 'le message porte la version chargée');
        }

        $this->em->clear();
        $this->handler()($message);
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $this->season = $this->em->getRepository(Season::class)->find($this->season->getId()) ?? $this->season;

        $new = $this->em->getRepository(Schedule::class)->find($newId);
        self::assertInstanceOf(Schedule::class, $new);
        self::assertSame(ScheduleStatus::COMPLETED, $new->getStatus());

        return $new;
    }

    private function runWorker(Schedule $pending, ?string $sourceId): void
    {
        $id = $pending->getId();
        $this->em->clear();
        $this->handler()(new GenerateScheduleMessage(scheduleId: $id, clubId: $this->club->getId(), sourceScheduleId: $sourceId));
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
    }

    /** Le handler réel, sur un moteur simulé qui capture le payload et renvoie les épingles reçues. */
    private function handler(): GenerateScheduleHandler
    {
        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $engine = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            ++$this->engineCalls;
            $this->enginePayload = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);
            $slots = [];
            foreach ((array) ($this->enginePayload['slotTemplates'] ?? []) as $pin) {
                if (\is_array($pin) && 'HARD' === ($pin['lockLevel'] ?? null)) {
                    $slots[] = [
                        'id' => 's-' . \count($slots), 'teamId' => $pin['teamId'], 'venueId' => $pin['venueId'], 'coachId' => null,
                        'dayOfWeek' => $pin['dayOfWeek'], 'startTime' => $pin['startTime'], 'durationMinutes' => $pin['durationMinutes'],
                        'lockLevel' => 'HARD', 'pendingConstraintSuggestion' => null,
                    ];
                }
            }

            return new MockResponse(json_encode(['status' => 'completed', 'score' => 0, 'slots' => $slots, 'diagnostics' => []], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        return new GenerateScheduleHandler(
            $this->em,
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(ScheduleResultImporter::class),
            new EngineClient($engine, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new ScheduleDiagnosticsRecorder($this->em, $container->get(DiagnosticMessageBuilder::class)),
            new SolverMetricsMapper,
            $container->get(ClubGenerationLock::class),
            $container->get(TenantConnectionContext::class),
            $container->get(StructureSnapshotter::class),
            $container->get(SchedulePlanProvisioner::class),
            null,
            null,
            $container->get(SolverMetricsRecorder::class),
        );
    }

    private function hardRowOf(Schedule $schedule, Team $team): ScheduleSlotTemplate
    {
        $row = $this->em->getRepository(ScheduleSlotTemplate::class)->findOneBy(['scheduleId' => $schedule->getId(), 'teamId' => $team->getId(), 'lockLevel' => LockLevel::HARD]);
        self::assertInstanceOf(ScheduleSlotTemplate::class, $row, 'le verrou doit avoir été réimporté');

        return $row;
    }

    /** @return array<string, string> */
    private function jsonHeaders(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function star(Schedule $schedule): void
    {
        $this->season->setLiveContextScheduleId($schedule->getId());
        $this->em->flush();
    }

    private function version(ScheduleStatus $status = ScheduleStatus::COMPLETED): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('V')->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function slot(Schedule $schedule, Team $team, string $venueId, int $day, string $time, LockLevel $lock): void
    {
        $this->em->persist((new ScheduleSlotTemplate)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setScheduleId($schedule->getId())
            ->setTeamId($team->getId())->setVenueId($venueId)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))->setDurationMinutes(90)
            ->setLockLevel($lock)->setLockOrigin(LockLevel::HARD === $lock ? LockOrigin::MANUAL : LockOrigin::UNKNOWN));
    }

    private function team(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName($name)
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1)->setSessionsPerWeek(1);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** @param list<array{0:int,1:string}> $windows */
    private function venue(string $name, array $windows): string
    {
        $venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName($name)->setCanSplit(false)->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        foreach ($windows as [$day, $time]) {
            $this->em->persist((new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setVenueId($venue->getId())
                ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))->setDurationMinutes(90)->setCapacity(1));
        }

        return $venue->getId();
    }
}
