<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
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
 * P2-46 PR-2 — la règle d'occupation exclusive + le rail d'écriture batch d'un entraînement
 * mutualisé, éprouvés dans les DEUX sens (chaque règle refuse à bon escient ET laisse passer le
 * cas sain). Axe structurant `constraint semantics` (§7.1) : la garde d'écriture est ce qui
 * empêche le solveur de sortir INFEASIBLE loin de sa cause (le constat vit côté moteur,
 * `engine/tests/semantic/test_shared_group_over_reserved_is_infeasible.py`).
 */
#[Group('phase1')]
#[Group('integration')]
final class GroupReservationApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private User $user;

    private Season $season;

    private string $token;

    // ── (a) EXCLUSIVITÉ ──────────────────────────────────────────────────────────

    public function testGroupOnAnEmptyCaseSucceeds(): void
    {
        [$t1, $t2] = [$this->team(2), $this->team(2)];
        $venue = $this->venue(false);
        $group = $this->group(null, [$t1, $t2], 2);

        $this->postGroup($group->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->reservationCountOnCase($venue->getId(), 2, '18:00'));
    }

    public function testGroupOnACaseAlreadyHoldingAReservationIsRefused(): void
    {
        [$t1, $t2, $t3] = [$this->team(2), $this->team(2), $this->team(2)];
        $venue = $this->venue(false);
        $group = $this->group(null, [$t1, $t2], 2);

        // Une réservation individuelle occupe déjà la case.
        $this->postIndividual($t3->getId(), $venue->getId(), 3, '18:00', null);
        self::assertResponseStatusCodeSame(201);

        $this->postGroup($group->getId(), $venue->getId(), 3, '18:00', null);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('déjà occupé', $this->body());
        // Aucune réservation du groupe n'a été écrite (la case ne porte que l'individuelle).
        self::assertSame(1, $this->reservationCountOnCase($venue->getId(), 3, '18:00'));
    }

    // ── (b) RÉCIPROQUE ───────────────────────────────────────────────────────────

    public function testIndividualOnACaseHoldingACompleteGroupIsRefused(): void
    {
        [$t1, $t2, $t3] = [$this->team(2), $this->team(2), $this->team(2)];
        $venue = $this->venue(false);
        $group = $this->group(null, [$t1, $t2], 1);

        $this->postGroup($group->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(201);

        // Une équipe étrangère tente de rejoindre la case déjà groupe-complète.
        $this->postIndividual($t3->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('mutualisé', $this->body());
        self::assertSame(2, $this->reservationCountOnCase($venue->getId(), 2, '18:00'));
    }

    // ── (c) PLAFOND K ────────────────────────────────────────────────────────────

    public function testGroupBeyondItsCommonSessionsIsRefused(): void
    {
        [$t1, $t2] = [$this->team(3), $this->team(3)];
        $venue = $this->venue(false);
        $group = $this->group(null, [$t1, $t2], 1); // K = 1

        $this->postGroup($group->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(201);

        // 2ᵉ case pour un groupe à K=1 : au-delà de commonSessions.
        $this->postGroup($group->getId(), $venue->getId(), 4, '18:00', null);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('séance', $this->body());
        self::assertStringContainsString('commune', $this->body());
        self::assertSame(0, $this->reservationCountOnCase($venue->getId(), 4, '18:00'));
    }

    // ── (d) PLAFOND PAR MEMBRE ───────────────────────────────────────────────────

    public function testGroupPushingAMemberOverItsWeeklySessionsIsRefused(): void
    {
        $t1 = $this->team(1); // une seule séance par semaine
        $t2 = $this->team(3);
        $venue = $this->venue(false);
        $group = $this->group(null, [$t1, $t2], 1);

        // t1 a déjà consommé sa séance ailleurs.
        $this->postIndividual($t1->getId(), $venue->getId(), 5, '18:00', null);
        self::assertResponseStatusCodeSame(201);

        $this->postGroup($group->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('séances par semaine', $this->body());
        // Atomicité : ni t1 ni t2 n'a été écrit sur la case du groupe.
        self::assertSame(0, $this->reservationCountOnCase($venue->getId(), 2, '18:00'));
    }

    // ── (e) CAPACITÉ (la dette, enfin gardée serveur) ────────────────────────────

    public function testIndividualReservationsAreCappedByTheSlotCapacity(): void
    {
        [$t1, $t2, $t3] = [$this->team(3), $this->team(3), $this->team(3)];
        $venue = $this->venue(true); // gymnase divisible
        $this->slot($venue->getId(), 2, '18:00', 2, null); // capacité 2

        $this->postIndividual($t1->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(201);
        // Sous la limite : la 2ᵉ passe.
        $this->postIndividual($t2->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(201);
        // Dépassement : la 3ᵉ est refusée.
        $this->postIndividual($t3->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('plein', $this->body());
        self::assertSame(2, $this->reservationCountOnCase($venue->getId(), 2, '18:00'));
    }

    public function testNonDivisibleVenueCapsIndividualReservationsAtOne(): void
    {
        [$t1, $t2] = [$this->team(3), $this->team(3)];
        $venue = $this->venue(false); // non divisible → capacité 1
        $this->slot($venue->getId(), 2, '18:00', 5, null); // capacity 5 stockée, mais canSplit=false ⇒ 1

        $this->postIndividual($t1->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(201);
        $this->postIndividual($t2->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(422, 'un gymnase non divisible plafonne à 1 quelle que soit la capacité du créneau');
    }

    // ── PORTÉE socle / période ───────────────────────────────────────────────────

    public function testASocleGroupReservedInAPeriodIsRefusedForScopeMismatch(): void
    {
        [$t1, $t2] = [$this->team(2), $this->team(2)];
        $venue = $this->venue(false);
        $socleGroup = $this->group(null, [$t1, $t2], 2); // groupe du SOCLE
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());

        $this->postGroup($socleGroup->getId(), $venue->getId(), 2, '18:00', $planId);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('n\'appartient pas à ce planning', $this->body());
        self::assertSame(0, $this->reservationCountOnCase($venue->getId(), 2, '18:00'));
    }

    public function testAPeriodGroupReservedOnTheSocleIsRefusedForScopeMismatch(): void
    {
        [$t1, $t2] = [$this->team(2), $this->team(2)];
        $venue = $this->venue(false);
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $periodGroup = $this->group($planId, [$t1, $t2], 2); // groupe de PÉRIODE

        $this->postGroup($periodGroup->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('n\'appartient pas à ce planning', $this->body());
    }

    public function testAPeriodGroupReservedInItsOwnPeriodSucceeds(): void
    {
        [$t1, $t2] = [$this->team(2), $this->team(2)];
        $venue = $this->venue(false);
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $periodGroup = $this->group($planId, [$t1, $t2], 2);

        $this->postGroup($periodGroup->getId(), $venue->getId(), 2, '18:00', $planId);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->reservationCountOnCaseScoped($venue->getId(), 2, '18:00', $planId));
    }

    // ── TENANT ───────────────────────────────────────────────────────────────────

    public function testAGroupOfAnotherClubIsInvisibleAndWritesNothing(): void
    {
        // Club B, avec son propre groupe, dans son propre tenant.
        [$clubB, $seasonB] = $this->makeOtherClub();
        $this->scopeGucToClub($clubB->getId());
        $tb1 = $this->teamFor($clubB, $seasonB, 2);
        $tb2 = $this->teamFor($clubB, $seasonB, 2);
        $foreignGroup = $this->groupFor($clubB, $seasonB, null, [$tb1, $tb2], 2);
        $venueB = $this->venueFor($clubB, $seasonB, false);
        $this->em->flush();
        // On repasse le GUC sur le club A (le client HTTP, lui, dérive son tenant du JWT de A).
        $this->scopeGucToClub($this->club->getId());

        $this->postGroup($foreignGroup->getId(), $venueB->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->reservationCountAll());
    }

    // ── SAISON ARCHIVÉE ──────────────────────────────────────────────────────────

    public function testWritingOnAnArchivedSeasonIs409(): void
    {
        [$user, , $seasons] = $this->clubWithThreeSeasons();
        [$past] = $seasons;

        // Le listener SeasonReadonlyGuardListener (kernel.controller) refuse AVANT __invoke.
        $this->client->request('POST', '/api/reservations/group', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'sharedTrainingGroupId' => '11111111-1111-4111-8111-111111111111',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'dayOfWeek' => 2, 'startTime' => '18:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    // ── PÉREMPTION DES PLANNINGS (listener, sans le modifier) ─────────────────────

    public function testBatchWriteMarksCompletedSchedulesStale(): void
    {
        [$t1, $t2] = [$this->team(2), $this->team(2)];
        $venue = $this->venue(false);
        $group = $this->group(null, [$t1, $t2], 2);

        // Un planning COMPLETED du plan SEASON, marqueur remis à zéro.
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('S')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();
        $this->em->clear();
        $managed = $this->em->find(Schedule::class, $schedule->getId());
        self::assertInstanceOf(Schedule::class, $managed);
        $managed->setResourcesChangedSinceGeneration(false);
        $this->em->flush();
        $this->em->clear();

        $this->postGroup($group->getId(), $venue->getId(), 2, '18:00', null);
        self::assertResponseStatusCodeSame(201);

        $this->em->clear();
        $reloaded = $this->em->find(Schedule::class, $schedule->getId());
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertTrue($reloaded->isResourcesChangedSinceGeneration(), 'l\'écriture batch doit périmer le planning de saison COMPLETED');
    }

    // ── Parité de VALIDATION avec le rail unitaire (revue sécu 2026-08-23) ───────

    /**
     * Un identifiant MALFORMÉ ne doit jamais atteindre Postgres : les colonnes visées sont des
     * `uuid` natifs, où `WHERE id = 'abc'` lève un 22P02 — donc un 500 là où le rail unitaire
     * rend un 422 propre (`ReservationInput` porte `#[Assert\Uuid]`). Classe de défaut que le
     * dépôt documente DEUX fois (`AssertsSchedulePlanExistsTrait`,
     * `TenantFilterListener::findClubSeason`) et que ce rail réintroduisait.
     */
    public function testAMalformedIdentifierIsRefusedBeforeReachingPostgres(): void
    {
        [$t1, $t2] = [$this->team(2), $this->team(2)];
        $venue = $this->venue(false);
        $group = $this->group(null, [$t1, $t2], 2);

        foreach ([['abc', $venue->getId()], [$group->getId(), 'not-a-uuid'], ['', $venue->getId()]] as [$groupId, $venueId]) {
            $this->client->request('POST', '/api/reservations/group', [], [], [
                'HTTP_X-Club-Id' => $this->club->getId(),
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
                'CONTENT_TYPE' => 'application/json',
            ], json_encode([
                'sharedTrainingGroupId' => $groupId,
                'venueId' => $venueId,
                'dayOfWeek' => 2, 'startTime' => '18:00',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(400, \sprintf('id « %s »/« %s » : attendu 400, jamais un 500 Postgres', $groupId, $venueId));
        }
        self::assertCount(0, $this->em->getRepository(Reservation::class)->findBy(['clubId' => $this->club->getId()]));
    }

    /**
     * Les bornes de `dayOfWeek` et `durationMinutes` du rail unitaire (`#[Assert\Range]`)
     * s'appliquent AUSSI ici : sans elles, `dayOfWeek: 8` s'écrit en base et dégrade le solve
     * en SILENCE (le schéma moteur ne borne pas ce champ). Un rail batch ne peut pas être plus
     * permissif que son rail unitaire.
     */
    public function testOutOfRangeDayOrDurationIsRefused(): void
    {
        [$t1, $t2] = [$this->team(2), $this->team(2)];
        $venue = $this->venue(false);
        $group = $this->group(null, [$t1, $t2], 2);

        foreach ([['dayOfWeek' => 8], ['dayOfWeek' => 0], ['durationMinutes' => 5000], ['durationMinutes' => 5]] as $override) {
            $body = [
                'sharedTrainingGroupId' => $group->getId(),
                'venueId' => $venue->getId(),
                'dayOfWeek' => 2, 'startTime' => '18:00', 'durationMinutes' => 90,
            ];
            $this->client->request('POST', '/api/reservations/group', [], [], [
                'HTTP_X-Club-Id' => $this->club->getId(),
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
                'CONTENT_TYPE' => 'application/json',
            ], json_encode(array_merge($body, $override), \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(422, \sprintf('%s hors bornes : attendu 422', array_key_first($override)));
        }
        self::assertCount(0, $this->em->getRepository(Reservation::class)->findBy(['clubId' => $this->club->getId()]));
    }

    // ── SEC-07 : parité stricte avec POST /reservations ──────────────────────────

    public function testNonManagementMemberIsForbidden(): void
    {
        $editorToken = $this->addActiveMember($this->club->getId(), 'member');

        $this->client->request('POST', '/api/reservations/group', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'sharedTrainingGroupId' => '11111111-1111-4111-8111-111111111111',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'dayOfWeek' => 2, 'startTime' => '18:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Grp Club ' . $uid)->setSlug('grp-club-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('grp' . $uid . '@test.com');
        $this->user->setFirstName('Grp');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    private function team(int $sessionsPerWeek): Team
    {
        $team = $this->teamFor($this->club, $this->season, $sessionsPerWeek);
        $this->em->flush();

        return $team;
    }

    private function teamFor(Club $club, Season $season, int $sessionsPerWeek): Team
    {
        $team = (new Team)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId($this->uuid())->setPriorityTierId(3)
            ->setName('T' . substr($this->uuid(), 0, 6))
            ->setSessionsPerWeek($sessionsPerWeek)->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    private function venue(bool $canSplit): Venue
    {
        $venue = $this->venueFor($this->club, $this->season, $canSplit);
        $this->em->flush();

        return $venue;
    }

    private function venueFor(Club $club, Season $season, bool $canSplit): Venue
    {
        $venue = (new Venue)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Gym ' . substr($this->uuid(), 0, 6))
            ->setSource('manual')->setCanSplit($canSplit);
        $this->em->persist($venue);

        return $venue;
    }

    private function slot(string $venueId, int $dayOfWeek, string $startTime, int $capacity, ?string $planId): void
    {
        $slot = (new VenueTrainingSlot)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($venueId)->setDayOfWeek($dayOfWeek)
            ->setStartTime(new DateTimeImmutable($startTime))->setDurationMinutes(90)
            ->setCapacity($capacity)->setSchedulePlanId($planId);
        $this->em->persist($slot);
        $this->em->flush();
    }

    /**
     * @param list<Team> $teams
     */
    private function group(?string $planId, array $teams, int $commonSessions): SharedTrainingGroup
    {
        $group = $this->groupFor($this->club, $this->season, $planId, $teams, $commonSessions);
        $this->em->flush();

        return $group;
    }

    /**
     * @param list<Team> $teams
     */
    private function groupFor(Club $club, Season $season, ?string $planId, array $teams, int $commonSessions): SharedTrainingGroup
    {
        $group = (new SharedTrainingGroup)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)->setCommonSessions($commonSessions);
        $this->em->persist($group);
        foreach ($teams as $team) {
            $member = (new SharedTrainingGroupTeam)
                ->setClubId($club->getId())->setSeasonId($season->getId())
                ->setSchedulePlanId($planId)->setGroupId($group->getId())->setTeamId($team->getId());
            $this->em->persist($member);
        }

        return $group;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function makeOtherClub(): array
    {
        $uid = uniqid('b', true);
        $club = (new Club)
            ->setName('Other ' . $uid)->setSlug('other-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        // RLS : la saison s'insère sous le GUC de SON club — on bascule AVANT de la persister.
        $this->scopeGucToClub($club->getId());
        $this->em->persist($club);
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    /**
     * @return array{0: User, 1: Club, 2: list<Season>}
     */
    private function clubWithThreeSeasons(): array
    {
        $uid = uniqid('r', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = (new Club)
            ->setName('Archived ' . $uid)->setSlug('archived-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('arch' . $uid . '@test.com');
        $user->setFirstName('Arch');
        $user->setLastName('Ived');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $past = $this->seasonYear($club, $year - 1);
        $current = $this->seasonYear($club, $year);
        $draft = $this->seasonYear($club, $year + 1);
        $this->em->flush();

        return [$user, $club, [$past, $current, $draft]];
    }

    private function seasonYear(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function postGroup(string $groupId, string $venueId, int $dayOfWeek, string $startTime, ?string $planId): void
    {
        $this->client->request('POST', '/api/reservations/group', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'sharedTrainingGroupId' => $groupId,
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
            'durationMinutes' => 90,
            'schedulePlanId' => $planId,
        ], \JSON_THROW_ON_ERROR));
    }

    private function postIndividual(string $teamId, string $venueId, int $dayOfWeek, string $startTime, ?string $planId): void
    {
        $this->client->request('POST', '/api/reservations', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'teamId' => $teamId,
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
            'durationMinutes' => 90,
            'schedulePlanId' => $planId,
        ], \JSON_THROW_ON_ERROR));
    }

    /** Le message d'erreur DÉCODÉ (le corps JSON échappe l'unicode ; le front, lui, le rend décodé). */
    private function body(): string
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) && \is_string($decoded['error'] ?? null) ? $decoded['error'] : (string) $this->client->getResponse()->getContent();
    }

    private function reservationCountOnCase(string $venueId, int $dayOfWeek, string $startTime): int
    {
        return $this->reservationCountOnCaseScoped($venueId, $dayOfWeek, $startTime, null);
    }

    private function reservationCountOnCaseScoped(string $venueId, int $dayOfWeek, string $startTime, ?string $planId): int
    {
        $this->em->clear();
        $qb = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.venueId = :v')->andWhere('r.dayOfWeek = :d')->andWhere('r.startTime = :s')
            ->setParameter('v', $venueId)->setParameter('d', $dayOfWeek)
            ->setParameter('s', new DateTimeImmutable($startTime));
        if (null === $planId) {
            $qb->andWhere('r.schedulePlanId IS NULL');
        } else {
            $qb->andWhere('r.schedulePlanId = :p')->setParameter('p', $planId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function reservationCountAll(): int
    {
        $this->em->clear();

        return (int) $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
