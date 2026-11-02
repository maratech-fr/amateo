<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR BLOQUANT — « seule la version CHARGÉE se modifie et se valide » (axes planning lifecycle +
 * generation pipeline ; plan « version chargée », A3 ; décision fondateur : une version non chargée
 * se consulte, ne se modifie pas, ne se valide pas, plans de saison ET de période).
 *
 * Contrat attendu de l'implémentation :
 *  - `App\Service\VersionEditability::check(Schedule): ?string` rend null, `not_loaded_version` ou
 *    `chosen_version` ; même code pour les 9 guichets de `ManualEditController` et la capacité ;
 *  - refus : 409 `{code, error}`, `error` = « Chargez cette version pour la modifier. » ;
 *  - `capabilities.canEdit` (bool) et `capabilities.editBlockedReason` (code ci-dessus ou null) ;
 *  - swap et mutualize-at-case sont jugés sur la séance source, move-group et remove-group sur le
 *    `scheduleId` du corps ;
 *  - POST validate sur une version non chargée : 409 `not_loaded_version`, « Chargez cette version
 *    pour la valider. », et `capabilities.canValidate` à false ;
 *  - POST fill sur une version de période autre que la source : même 409, aucune version créée ;
 *  - période : la « chargée » est la source des verrous (`lockSourceScheduleIdOfPlan`), « Charger »
 *    (load-locks) reste gratuit.
 *
 * « Acceptée » signifie ici : la garde laisse passer. Le verdict moteur ou la validation du corps
 * peuvent répondre autre chose, c'est prouvé par les tests de chaque guichet.
 */
#[Group('phase1')]
#[Group('integration')]
final class LoadedVersionIsTheOnlyEditableTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const array GUICHETS = ['lock', 'move', 'place-slot', 'move-group', 'swap', 'remove', 'remove-group', 'mutualize', 'mutualize-at-case'];

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    private string $venue;

    private string $teamA;

    private string $teamB;

    /** @return iterable<string, array{string}> */
    public static function guichets(): iterable
    {
        foreach (self::GUICHETS as $guichet) {
            yield $guichet => [$guichet];
        }
    }

    /** Saison : le guichet refuse une version non chargée, sans rien écrire. */
    #[DataProvider('guichets')]
    public function testASeasonVersionNotLoadedIsRefusedAndNothingIsWritten(string $guichet): void
    {
        $older = $this->seasonVersion();
        $loaded = $this->seasonVersion();
        $this->season->setLiveContextScheduleId($loaded->getId());
        $this->em->flush();
        [$s1, $s2] = $this->slotsOf($older);
        $before = $this->fingerprint();

        $this->call($guichet, $older, $s1, $s2);

        $this->assertRefused('not_loaded_version', $guichet);
        self::assertSame('Chargez cette version pour la modifier.', $this->decode()['error'] ?? null);
        self::assertSame($before, $this->fingerprint(), 'rien n’est écrit');
    }

    /** Saison : sans étoile, la version chargée est la dernière terminée, les autres sont refusées. */
    #[DataProvider('guichets')]
    public function testWithoutStarOnlyTheLatestCompletedIsEditable(string $guichet): void
    {
        $older = $this->seasonVersion();
        $latest = $this->seasonVersion();
        [$s1, $s2] = $this->slotsOf($older);
        [$l1, $l2] = $this->slotsOf($latest);

        $this->call($guichet, $older, $s1, $s2);
        $this->assertRefused('not_loaded_version', $guichet);

        $this->call($guichet, $latest, $l1, $l2);
        $this->assertGuardLetThrough($guichet);
    }

    /** Saison : la version chargée passe la garde. */
    #[DataProvider('guichets')]
    public function testTheLoadedSeasonVersionPassesTheGuard(string $guichet): void
    {
        $this->seasonVersion();
        $loaded = $this->seasonVersion();
        $older = $this->seasonVersion();
        $this->season->setLiveContextScheduleId($loaded->getId());
        $this->em->flush();
        [$s1, $s2] = $this->slotsOf($loaded);
        self::assertNotSame($older->getId(), $loaded->getId());

        $this->call($guichet, $loaded, $s1, $s2);

        $this->assertGuardLetThrough($guichet);
    }

    /** Une version validée répond son propre code : le front ne confond pas « rouvrir » et « charger ». */
    #[DataProvider('guichets')]
    public function testAChosenVersionAnswersItsOwnCode(string $guichet): void
    {
        $chosen = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('V')->setStatus(ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($chosen);
        $this->seedSlots($chosen);
        [$s1, $s2] = $this->slotsOf($chosen);
        $before = $this->fingerprint();

        $this->call($guichet, $chosen, $s1, $s2);

        $this->assertRefused('chosen_version', $guichet);
        self::assertSame($before, $this->fingerprint());
    }

    /** Période : même règle, la source des verrous est seule modifiable. */
    #[DataProvider('guichets')]
    public function testAPeriodVersionOtherThanTheSourceIsRefused(string $guichet): void
    {
        $entry = $this->period();
        $older = $this->periodVersion($entry);
        $source = $this->periodVersion($entry);
        [$s1, $s2] = $this->slotsOf($older);
        [$l1, $l2] = $this->slotsOf($source);
        $before = $this->fingerprint();

        $this->call($guichet, $older, $s1, $s2);
        $this->assertRefused('not_loaded_version', $guichet);
        self::assertSame($before, $this->fingerprint());

        $this->call($guichet, $source, $l1, $l2);
        $this->assertGuardLetThrough($guichet);
    }

    /** Période : « Charger » reste gratuit et déplace la version modifiable. */
    public function testLoadingAPeriodVersionMakesItTheEditableOne(): void
    {
        $entry = $this->period();
        $older = $this->periodVersion($entry);
        $source = $this->periodVersion($entry);

        $this->client->request('POST', '/api/schedules/' . $older->getId() . '/load-locks', [], [], $this->headers());
        self::assertResponseIsSuccessful();

        [$o1, $o2] = $this->slotsOf($older);
        [$s1, $s2] = $this->slotsOf($source);
        $this->call('lock', $older, $o1, $o2);
        self::assertResponseIsSuccessful();
        $this->call('lock', $source, $s1, $s2);
        $this->assertRefused('not_loaded_version', 'lock');
    }

    /** swap et mutualize-at-case se jugent sur la source : l'autre séance ne sauve ni ne condamne le geste. */
    public function testSwapAndMutualizeAtCaseAreJudgedOnTheSource(): void
    {
        $older = $this->seasonVersion();
        $loaded = $this->seasonVersion();
        [$fromOlder] = $this->slotsOf($older);
        [$fromLoaded] = $this->slotsOf($loaded);

        foreach ([['swap', 'slotId', 'otherSlotId'], ['mutualize-at-case', 'sourceSlotId', 'targetSlotId']] as [$route, $src, $tgt]) {
            $this->post('/api/schedule-slots/' . $route, [$src => $fromOlder->getId(), $tgt => $fromLoaded->getId()]);
            $this->assertRefused('not_loaded_version', $route . ' depuis une version non chargée');

            $this->post('/api/schedule-slots/' . $route, [$src => $fromLoaded->getId(), $tgt => $fromOlder->getId()]);
            $this->assertGuardLetThrough($route . ' depuis la version chargée');
        }
    }

    /** move-group et remove-group se jugent sur le `scheduleId` du corps. */
    public function testGroupGesturesAreJudgedOnTheBodyScheduleId(): void
    {
        $older = $this->seasonVersion();
        $loaded = $this->seasonVersion();

        foreach (['move-group', 'remove-group'] as $route) {
            $this->post('/api/schedule-slots/' . $route, ['scheduleId' => $older->getId(), 'blockId' => '77777777-7777-4777-8777-777777777777']);
            $this->assertRefused('not_loaded_version', $route);

            $this->post('/api/schedule-slots/' . $route, ['scheduleId' => $loaded->getId(), 'blockId' => '77777777-7777-4777-8777-777777777777']);
            $this->assertGuardLetThrough($route);
        }
    }

    /** La capacité affichée vaut le verdict du guichet, sur les trois états, en saison et en période. */
    public function testTheCapabilityIsTheVerdict(): void
    {
        $older = $this->seasonVersion();
        $loaded = $this->seasonVersion();
        $entry = $this->period();
        $periodOlder = $this->periodVersion($entry);
        $periodSource = $this->periodVersion($entry);

        foreach ([[$older, false, 'not_loaded_version'], [$loaded, true, null], [$periodOlder, false, 'not_loaded_version'], [$periodSource, true, null]] as [$schedule, $canEdit, $reason]) {
            $capabilities = $this->capabilitiesOf($schedule);
            self::assertSame($canEdit, $capabilities['canEdit'] ?? null);
            self::assertSame($reason, $capabilities['editBlockedReason'] ?? null);
            self::assertSame($canEdit, $capabilities['canValidate'] ?? null, 'valider suit la même règle');

            [$slot, $other] = $this->slotsOf($schedule);
            $this->call('lock', $schedule, $slot, $other);
            self::assertSame($canEdit ? 200 : 409, $this->client->getResponse()->getStatusCode());
        }
    }

    /** La collection (ce que lit l'écran) dit la même chose que l'item. */
    public function testTheCollectionServesTheSameVerdict(): void
    {
        $older = $this->seasonVersion();
        $loaded = $this->seasonVersion();

        $this->client->request('GET', '/api/schedules?seasonId=' . $this->season->getId(), [], [], ['HTTP_ACCEPT' => 'application/ld+json'] + $this->headers());
        self::assertResponseIsSuccessful();
        $byId = [];
        foreach ((array) ($this->decode()['member'] ?? []) as $row) {
            self::assertIsArray($row);
            $byId[$row['id']] = $row['capabilities'] ?? [];
        }

        self::assertFalse($byId[$older->getId()]['canEdit'] ?? null);
        self::assertTrue($byId[$loaded->getId()]['canEdit'] ?? null);
    }

    /** L'annulation passe par les mêmes guichets : charger une autre version la rend caduque. */
    public function testUndoIsRefusedOnceAnotherVersionWasLoaded(): void
    {
        $older = $this->seasonVersion();
        $loaded = $this->seasonVersion();
        [$slot] = $this->slotsOf($loaded);

        $this->post('/api/schedule-slots/' . $slot->getId() . '/manual-edit/lock', ['lockLevel' => 'HARD']);
        self::assertResponseIsSuccessful();

        $this->season->setLiveContextScheduleId($older->getId());
        $this->em->flush();

        $this->post('/api/schedule-slots/' . $slot->getId() . '/manual-edit/lock', ['lockLevel' => 'NONE']);
        $this->assertRefused('not_loaded_version', 'annuler après avoir chargé une autre version');
    }

    /** Valider une version non chargée est refusé, sans détruire les sœurs ; la chargée se valide. */
    public function testValidatingANonLoadedVersionIsRefused(): void
    {
        $older = $this->seasonVersion();
        $loaded = $this->seasonVersion();

        $this->post('/api/schedules/' . $older->getId() . '/validate', []);
        $this->assertRefused('not_loaded_version', 'valider');
        self::assertSame('Chargez cette version pour la valider.', $this->decode()['error'] ?? null);
        self::assertNull($this->chosenPlanVersion($this->season), 'rien n’est pointé');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($loaded->getId()), 'les sœurs survivent');

        $this->post('/api/schedules/' . $loaded->getId() . '/validate', []);
        self::assertResponseIsSuccessful();
    }

    /** Période : même refus de validation, la source se valide. */
    public function testValidatingAPeriodVersionOtherThanTheSourceIsRefused(): void
    {
        $this->chooseSocle();
        $entry = $this->period();
        $older = $this->periodVersion($entry);
        $source = $this->periodVersion($entry);

        $this->post('/api/schedules/' . $older->getId() . '/validate', []);
        $this->assertRefused('not_loaded_version', 'valider une période');

        $this->post('/api/schedules/' . $source->getId() . '/validate', []);
        self::assertResponseIsSuccessful();
    }

    /**
     * « Combler » dérive une V+1 de la version nommée : hors de la source il aurait figé une version
     * que le gestionnaire ne travaille pas. Refusé sans créer de version ; la source part toujours.
     * Le contrôleur ne distingue ni séance réservée ni libre : le verdict porte sur la version seule.
     * Jumeau écran : PlanningPage.test.tsx, « Combler » grisé sur une version non source.
     */
    public function testFillingAPeriodVersionOtherThanTheSourceIsRefused(): void
    {
        $this->chooseSocle();
        $entry = $this->period();
        $older = $this->periodVersion($entry);
        $source = $this->periodVersion($entry);
        $versions = $this->versionCount();

        $this->post('/api/schedules/' . $older->getId() . '/fill', []);

        $this->assertRefused('not_loaded_version', 'combler une version non source');
        self::assertStringContainsString('Chargez cette version', (string) ($this->decode()['error'] ?? ''));
        self::assertSame($versions, $this->versionCount(), 'aucune version créée, donc aucun solve lancé');

        $this->post('/api/schedules/' . $source->getId() . '/fill', []);

        self::assertSame(202, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame($versions + 1, $this->versionCount(), 'la source dérive sa V+1');
    }

    /** « Charger » déplace aussi le droit de combler : la version chargée part, l'ancienne source est refusée. */
    public function testLoadingAPeriodVersionMovesTheRightToFill(): void
    {
        $this->chooseSocle();
        $entry = $this->period();
        $older = $this->periodVersion($entry);
        $source = $this->periodVersion($entry);

        $this->client->request('POST', '/api/schedules/' . $older->getId() . '/load-locks', [], [], $this->headers());
        self::assertResponseIsSuccessful();

        $this->post('/api/schedules/' . $source->getId() . '/fill', []);
        $this->assertRefused('not_loaded_version', 'combler l’ancienne source');

        $this->post('/api/schedules/' . $older->getId() . '/fill', []);
        self::assertSame(202, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Editable ' . $uid)->setSlug('editable-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('editable' . $uid . '@test.com')->setFirstName('E')->setLastName('D');
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

        $venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('G')->setCanSplit(false)->setSource('manual');
        $this->em->persist($venue);
        $teamA = $this->team('A');
        $teamB = $this->team('B');
        $this->em->flush();
        $this->venue = $venue->getId();
        $this->teamA = $teamA->getId();
        $this->teamB = $teamB->getId();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function call(string $guichet, Schedule $schedule, ScheduleSlotTemplate $slot, ScheduleSlotTemplate $other): void
    {
        $id = $slot->getId();
        $blockId = '77777777-7777-4777-8777-777777777777';
        [$url, $body] = match ($guichet) {
            'lock' => ['/api/schedule-slots/' . $id . '/manual-edit/lock', ['lockLevel' => 'HARD']],
            'move' => ['/api/schedule-slots/' . $id . '/move', ['dayOfWeek' => 4, 'startTime' => '20:00', 'venueId' => $this->venue]],
            'place-slot' => ['/api/schedules/' . $schedule->getId() . '/place-slot', ['teamId' => $this->teamA, 'dayOfWeek' => 5, 'startTime' => '20:00', 'venueId' => $this->venue, 'durationMinutes' => 90]],
            'move-group' => ['/api/schedule-slots/move-group', ['scheduleId' => $schedule->getId(), 'blockId' => $blockId, 'fromDayOfWeek' => 1, 'fromStartTime' => '18:00', 'fromVenueId' => $this->venue, 'dayOfWeek' => 4, 'startTime' => '20:00', 'venueId' => $this->venue]],
            'swap' => ['/api/schedule-slots/swap', ['slotId' => $id, 'otherSlotId' => $other->getId()]],
            'remove' => ['/api/schedule-slots/' . $id . '/remove', []],
            'remove-group' => ['/api/schedule-slots/remove-group', ['scheduleId' => $schedule->getId(), 'blockId' => $blockId, 'dayOfWeek' => 1, 'startTime' => '18:00', 'venueId' => $this->venue]],
            'mutualize' => ['/api/schedule-slots/' . $id . '/mutualize', ['teamIds' => [$this->teamB]]],
            'mutualize-at-case' => ['/api/schedule-slots/mutualize-at-case', ['sourceSlotId' => $id, 'targetSlotId' => $other->getId()]],
            default => self::fail('guichet inconnu ' . $guichet),
        };
        $this->post($url, $body);
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body): void
    {
        $this->client->request('POST', $url, [], [], $this->headers(), json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function assertRefused(string $code, string $what): void
    {
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), $what . ' : ' . $this->client->getResponse()->getContent());
        self::assertSame($code, $this->decode()['code'] ?? null, $what);
    }

    private function assertGuardLetThrough(string $what): void
    {
        $response = $this->client->getResponse();
        self::assertNotContains($this->decode()['code'] ?? null, ['not_loaded_version', 'chosen_version'], $what . ' : ' . $response->getContent());
        self::assertLessThan(500, $response->getStatusCode(), $what . ' : ' . $response->getContent());
    }

    /** @return array<string, mixed> */
    private function capabilitiesOf(Schedule $schedule): array
    {
        $this->client->request('GET', '/api/schedules/' . $schedule->getId(), [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $capabilities = $this->decode()['capabilities'] ?? null;
        self::assertIsArray($capabilities, 'l’item porte ses capacités');

        return $capabilities;
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    /** L'état écrit des séances du club, pour prouver qu'un refus n'a rien touché. */
    private function fingerprint(): string
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, schedule_id, team_id, venue_id, day_of_week, start_time, lock_level FROM schedule_slot_template WHERE club_id = :c ORDER BY id',
            ['c' => $this->club->getId()],
        );

        return json_encode($rows, \JSON_THROW_ON_ERROR);
    }

    private function seasonVersion(): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('V')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();
        $this->seedSlots($schedule);

        return $schedule;
    }

    private function periodVersion(CalendarEntry $entry): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('P')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule, $entry->getId());
        $this->em->flush();
        $this->seedSlots($schedule);

        return $schedule;
    }

    private function chooseSocle(): void
    {
        $this->choosePlanVersion((new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('Socle')->setStatus(ScheduleStatus::COMPLETED));
    }

    private function versionCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule WHERE club_id = :c', ['c' => $this->club->getId()]);
    }

    private function period(): CalendarEntry
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setTitle('Fermeture')->setStartDate(new DateTimeImmutable('+30 days'))->setEndDate(new DateTimeImmutable('+40 days'));
        $this->em->persist($entry);
        $this->em->flush();
        self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());

        return $entry;
    }

    private function seedSlots(Schedule $schedule): void
    {
        foreach ([[$this->teamA, 1, '18:00'], [$this->teamB, 2, '19:00']] as [$team, $day, $time]) {
            $this->em->persist((new ScheduleSlotTemplate)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setScheduleId($schedule->getId())
                ->setTeamId($team)->setVenueId($this->venue)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))->setDurationMinutes(90)->setLockLevel(LockLevel::NONE));
        }
        $this->em->flush();
    }

    /** @return list<ScheduleSlotTemplate> */
    private function slotsOf(Schedule $schedule): array
    {
        return array_values($this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $schedule->getId()], ['dayOfWeek' => 'ASC']));
    }

    private function team(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName($name)
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1)->setSessionsPerWeek(1);
        $this->em->persist($team);

        return $team;
    }
}
