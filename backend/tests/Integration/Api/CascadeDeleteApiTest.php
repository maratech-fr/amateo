<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Competition;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CompetitionType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * §7.1 non-regression: deleting an interlinked entity must purge every logical
 * child in the SAME request (no ORM/DB cascade exists — everything is a plain
 * guid column). Guards the orphan-reservation defect: an orphan reservation is
 * invisible in the UI yet still feeds the solver as a HARD pre-placement on a
 * slot that no longer exists. Also guards tenant isolation (a cascade never
 * crosses a club).
 */
#[Group('phase1')]
final class CascadeDeleteApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private string $seasonVersionId;

    private Season $season;

    private string $token;

    public function testDeletingTeamPurgesAllItsLinks(): void
    {
        $team = $this->persistTeam('SM1');
        $coach = $this->persistCoach();
        $teamCoachId = $this->persist((new TeamCoach)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())
            ->setRole(TeamCoachRole::MAIN)->setIsRequired(false));
        $coachPlayerId = $this->persist((new CoachPlayerMembership)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCoachId($coach->getId())->setTeamId($team->getId())->setIsActive(true));
        $reservationId = $this->persistReservation($team->getId(), '22222222-2222-4222-8222-222222222222', 2, '20:30');
        $templateId = $this->persistHardTemplate($team->getId(), '22222222-2222-4222-8222-222222222222', 2, '20:30');
        $constraintId = $this->persist((new Constraint)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('SM1 le mardi')->setScope(ConstraintScope::TEAM)->setScopeTargetId($team->getId())
            ->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::HARD)->setConfig([]));
        // A period had disabled that TEAM-scoped constraint → the override must go
        // with the constraint when the team's cascade bulk-deletes it (else it orphans).
        $overrideId = $this->persist((new ConstraintPeriodOverride)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->periodPlanId())->setConstraintId($constraintId)->setIsActive(false));
        // PAS de Fixture ici : depuis la garde du périmètre engagé, un SEUL match — même
        // UNPLACED — rend l'équipe indélébile (409). Ce test porte sur la cascade d'une
        // équipe ordinaire ; le cas « elle joue » est couvert par EngagedTeamGuardTest.
        // La Competition, elle, n'engage pas : c'est une inscription, pas une rencontre.
        $competitionId = $this->persist((new Competition)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setTeamId($team->getId())
            ->setName('D2 Poule A')->setCompetitionType(CompetitionType::CHAMPIONSHIP));
        $diagnosticId = $this->persist((new ScheduleDiagnostic)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setScheduleId($this->season->getId())
            ->setType('unplaced')->setSeverity(ScheduleDiagnosticSeverity::WARNING)->setTeamId($team->getId())
            ->setMessage('SM1 non placée')->setSuggestions([]));

        $this->client->request('DELETE', '/api/teams/' . $team->getId(), [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Reservation::class)->find($reservationId), 'reservation orphaned');
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($templateId), 'HARD pin left for the solver');
        self::assertNull($this->em->getRepository(TeamCoach::class)->find($teamCoachId));
        self::assertNull($this->em->getRepository(CoachPlayerMembership::class)->find($coachPlayerId));
        self::assertNull($this->em->getRepository(Constraint::class)->find($constraintId));
        self::assertNull($this->em->getRepository(ConstraintPeriodOverride::class)->find($overrideId), 'period override of the deleted scoped constraint orphaned');
        self::assertNull($this->em->getRepository(Competition::class)->find($competitionId), 'competition orphaned');
        self::assertNull($this->em->getRepository(ScheduleDiagnostic::class)->find($diagnosticId), 'diagnostic dangling');
        // The coach itself is a peer, not a child — it survives the team delete.
        self::assertNotNull($this->em->getRepository(Coach::class)->find($coach->getId()));
    }

    public function testDeletingAvailabilitySlotPurgesItsReservations(): void
    {
        $venueId = '22222222-2222-4222-8222-222222222222';
        $slot = $this->persist((new VenueTrainingSlot)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($venueId)->setDayOfWeek(3)->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)->setCapacity(1));
        $reservationId = $this->persistReservation('11111111-1111-4111-8111-111111111111', $venueId, 3, '18:00');
        $hardTemplateId = $this->persistHardTemplate('11111111-1111-4111-8111-111111111111', $venueId, 3, '18:00');
        // A SOFT solver placement at the SAME venue/day/time in a generated
        // schedule is a RESULT, not a pin — it must survive the slot delete.
        $softTemplateId = $this->persist((new ScheduleSlotTemplate)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setScheduleId($this->seasonVersionId())
            ->setTeamId('55555555-5555-4555-8555-555555555555')->setVenueId($venueId)->setDayOfWeek(3)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setLockLevel(LockLevel::SOFT));

        $this->client->request('DELETE', '/api/venue_training_slots/' . $slot, [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Reservation::class)->find($reservationId), 'the orphan-reservation cause: slot gone, reservation must go too');
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($hardTemplateId), 'the reservation HARD pin goes with the slot');
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($softTemplateId), 'a SOFT solver placement is a result, not a pin — must survive');
    }

    public function testDeletingASeasonSlotLeavesThePinsOfAPeriodAlone(): void
    {
        // #8 — une période POSSÈDE sa grille : supprimer un créneau de saison ne supprime
        // PAS la copie qu'elle en détient. Emporter au passage le verrou que le
        // gestionnaire y avait posé la laissait avec un créneau toujours offert mais son
        // épinglage disparu, sans un mot. La borne de couche va donc dans les deux sens.
        $venueId = '22222222-2222-4222-8222-222222222222';
        $slot = $this->persist((new VenueTrainingSlot)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($venueId)->setDayOfWeek(3)->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)->setCapacity(1));
        $seasonPin = $this->persistHardTemplate('11111111-1111-4111-8111-111111111111', $venueId, 3, '18:00');

        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Toussaint');
        $entry->setStartDate(new DateTimeImmutable('+1 month'));
        $entry->setEndDate(new DateTimeImmutable('+1 month +7 days'));
        $this->em->persist($entry);
        $this->em->flush();
        $periodVersion = new Schedule;
        $periodVersion->setClubId($this->club->getId());
        $periodVersion->setSeasonId($this->season->getId());
        $periodVersion->setName('Toussaint V1');
        $periodVersion->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($periodVersion, $entry->getId());
        $this->em->flush();
        $periodPin = $this->persist((new ScheduleSlotTemplate)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setScheduleId($periodVersion->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')->setVenueId($venueId)->setDayOfWeek(3)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setLockLevel(LockLevel::HARD));

        $this->client->request('DELETE', '/api/venue_training_slots/' . $slot, [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($seasonPin), 'le verrou de la COUCHE supprimée part avec son créneau');
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($periodPin), 'celui de la période reste : sa grille, elle, n\'a pas bougé');
    }

    public function testCascadeNeverCrossesClub(): void
    {
        // Delete a team in THIS club; the other club gets a reservation carrying
        // the SAME teamId value — so if the cascade scoped by teamId alone (no
        // clubId / no RLS) it WOULD wrongly delete it. Surviving proves the
        // club boundary is actually enforced, not incidentally by unique UUIDs.
        $team = $this->persistTeam('Doomed');
        $this->persistReservation($team->getId(), '22222222-2222-4222-8222-222222222222', 2, '20:30');

        [$otherClub, $otherSeason] = $this->createOtherClub();
        $this->scopeGucToClub($otherClub->getId());
        $otherReservation = $this->persist((new Reservation)
            ->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setTeamId($team->getId()) // same teamId, different club
            ->setVenueId('22222222-2222-4222-8222-222222222222')
            ->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('20:30'))->setDurationMinutes(120));

        $this->scopeGucToClub($this->club->getId());
        $this->client->request('DELETE', '/api/teams/' . $team->getId(), [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->scopeGucToClub($otherClub->getId());
        self::assertNotNull($this->em->getRepository(Reservation::class)->find($otherReservation), 'another club reservation with the same teamId must survive');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Cascade ' . $uid)->setSlug('cascade-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $user = (new User)->setEmail('cascade' . $uid . '@test.com')->setFirstName('C')->setLastName('D');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @return array{0: Club, 1: Season} */
    private function createOtherClub(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Other ' . $uid)->setSlug('other-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    private function persistTeam(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(1)
            ->setName($name)->setSessionsPerWeek(2)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function persistCoach(): Coach
    {
        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Jean')->setLastName('Coach');
        $this->em->persist($coach);
        $this->em->flush();

        return $coach;
    }

    private function persistReservation(string $teamId, string $venueId, int $day, string $start): string
    {
        return $this->persist((new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($teamId)->setVenueId($venueId)->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(120));
    }

    /**
     * La version de SOCLE à laquelle appartiennent les verrous de ce test. Depuis #8 la
     * purge d'un créneau est bornée à la COUCHE du créneau (un créneau de saison
     * n'emporte que les verrous du planning principal) : un scheduleId inventé ne
     * relèverait d'aucune couche et le test ne prouverait plus rien.
     */
    private function seasonVersionId(): string
    {
        if (!isset($this->seasonVersionId)) {
            $schedule = new Schedule;
            $schedule->setClubId($this->club->getId());
            $schedule->setSeasonId($this->season->getId());
            $schedule->setName('Socle');
            $schedule->setStatus(ScheduleStatus::COMPLETED);
            $this->linkSeededSchedule($schedule);
            $this->em->flush();
            $this->seasonVersionId = $schedule->getId();
        }

        return $this->seasonVersionId;
    }

    private function persistHardTemplate(string $teamId, string $venueId, int $day, string $start): string
    {
        return $this->persist((new ScheduleSlotTemplate)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setScheduleId($this->seasonVersionId())
            ->setTeamId($teamId)->setVenueId($venueId)->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(120)->setLockLevel(LockLevel::HARD));
    }

    /** @return string the persisted entity id */
    private function persist(object $entity): string
    {
        $this->em->persist($entity);
        $this->em->flush();

        return method_exists($entity, 'getId') ? (string) $entity->getId() : '';
    }

    /**
     * P4-34 — l'ancre d'un `*PeriodOverride` doit EXISTER (FK + garde 422 depuis
     * cette PR) : un uuid décoratif ne passe plus. On provisionne un vrai plan de
     * période, comme en production.
     */
    private function periodPlanId(): string
    {
        $plan = (new SchedulePlan)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setType(SchedulePlanType::HOLIDAY)->setName('Période de test')
            ->setStartDate(new DateTimeImmutable('2026-02-15'))->setEndDate(new DateTimeImmutable('2026-02-28'));
        $this->em->persist($plan);
        $this->em->flush();

        return $plan->getId();
    }
}
