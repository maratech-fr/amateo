<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
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
 * Le Récap d'un plan de PÉRIODE ne crie pas à l'échec pour un groupe entièrement réservé (axe constraint
 * semantics), à travers le vrai `POST /api/constraints/validate` et le vrai `buildForPeriodPlan` : le membre
 * A est épinglé par une réservation PROPRE à la période, le membre B par un verrou HÉRITÉ de la version
 * source du plan. Un test au payload écrit à la main ne verrait pas ces deux chemins (patron
 * {@see RecapCapacityWarningTest}). Jumeau de l'algèbre : `Unit/Service/PreSolvePreventionWarningsTest`.
 */
#[Group('security')]
final class RecapReservedGroupIsNotAFailureTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const int MONDAY = 1;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    /** Réservation propre + verrou hérité : tout le groupe est là, son union absente, aucun « échouera ». */
    public function testAGroupReservedByItsOwnPinAndAnInheritedLockIsSilent(): void
    {
        self::assertSame([], $this->groupWarnings($this->recapOfPeriod(withInheritedLock: true)));
    }

    /** TÉMOIN : sans le verrou hérité, B reste à poser sur une case sans coach, le Récap avertit. C'est donc bien le verrou hérité qui compte. */
    public function testWithoutTheInheritedLockTheGroupIsStillWarned(): void
    {
        $found = $this->groupWarnings($this->recapOfPeriod(withInheritedLock: false));

        self::assertCount(1, $found);
        self::assertStringContainsString('la génération échouera', $found[0]);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array{warnings: list<string>} $body
     *
     * @return list<string>
     */
    private function groupWarnings(array $body): array
    {
        return array_values(array_filter($body['warnings'], static fn (string $w): bool => str_starts_with($w, 'Groupe')));
    }

    /**
     * Un groupe A+B, une case lundi 18:00, Anna et Ben indisponibles tout le lundi, une fermeture qui hérite de tout.
     *
     * @return array{warnings: list<string>}
     */
    private function recapOfPeriod(bool $withInheritedLock): array
    {
        [$user, $club, $season, $category] = $this->seedClubSeason();
        $teamA = $this->team($club, $season, $category, 'A');
        $teamB = $this->team($club, $season, $category, 'B');
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Nord')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $this->seasonSlot($club, $season, $venue);
        $this->absentCoachOf($club, $season, $teamA, 'Anna', 'Martin');
        $this->absentCoachOf($club, $season, $teamB, 'Ben', 'Durand');

        $entry = (new CalendarEntry)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)
            ->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setStatus(CalendarEntryStatus::ACTIVE)
            ->setTitle('Fermeture')
            ->setStartDate(new DateTimeImmutable('2025-12-20'))
            ->setEndDate(new DateTimeImmutable('2026-01-04'));
        $this->em->persist($entry);
        $planId = $this->planIdOf($entry);

        $this->planBlock($club, $season, $planId, [$teamA, $teamB]);

        $own = (new Reservation)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setVenueId($venue->getId())
            ->setTeamId($teamA->getId())
            ->setDayOfWeek(self::MONDAY)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90);
        $this->em->persist($own);

        if ($withInheritedLock) {
            $source = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
                ->setName('Version source')->setStatus(ScheduleStatus::COMPLETED);
            $this->linkSeededSchedule($source, $entry->getId());
            $this->em->flush();
            $this->em->persist(
                (new ScheduleSlotTemplate)
                    ->setClubId($club->getId())
                    ->setSeasonId($season->getId())
                    ->setScheduleId($source->getId())
                    ->setTeamId($teamB->getId())
                    ->setVenueId($venue->getId())
                    ->setDayOfWeek(self::MONDAY)
                    ->setStartTime(new DateTimeImmutable('18:00'))
                    ->setDurationMinutes(90)
                    ->setLockLevel(LockLevel::HARD)
                    ->setLockOrigin(LockOrigin::MANUAL),
            );
        }
        $this->em->flush();

        $this->client->request('POST', '/api/constraints/validate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entry->getId()], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        /* @var array{warnings: list<string>} */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return array{0: User, 1: Club, 2: Season, 3: SportCategory} */
    private function seedClubSeason(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club groupe réservé');
        $club->setSlug('resa-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('RES' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('resa-' . $uid . '@test.com');
        $user->setFirstName('Re');
        $user->setLastName('Sa');
        $user->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'pass'));
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
        $sport->setSlug('resa-' . $uid);
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

        if (!$this->em->getRepository(PriorityTier::class)->find(1) instanceof PriorityTier) {
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

        return [$user, $club, $season, $category];
    }

    private function team(Club $club, Season $season, SportCategory $category, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name);
        $team->setSessionsPerWeek(1);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** Créneau de SAISON : la grille de la période est la COPIE que `planIdOf` prend à la naissance du plan. */
    private function seasonSlot(Club $club, Season $season, Venue $venue): void
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venue->getId());
        $slot->setDayOfWeek(self::MONDAY);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $slot->setSchedulePlanId(null);
        $this->em->persist($slot);
        $this->em->flush();
    }

    private function absentCoachOf(Club $club, Season $season, Team $team, string $firstName, string $lastName): void
    {
        $coach = (new Coach)->setClubId($club->getId())->setSeasonId($season->getId())->setFirstName($firstName)->setLastName($lastName);
        $this->em->persist($coach);
        $this->em->flush();

        $this->em->persist((new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN)->setIsRequired(true));

        $off = new Constraint;
        $off->setClubId($club->getId());
        $off->setSeasonId($season->getId());
        $off->setName($firstName . ' indisponible');
        $off->setScope(ConstraintScope::COACH);
        $off->setScopeTargetId($coach->getId());
        $off->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $off->setRuleType(ConstraintRuleType::HARD);
        $off->setConfig(['unavailableDays' => [self::MONDAY]]);
        $off->setIsActive(true);
        $this->em->persist($off);
        $this->em->flush();
    }

    /**
     * Le groupe, ancré au plan de période.
     *
     * @param list<Team> $teams
     */
    private function planBlock(Club $club, Season $season, string $planId, array $teams): void
    {
        $block = new SharedTrainingBlock;
        $block->setClubId($club->getId());
        $block->setSeasonId($season->getId());
        $block->setSchedulePlanId($planId);
        $block->setCommonSessions(1);
        $this->em->persist($block);

        foreach ($teams as $team) {
            $member = new SharedTrainingBlockTeam;
            $member->setClubId($club->getId());
            $member->setSeasonId($season->getId());
            $member->setSchedulePlanId($planId);
            $member->setBlockId($block->getId());
            $member->setTeamId($team->getId());
            $this->em->persist($member);
        }
        $this->em->flush();
    }
}
