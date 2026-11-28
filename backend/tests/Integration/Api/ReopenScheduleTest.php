<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Rouvrir » une période ne vaut que pour la version que son plan pointe, et dans le club de
 * l'appelant. Le plan de saison ne se rouvre plus (lot 5) : son refus est gardé par
 * `Security/SeasonPlanInForceTest::testReopeningTheSeasonPlanIsRefused`, et le dépointage d'une
 * période par `Integration/Api/OverlayVersionsTest::testReopeningAnOverlayReleasesItsPeriodPlan`.
 */
#[Group('phase1')]
#[Group('integration')]
final class ReopenScheduleTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    public function testReopeningAVersionThePlanStoppedPointingAtDoesNotClaimSuccess(): void
    {
        // Course : un autre onglet valide une AUTRE version de la même période pendant qu'on
        // rouvre. Le dépointage ne toucherait alors plus rien. Répondre 200 « rouvert » ferait
        // croire au gestionnaire qu'il peut éditer, et chaque geste serait refusé en 409.
        [$user, , $season] = $this->seed('REO9');
        [$entry, $v1] = $this->overlayEntry($season, 'Toussaint');
        // L'issue de la course : le plan de la période pointe désormais une autre version.
        $v2 = $this->siblingOverlay($season, $entry);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v1}/reopen");

        self::assertResponseStatusCodeSame(409, 'on n\'annonce pas une réouverture qui n\'a pas eu lieu');
        $this->em->clear();
        self::assertSame($v2, self::getContainer()->get(SchedulePlanProvisioner::class)->chosenOfPeriodPlan($entry->getId()), 'et le pointeur d\'autrui n\'est pas touché');
    }

    public function testAVersionThePlanDoesNotPointAtCannotBeReopened(): void
    {
        // « Validé » = le pointeur et rien d'autre : une version terminée d'une période que son
        // plan ne pointe pas n'a rien à rouvrir.
        [$user, , $season] = $this->seed('REO2');
        [$entry] = $this->overlayEntry($season, 'Toussaint');
        $unpointed = $this->siblingOverlay($season, $entry, choose: false);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$unpointed}/reopen");

        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignScheduleIsNotAccessible(): void
    {
        [$user] = $this->seed('REO3');
        [, , $otherSeason] = $this->seed('REO4');
        $foreign = $this->createSchedule($otherSeason, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($foreign);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$foreign->getId()}/reopen");

        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('R');
        $user->setLastName('EO');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $this->em->flush();

        return [$user, $club, $season];
    }

    private function createSchedule(Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan');
        $schedule->setStatus($status);
        // lot D : une version naît liée à un plan (schedule_plan_id NOT NULL). On la lie au
        // plan SEASON (le socle) comme la prod au POST — plan AVANT persist, puis numérotée.
        // Les tests qui la POINTENT enchaînent choosePlanVersion (idempotent : version déjà
        // ≥ 1, il ne relie pas et se contente de pointer).
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    /**
     * A period entry with a generated overlay (schedule + one slot + dated FACILITY
     * constraint).
     *
     * @return array{0: CalendarEntry, 1: string, 2: string, 3: string} entry, overlayId, slotId, datedConstraintId
     */
    private function overlayEntry(Season $season, string $title): array
    {
        $overlay = new Schedule;
        $overlay->setClubId($season->getClubId());
        $overlay->setSeasonId($season->getId());
        $overlay->setName('Overlay ' . $title);
        $overlay->setStatus(ScheduleStatus::COMPLETED);
        // lot D : l'overlay ne peut être persisté sans son plan (schedule_plan_id NOT NULL),
        // or ce plan est celui de la PÉRIODE — il faut d'abord créer l'entrée. On garde donc
        // l'overlay en mémoire (son id vient du constructeur) et on le persiste plus bas, une
        // fois son plan de période résolu et posé.

        $entry = new CalendarEntry;
        $entry->setClubId($season->getClubId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle($title);
        // À VENIR, relatif au présent : depuis #8 la reprise du socle ne détruit que les
        // périodes pas encore échues (une période jouée est de l'histoire). Une date en
        // dur finirait par passer dans le passé et désarmerait ce test en silence.
        $entry->setStartDate(new DateTimeImmutable('+1 month'));
        $entry->setEndDate(new DateTimeImmutable('+1 month +6 days'));
        $this->em->persist($entry);
        $this->em->flush();
        // Depuis C4 l'overlay pend au PLAN de la période (plus de calendarEntryId) : le
        // reopen et la cascade le retrouvent PAR son plan, né du geste (rejoué ici).
        $overlay->setSchedulePlanId(self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId()));
        // Plan posé (AVANT persist, car provisionPeriodPlan a sa propre transaction) : on peut
        // enfin persister l'overlay. version_number reste 0 (valide, NOT NULL passe) — aucun
        // test n'exige un numéro ici, l'overlay est retrouvé PAR son plan de période.
        $this->em->persist($overlay);

        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($season->getClubId());
        $slot->setSeasonId($season->getId());
        $slot->setScheduleId($overlay->getId());
        $slot->setTeamId('44444444-4444-4444-8444-444444444444');
        $slot->setVenueId('55555555-5555-4555-8555-555555555555');
        $slot->setDayOfWeek(1);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $this->em->persist($slot);

        $dated = new Constraint;
        $dated->setClubId($season->getClubId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Salle fermée');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setScopeTargetId('55555555-5555-4555-8555-555555555555');
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setCalendarEntryId($entry->getId());
        $this->em->persist($dated);
        $this->em->flush();

        // lot D-b : le confirm reopen ne compte que les plans secondaires RÉELS (validés).
        // On valide donc l'overlay — le plan de la période POINTE cette version.
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $provisioner->linkSchedule($overlay);
        $provisioner->choose($overlay);
        $this->em->flush();

        return [$entry, $overlay->getId(), $slot->getId(), $dated->getId()];
    }

    /** Une autre version terminée du plan de la même période ; pointée par défaut (la validation concurrente). */
    private function siblingOverlay(Season $season, CalendarEntry $entry, bool $choose = true): string
    {
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $sibling = new Schedule;
        $sibling->setClubId($season->getClubId());
        $sibling->setSeasonId($season->getId());
        $sibling->setName('Overlay sœur');
        $sibling->setStatus(ScheduleStatus::COMPLETED);
        $sibling->setSchedulePlanId($provisioner->provisionPeriodPlan($entry->getId()));
        $this->em->persist($sibling);
        $this->em->flush();
        $provisioner->linkSchedule($sibling);
        if ($choose) {
            $provisioner->choose($sibling);
        }
        $this->em->flush();

        return $sibling->getId();
    }
}
