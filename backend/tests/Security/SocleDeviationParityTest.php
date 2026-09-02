<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\VenueDayState;
use App\Enum\VenuePeriodMode;
use App\Service\PeriodPlanTranscriber;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR BLOQUANT — P2-44 PR-5 « les écarts NOMMÉS vs le socle » (axes §7.1 : generation pipeline +
 * planning lifecycle).
 *
 * PROUVE que `GET /api/schedules/{id}/socle-deviation` NOMME EXACTEMENT les écarts entre la version
 * affichée d'un plan de PÉRIODE de type FERMETURE et la version POINTÉE du socle — falsifié dans les
 * DEUX sens :
 *   - une séance BOUGÉE apparaît en `moved` avec `from`/`to` exacts ;
 *   - une séance INCHANGÉE n'apparaît nulle part ;
 *   - une séance NOUVELLE (période sans contrepartie socle) n'apparaît nulle part ;
 *   - une NON REPLACÉE porte la raison DE LA SÉLECTION : fermer le jour du gymnase dans la sélection
 *     donne `venue_closed`, un gymnase désactivé `venue_disabled`, une réduction `team_reduced` ;
 *     une absence INEXPLIQUÉE par la sélection (suppression manuelle) porte `null`, JAMAIS une raison
 *     fabriquée ;
 *   - la RÉDUCTION laisse en reliquat les DERNIÈRES de la semaine (déterminisme épinglé) ;
 *   - une équipe DÉSACTIVÉE n'a aucun écart (ni déplacée ni non replacée).
 *
 * Puis les REFUS nommés : 422 plan SEASON, 422 période HOLIDAY, 409 version non COMPLETED, 409 socle
 * non pointé, 404 tenant (autre club). Et la LECTURE est OUVERTE au Membre (pas de garde management).
 */
#[Group('phase1')]
#[Group('security')]
final class SocleDeviationParityTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    private SportCategory $category;

    /** Le cœur : les deux catégories (déplacée / non replacée), falsifiées dans les deux sens. */
    public function testDeviationsAreNamedAgainstThePointedSocle(): void
    {
        [$user, $club, $season] = $this->seedClub('DEV');

        $vHealthy = $this->venue($club, $season, 'Sain');
        $vAlt = $this->venue($club, $season, 'Autre');
        $vDisabled = $this->venue($club, $season, 'Désactivé');
        $vClosed = $this->venue($club, $season, 'Fermé mercredi');

        $teamUnchanged = $this->team($club, $season, 'Unchanged');
        $teamMoved = $this->team($club, $season, 'Moved');
        $teamNew = $this->team($club, $season, 'New');
        $teamNull = $this->team($club, $season, 'UnplacedNull');
        $teamReduced = $this->team($club, $season, 'Reduced');
        $teamDisabled = $this->team($club, $season, 'DisabledVenue');
        $teamClosed = $this->team($club, $season, 'ClosedDay');
        $teamPaused = $this->team($club, $season, 'Paused');

        // La version POINTÉE du socle.
        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $teamUnchanged, $vHealthy, 1, '18:00');   // inchangée
        $this->slot($socle, $teamMoved, $vHealthy, 2, '18:30');       // déplacée → Jeu 19:00 vAlt
        $this->slot($socle, $teamNull, $vHealthy, 5, '20:00');        // supprimée à la main → non replacée reason null
        $this->slot($socle, $teamReduced, $vHealthy, 1, '18:00');     // conservée
        $this->slot($socle, $teamReduced, $vHealthy, 5, '20:00');     // réduite (dernière) → team_reduced
        $this->slot($socle, $teamDisabled, $vDisabled, 3, '18:00');   // gymnase désactivé → venue_disabled
        $this->slot($socle, $teamClosed, $vClosed, 3, '17:30');       // jour fermé → venue_closed
        $this->slot($socle, $teamPaused, $vHealthy, 1, '16:00');      // équipe en pause → aucun écart
        $this->em->flush();
        $this->choosePlanVersion($socle);

        // La période (FERMETURE) et son plan.
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $planId = $this->planIdOf($entry);

        // Les réglages de la période qui EXPLIQUENT les raisons.
        $this->venueDisabled($club, $season, $planId, $vDisabled);
        $this->venueDayClosed($club, $season, $planId, $vClosed, 3);
        $this->teamOverride($club, $season, $planId, $teamReduced, true, 1);
        $this->teamOverride($club, $season, $planId, $teamPaused, false, null);
        $this->em->flush();

        // La version affichée du plan de période (COMPLETED) — ce que le gestionnaire voit.
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $teamUnchanged, $vHealthy, 1, '18:00');            // identique
        $periodMovedSlot = $this->slot($period, $teamMoved, $vAlt, 4, '19:00'); // déplacée
        $this->slot($period, $teamNew, $vHealthy, 1, '17:00');                  // nouvelle
        $this->slot($period, $teamReduced, $vHealthy, 1, '18:00');              // la conservée
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame($socle->getId(), $body['socleScheduleId']);

        // ── DÉPLACÉES ───────────────────────────────────────────────────────────────────────────
        $movedByTeam = [];
        foreach ($body['moved'] as $row) {
            $movedByTeam[(string) $row['teamId']] = $row;
        }
        self::assertArrayHasKey($teamMoved->getId(), $movedByTeam, 'la séance bougée apparaît en déplacée');
        $mv = $movedByTeam[$teamMoved->getId()];
        self::assertSame(2, $mv['from']['dayOfWeek']);
        self::assertSame('18:30', $mv['from']['startTime']);
        self::assertSame($vHealthy->getId(), $mv['from']['venueId']);
        self::assertSame(4, $mv['to']['dayOfWeek']);
        self::assertSame('19:00', $mv['to']['startTime']);
        self::assertSame($vAlt->getId(), $mv['to']['venueId']);
        // PR-4 — `to` porte le slotId du créneau de PÉRIODE (celui que la grille affiche) pour
        // que le front marque LA carte déviée ; `from` (socle, non affiché) n'en a pas.
        self::assertSame($periodMovedSlot->getId(), $mv['to']['slotId'], 'le slotId servi = l\'id du slot de PÉRIODE');
        self::assertArrayNotHasKey('slotId', $mv['from'], 'le placement du socle n\'a pas de slotId (il n\'est pas affiché dans la grille)');

        // Falsification : une INCHANGÉE et une NOUVELLE ne sont JAMAIS déplacées.
        self::assertArrayNotHasKey($teamUnchanged->getId(), $movedByTeam, 'une inchangée n\'est pas déplacée');
        self::assertArrayNotHasKey($teamNew->getId(), $movedByTeam, 'une nouvelle n\'est pas déplacée');
        self::assertCount(1, $body['moved'], 'exactement une séance déplacée');

        // ── NON REPLACÉES (raison SERVIE par la sélection) ───────────────────────────────────────
        $reasonByTeam = [];
        foreach ($body['unplaced'] as $row) {
            $reasonByTeam[(string) $row['teamId']] = $row['reason'];
        }
        self::assertArrayHasKey($teamReduced->getId(), $reasonByTeam);
        self::assertSame(PeriodPlanTranscriber::SKIP_TEAM_REDUCED, $reasonByTeam[$teamReduced->getId()]);
        self::assertSame(PeriodPlanTranscriber::SKIP_VENUE_DISABLED, $reasonByTeam[$teamDisabled->getId()] ?? null);
        self::assertSame(PeriodPlanTranscriber::SKIP_VENUE_CLOSED, $reasonByTeam[$teamClosed->getId()] ?? null);
        // Absence INEXPLIQUÉE par la sélection → null, jamais fabriquée.
        self::assertArrayHasKey($teamNull->getId(), $reasonByTeam);
        self::assertNull($reasonByTeam[$teamNull->getId()], 'une suppression manuelle n\'a pas de raison servie');

        // DÉTERMINISME de la réduction : c'est bien la Ven 20:00 (dernière) qui est non replacée.
        $reducedRow = null;
        foreach ($body['unplaced'] as $row) {
            if ($teamReduced->getId() === $row['teamId']) {
                $reducedRow = $row;
            }
        }
        self::assertNotNull($reducedRow);
        self::assertSame(5, $reducedRow['dayOfWeek']);
        self::assertSame('20:00', $reducedRow['startTime']);

        // Falsification : une INCHANGÉE, une NOUVELLE et une équipe EN PAUSE ne sont jamais non replacées.
        self::assertArrayNotHasKey($teamUnchanged->getId(), $reasonByTeam);
        self::assertArrayNotHasKey($teamNew->getId(), $reasonByTeam);
        self::assertArrayNotHasKey($teamPaused->getId(), $reasonByTeam, 'une équipe désactivée n\'a aucun écart');
        self::assertCount(4, $body['unplaced'], 'exactement 4 séances non replacées');
    }

    /** 422 — un plan SEASON (le socle) n'a pas d'écart avec lui-même. */
    public function testSeasonPlanIsRefused(): void
    {
        [$user, $club, $season] = $this->seedClub('SEAS');
        $team = $this->team($club, $season, 'A');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $team, $vHealthy, 1, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $this->request($user, $club, $season, $socle->getId());
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /** 422 — une reprise de VACANCES n'a pas d'écart nommé (planning tout nouveau, ADR-0004). */
    public function testHolidayPeriodIsRefused(): void
    {
        [$user, $club, $season, $planId, $period] = $this->closureScaffold('HOL', CalendarEntryPeriodType::HOLIDAY, ScheduleStatus::COMPLETED, chooseSocle: true);
        unset($planId);

        $this->request($user, $club, $season, $period->getId());
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /** 409 — une version non terminée n'a pas de placement stable à comparer. */
    public function testNonCompletedVersionIsRefused(): void
    {
        [$user, $club, $season, $planId, $period] = $this->closureScaffold('DRAFT', CalendarEntryPeriodType::CLOSURE, ScheduleStatus::DRAFT, chooseSocle: true);
        unset($planId);

        $this->request($user, $club, $season, $period->getId());
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    /** 409 — socle non pointé (période DÉJÀ commencée, atteignable hors du moment de génération). */
    public function testUnchosenSocleIsRefused(): void
    {
        [$user, $club, $season, $planId, $period] = $this->closureScaffold('NOSOCLE', CalendarEntryPeriodType::CLOSURE, ScheduleStatus::COMPLETED, chooseSocle: false);
        unset($planId);

        $this->request($user, $club, $season, $period->getId());
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Tenant — la version d'un AUTRE club est refusée : 404 quand la RLS la cache (prod),
     * 403 par la défense club en profondeur (harness de test). Jamais 200.
     */
    public function testOtherClubIsRefused(): void
    {
        [, $club, $season, $planId, $period] = $this->closureScaffold('TEN', CalendarEntryPeriodType::CLOSURE, ScheduleStatus::COMPLETED, chooseSocle: true);
        unset($planId, $club, $season);

        [$stranger, $otherClub, $otherSeason] = $this->seedClub('STRANGER');
        $this->request($stranger, $otherClub, $otherSeason, $period->getId());
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404], 'version d\'un autre club → refusée');
    }

    /** La LECTURE est ouverte au Membre : pas de garde management sur une route de lecture. */
    public function testReadIsOpenToMember(): void
    {
        [, $club, $season, $planId, $period] = $this->closureScaffold('MEMBER', CalendarEntryPeriodType::CLOSURE, ScheduleStatus::COMPLETED, chooseSocle: true);
        unset($planId);

        $member = $this->member($club->getId(), 'editor');
        $body = $this->deviation($member, $club, $season, $period->getId());
        self::assertArrayHasKey('moved', $body);
        self::assertArrayHasKey('unplaced', $body);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * Un club + socle pointé (optionnel) + une période d'un type donné + une version de plan de
     * période d'un statut donné. Le socle porte une séance simple (le comparatif n'est pas l'objet
     * des tests de refus).
     *
     * @return array{0: User, 1: Club, 2: Season, 3: string, 4: Schedule}
     */
    private function closureScaffold(string $tag, CalendarEntryPeriodType $type, ScheduleStatus $status, bool $chooseSocle): array
    {
        [$user, $club, $season] = $this->seedClub($tag);
        $vHealthy = $this->venue($club, $season, 'Sain');
        $team = $this->team($club, $season, 'A');
        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $team, $vHealthy, 1, '18:00');
        $this->em->flush();
        if ($chooseSocle) {
            $this->choosePlanVersion($socle);
        }

        $entry = $this->period($club, $season, $type);
        $planId = $this->planIdOf($entry);
        $period = $this->periodVersion($club, $season, $planId, $status);
        $this->slot($period, $team, $vHealthy, 1, '18:00');
        $this->em->flush();

        return [$user, $club, $season, $planId, $period];
    }

    /**
     * @return array{socleScheduleId: string, moved: list<array<string, mixed>>, unplaced: list<array<string, mixed>>}
     */
    private function deviation(User $user, Club $club, Season $season, string $scheduleId): array
    {
        $this->request($user, $club, $season, $scheduleId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        /* @var array{socleScheduleId: string, moved: list<array<string, mixed>>, unplaced: list<array<string, mixed>>} */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function request(User $user, Club $club, Season $season, string $scheduleId): void
    {
        $this->client->request('GET', '/api/schedules/' . $scheduleId . '/socle-deviation', [], [], $this->headers($user, $club, $season));
    }

    /**
     * @return array<string, string>
     */
    private function headers(User $user, Club $club, Season $season): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
            'HTTP_X-Season-Id' => $season->getId(),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function socleVersion(Club $club, Season $season): Schedule
    {
        $planId = $this->seasonPlanIdOf($season);
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setName('Socle')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);

        return $schedule;
    }

    private function periodVersion(Club $club, Season $season, string $planId, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setName('Adaptation')
            ->setStatus($status);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);

        return $schedule;
    }

    private function slot(Schedule $schedule, Team $team, Venue $venue, int $day, string $start): ScheduleSlotTemplate
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId($team->getId())
            ->setVenueId($venue->getId())
            ->setCoachId(null)
            ->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes(90)
            ->setLockLevel(LockLevel::NONE);
        $this->em->persist($slot);

        return $slot;
    }

    private function period(Club $club, Season $season, CalendarEntryPeriodType $type): CalendarEntry
    {
        // Semaine PLEINE (lundi → dimanche) : tous les jours de semaine sont dans la fenêtre.
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Période');
        $entry->setStartDate(new DateTimeImmutable('2025-10-20'));
        $entry->setEndDate(new DateTimeImmutable('2025-10-26'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType($type);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function venueDayClosed(Club $club, Season $season, string $planId, Venue $venue, int $day): void
    {
        $override = new VenuePeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setVenueId($venue->getId());
        $override->setMode(null);
        $override->setDayOverrides([$day => VenueDayState::CLOSED->value]);
        $this->em->persist($override);
    }

    private function venueDisabled(Club $club, Season $season, string $planId, Venue $venue): void
    {
        $override = new VenuePeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setVenueId($venue->getId());
        $override->setMode(VenuePeriodMode::DISABLED);
        $this->em->persist($override);
    }

    private function teamOverride(Club $club, Season $season, string $planId, Team $team, bool $active, ?int $sessionsPerWeek): void
    {
        $override = new TeamPeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setTeamId($team->getId());
        $override->setIsActive($active);
        $override->setSessionsPerWeek($sessionsPerWeek);
        $this->em->persist($override);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name . '-' . uniqid());
        $team->setSessionsPerWeek(3);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $venue->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function member(string $clubId, string $role): User
    {
        $uid = uniqid('', true);
        $user = new User;
        $user->setEmail('m-' . $uid . '@test.com');
        $user->setFirstName('Me');
        $user->setLastName('Mber');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seedClub(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('dev-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('dev-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('De');
        $user->setLastName('V');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('dev-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();
        $this->category = $category;

        $this->provisionSeasonPlan($season);

        return [$user, $club, $season];
    }
}
