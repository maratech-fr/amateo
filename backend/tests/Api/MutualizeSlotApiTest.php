<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ClubGenerationLock;
use App\Service\MutualizeSlotService;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot 9 — le rail `POST /api/schedule-slots/{id}/mutualize` : depuis la fiche d'une séance, un
 * bloc de mutualisation est déclaré et ANCRÉ à la case, EN PLACE sur un plan de période, sans
 * verdict moteur. On prouve le geste heureux (bloc + verrous HARD/MANUAL co-localisés, activation
 * d'une équipe à 0 séance) ET chaque refus NOMMÉ / abus (403, 404, 409, 422).
 */
#[Group('integration')]
final class MutualizeSlotApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** Geste heureux — deux équipes déjà placées : bloc créé (nom posé), les deux séances déplacées+verrouillées sur la case. */
    public function testMutualizesTwoPlacedTeamsAnchoredAndLocked(): void
    {
        $ctx = $this->seedPeriod('a', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        // Le joueur « joiner » a sa propre séance AILLEURS (mardi), remplacée par la commune.
        $joinerSlot = $this->placeSlot($ctx, $ctx['teams']['joiner'], ['venueId' => $ctx['venueId'], 'dayOfWeek' => 3, 'startTime' => '18:00']);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']],
            'label' => 'Baby U7-U9',
            'replacedSlotIds' => [$joinerSlot->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['clubId' => $ctx['clubId'], 'schedulePlanId' => $ctx['planId']]);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        self::assertSame(1, $block->getCommonSessions());
        self::assertSame('Baby U7-U9', $block->getLabel());
        self::assertCount(2, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $block->getId()]));

        // Les deux séances co-localisées sur la case, verrouillées HARD/MANUAL.
        foreach ([$source->getId(), $joinerSlot->getId()] as $slotId) {
            $slot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slotId);
            self::assertInstanceOf(ScheduleSlotTemplate::class, $slot);
            self::assertSame($ctx['venueId'], $slot->getVenueId());
            self::assertSame(2, $slot->getDayOfWeek());
            self::assertSame('18:00', $slot->getStartTime()->format('H:i'));
            self::assertSame(LockLevel::HARD, $slot->getLockLevel());
            self::assertSame(LockOrigin::MANUAL, $slot->getLockOrigin());
        }
    }

    /** Équipe rejoignante à 0 séance : activée dans le plan (1 séance, source=mutualisation) + séance neuve verrouillée sur la case. */
    public function testActivatesAZeroSessionTeamMarkedMutualisation(): void
    {
        $ctx = $this->seedPeriod('b', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        // Le joiner n'a AUCUNE séance dans ce planning → activation.

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']],
            'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $body = $this->responseData();
        self::assertSame([$ctx['teams']['joiner']], $body['activatedTeamIds']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $override = $this->em->getRepository(TeamPeriodOverride::class)->findOneBy(['schedulePlanId' => $ctx['planId'], 'teamId' => $ctx['teams']['joiner']]);
        self::assertInstanceOf(TeamPeriodOverride::class, $override);
        self::assertTrue($override->isActive());
        self::assertSame(1, $override->getSessionsPerWeek());
        self::assertSame(MutualizeSlotService::OVERRIDE_SOURCE, $override->getSource());

        // Une séance NEUVE verrouillée pour le joiner sur la case.
        $created = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $ctx['scheduleId'], 'teamId' => $ctx['teams']['joiner']]);
        self::assertCount(1, $created);
        self::assertSame(LockLevel::HARD, $created[0]->getLockLevel());
        self::assertSame($ctx['venueId'], $created[0]->getVenueId());
        self::assertSame('18:00', $created[0]->getStartTime()->format('H:i'));
    }

    public function testNonManagerRefused(): void
    {
        $ctx = $this->seedPeriod('c', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $member = $this->createMember($ctx['clubId'], 'editor');

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->authHeaders($member) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]));
    }

    public function testValidatedPlanRefused(): void
    {
        $ctx = $this->seedPeriod('d', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        // Pointer la version du plan de période = « validé » (lecture seule).
        $this->scopeGucToClub($ctx['clubId']);
        self::getContainer()->get(SchedulePlanProvisioner::class)->choose($ctx['schedule']);
        $this->em->flush();

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testGenerationInProgressRefused(): void
    {
        $ctx = $this->seedPeriod('e', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($ctx['clubId'], 60);
        self::assertIsString($token);

        try {
            $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
                'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(409);
            self::assertSame('generation_in_progress', $this->responseData()['code'] ?? null);
        } finally {
            $lock->release($ctx['clubId'], $token);
        }
    }

    /** Case pleine : capacité 1 + une équipe NON-membre déjà posée à la case → 422. */
    public function testFullCaseRefused(): void
    {
        $ctx = $this->seedPeriod('f', capacity: 1);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $intruder = $this->createTeam($ctx, 'Intrus', 2, true);
        $this->placeSlot($ctx, $intruder, $ctx['case']); // non-membre, occupe déjà la case

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]));
    }

    /** Σ garde falsifiée : le joiner (2 séances/semaine) est déjà dans un bloc de 2 séances → +1 = 3 > 2 → 422. */
    public function testSumOverBudgetRefused(): void
    {
        $ctx = $this->seedPeriod('g', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $joinerSlot = $this->placeSlot($ctx, $ctx['teams']['joiner'], ['venueId' => $ctx['venueId'], 'dayOfWeek' => 3, 'startTime' => '18:00']);

        // Un AUTRE bloc de la même portée consommant déjà les 2 séances du joiner : +1 dépasse.
        $other = $this->createTeam($ctx, 'Autre', 2, true);
        $this->declareBlock($ctx, [$ctx['teams']['joiner'], $other], 2);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [$joinerSlot->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    /** Socle : l'ancre est une séance d'un planning de SAISON → période seulement → 422. */
    public function testSeasonBasePlanRefused(): void
    {
        $ctx = $this->seedSeason('h');
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]));
    }

    /** Ancre d'un AUTRE club : le filtre tenant la rend invisible → 404. */
    public function testForeignAnchorSlotNotFound(): void
    {
        $ctx = $this->seedPeriod('i', capacity: 2);
        $foreignSlot = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $other = $this->seedPeriod('j', capacity: 2); // un autre club

        $this->client->request('POST', '/api/schedule-slots/' . $foreignSlot->getId() . '/mutualize', [], [], $this->managerHeaders($other), json_encode([
            'teamIds' => [$other['teams']['source'], $other['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    /** Un bloc portant EXACTEMENT ces équipes existe déjà pour cette portée → 422. */
    public function testSameTeamSetAlreadyDeclaredRefused(): void
    {
        $ctx = $this->seedPeriod('k', capacity: 2);
        $source = $this->placeSlot($ctx, $ctx['teams']['source'], $ctx['case']);
        $this->declareBlock($ctx, [$ctx['teams']['source'], $ctx['teams']['joiner']], 1);

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->managerHeaders($ctx), json_encode([
            'teamIds' => [$ctx['teams']['source'], $ctx['teams']['joiner']], 'replacedSlotIds' => [],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $ctx['clubId']]), 'aucun second bloc identique');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Un club/manager/saison + un gymnase et sa fenêtre à la case (lundi 18h, couche PÉRIODE), une
     * équipe SOURCE et une équipe JOINER, un planning de PÉRIODE éditable.
     *
     * @return array{clubId: string, seasonId: string, planId: string, scheduleId: string, schedule: Schedule, venueId: string, categoryId: string, tierId: int, user: User, teams: array{source: string, joiner: string}, case: array{venueId: string, dayOfWeek: int, startTime: string}}
     */
    private function seedPeriod(string $suffix, int $capacity): array
    {
        $ctx = $this->seedBase($suffix, $capacity, season: false);
        $planId = $this->createPeriodPlan($ctx['clubId'], $ctx['seasonId']);
        $ctx['planId'] = $planId;
        $ctx['schedule'] = $this->linkPeriodSchedule($ctx['clubId'], $ctx['seasonId'], $planId);
        $ctx['scheduleId'] = $ctx['schedule']->getId();
        // La fenêtre de gymnase à la case vit dans la COUCHE du plan de période.
        $this->createWindow($ctx['clubId'], $ctx['seasonId'], $planId, $ctx['venueId'], 2, '18:00', $capacity);

        return $ctx;
    }

    /**
     * La variante SOCLE : le planning est la version du plan SEASON (isSeasonSchedule = true).
     *
     * @return array{clubId: string, seasonId: string, planId: ?string, scheduleId: string, schedule: Schedule, venueId: string, categoryId: string, tierId: int, user: User, teams: array{source: string, joiner: string}, case: array{venueId: string, dayOfWeek: int, startTime: string}}
     */
    private function seedSeason(string $suffix): array
    {
        $ctx = $this->seedBase($suffix, capacity: 2, season: true);
        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Socle')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $this->linkSeededSchedule($schedule); // plan SEASON
        $this->em->flush();
        $ctx['planId'] = null;
        $ctx['schedule'] = $schedule;
        $ctx['scheduleId'] = $schedule->getId();
        $this->createWindow($ctx['clubId'], $ctx['seasonId'], null, $ctx['venueId'], 2, '18:00', 2);

        return $ctx;
    }

    /**
     * Le socle commun : club + manager + saison + gymnase + 2 équipes (source, joiner).
     *
     * @return array{clubId: string, seasonId: string, venueId: string, categoryId: string, tierId: int, user: User, teams: array{source: string, joiner: string}, case: array{venueId: string, dayOfWeek: int, startTime: string}}
     */
    private function seedBase(string $suffix, int $capacity, bool $season): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = (new Club)->setName('Club lot9 ' . $suffix)->setSlug('club-lot9-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = (new User)->setEmail('m9' . $uid . '@test.com')->setFirstName('Gest')->setLastName('User')->setPasswordHash($hasher->hashPassword(new User, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = (new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($membership);

        $season = (new Season)->setClubId($club->getId())->setName('2026')->setStartDate(new DateTimeImmutable('2026-08-01'))->setEndDate(new DateTimeImmutable('2027-07-15'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->provisionSeasonPlan($season);

        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase ' . $suffix)->setSource('manual');
        $this->em->persist($venue);

        $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
        $this->em->persist($sport);
        $category = (new SportCategory)->setClubId($club->getId())->setSportId($sport->getId())->setName('U9-' . $uid)->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        $ctx = [
            'clubId' => $club->getId(), 'seasonId' => $season->getId(), 'venueId' => $venue->getId(),
            'categoryId' => $category->getId(), 'tierId' => 3, 'user' => $user,
            'case' => ['venueId' => $venue->getId(), 'dayOfWeek' => 2, 'startTime' => '18:00'],
        ];
        $ctx['teams'] = [
            'source' => $this->createTeam($ctx, 'Source', 2, true),
            'joiner' => $this->createTeam($ctx, 'Joiner', 2, true),
        ];

        return $ctx;
    }

    /**
     * @param array{clubId: string, seasonId: string, categoryId: string, tierId: int} $ctx
     */
    private function createTeam(array $ctx, string $name, int $sessionsPerWeek, bool $active): string
    {
        $this->scopeGucToClub($ctx['clubId']);
        $team = (new Team)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setSportCategoryId($ctx['categoryId'])
            ->setPriorityTierId($ctx['tierId'])
            ->setName($name)
            ->setSessionsPerWeek($sessionsPerWeek)
            ->setIsActive($active);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    /**
     * @param array{clubId: string, seasonId: string, scheduleId: string} $ctx
     * @param array{venueId: string, dayOfWeek: int, startTime: string}   $case
     */
    private function placeSlot(array $ctx, string $teamId, array $case): ScheduleSlotTemplate
    {
        $this->scopeGucToClub($ctx['clubId']);
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setScheduleId($ctx['scheduleId'])
            ->setTeamId($teamId)
            ->setVenueId($case['venueId'])
            ->setDayOfWeek($case['dayOfWeek'])
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $case['startTime']))
            ->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    private function linkPeriodSchedule(string $clubId, string $seasonId, string $planId): Schedule
    {
        $this->scopeGucToClub($clubId);
        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Période')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $schedule->setSchedulePlanId($planId);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function createWindow(string $clubId, string $seasonId, ?string $planId, string $venueId, int $dayOfWeek, string $startTime, int $capacity): void
    {
        $this->scopeGucToClub($clubId);
        $window = (new VenueTrainingSlot)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $startTime))
            ->setDurationMinutes(90)
            ->setCapacity($capacity);
        $window->setSchedulePlanId($planId);
        $this->em->persist($window);
        $this->em->flush();
    }

    /**
     * @param array{clubId: string, seasonId: string, planId?: ?string} $ctx
     * @param list<string>                                              $teamIds
     */
    private function declareBlock(array $ctx, array $teamIds, int $commonSessions): void
    {
        $this->scopeGucToClub($ctx['clubId']);
        $planId = $ctx['planId'] ?? null;
        $block = (new SharedTrainingBlock)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($planId)->setCommonSessions($commonSessions);
        $this->em->persist($block);
        foreach ($teamIds as $teamId) {
            $member = (new SharedTrainingBlockTeam)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($teamId);
            $this->em->persist($member);
        }
        $this->em->flush();
    }

    private function createMember(string $clubId, string $role): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = (new User)->setEmail($role . $uid . '@test.com')->setFirstName('N')->setLastName('Member')->setPasswordHash($hasher->hashPassword(new User, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = (new ClubUser)->setClubId($clubId)->setUserId($user->getId())->setRole($role)->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array{user: User} $ctx
     *
     * @return array{HTTP_AUTHORIZATION: string, CONTENT_TYPE: string}
     */
    private function managerHeaders(array $ctx): array
    {
        return $this->authHeaders($ctx['user']) + ['CONTENT_TYPE' => 'application/json'];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
