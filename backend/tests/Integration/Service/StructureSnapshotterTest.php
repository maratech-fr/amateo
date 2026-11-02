<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\StructureSnapshotter;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * planning-versions D2 (§7.1 generation pipeline): each season-plan generation
 * attaches a FAITHFUL photo of the club structure — the enabler of the D3
 * restore. Dated (calendar) rows are excluded; the photo is replaced on
 * regeneration, never duplicated.
 */
#[Group('phase1')]
#[Group('integration')]
final class StructureSnapshotterTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private StructureSnapshotter $snapshotter;

    public function testCapturesFaithfulRowsAndExcludesDatedOnes(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(2)
            ->setName('SM1')->setSessionsPerWeek(3)->setIsActive(true);
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase A')->setSource('manual');
        $permanent = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('SM1 mardi')->setScope(ConstraintScope::TEAM)->setScopeTargetId('11111111-1111-4111-8111-111111111111')
            ->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['days' => [2]]);
        $dated = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Fermeture vacances')->setScope(ConstraintScope::FACILITY)->setScopeTargetId('22222222-2222-4222-8222-222222222222')
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig([])
            ->setCalendarEntryId('44444444-4444-4444-8444-444444444444');
        $overlayPlanId = $this->createPeriodPlan($club->getId(), $season->getId());
        $overlayReservation = (new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')->setVenueId('22222222-2222-4222-8222-222222222222')
            ->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)
            ->setSchedulePlanId($overlayPlanId);
        $baseReservation = (new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')->setVenueId('22222222-2222-4222-8222-222222222222')
            ->setDayOfWeek(4)->setStartTime(new DateTimeImmutable('20:30'))->setDurationMinutes(120);
        $slot = (new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId('22222222-2222-4222-8222-222222222222')->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1);
        $coach = (new Coach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setFirstName('Jean')->setLastName('Coach');
        foreach ([$team, $venue, $permanent, $dated, $overlayReservation, $baseReservation, $slot, $coach] as $e) {
            $this->em->persist($e);
        }
        $this->em->flush();
        $link = (new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())
            ->setRole(TeamCoachRole::MAIN);
        $this->em->persist($link);
        $schedule = $this->makeSchedule($club, $season);
        $this->em->flush();

        $this->snapshotter->store($schedule, $this->snapshotter->serialize($club->getId(), $season->getId()));

        $snapshot = $this->em->getRepository(ScheduleStructureSnapshot::class)->findOneBy(['scheduleId' => $schedule->getId()]);
        self::assertInstanceOf(ScheduleStructureSnapshot::class, $snapshot);
        $data = $snapshot->getData();

        // Faithful rows: scalar columns, enums as values, dates as ATOM strings.
        self::assertCount(1, $data['Team']);
        self::assertSame('SM1', $data['Team'][0]['name']);
        self::assertSame(3, $data['Team'][0]['sessionsPerWeek']);
        self::assertSame(2, $data['Team'][0]['priorityTierId']);
        self::assertCount(1, $data['Venue']);
        self::assertSame('Gymnase A', $data['Venue'][0]['name']);
        // Only the PERMANENT constraint is structure; the dated one is calendar.
        self::assertCount(1, $data['Constraint']);
        self::assertSame('SM1 mardi', $data['Constraint'][0]['name']);
        self::assertSame('TEAM', $data['Constraint'][0]['scope'], 'enum serialized as backed value');
        self::assertSame(['days' => [2]], $data['Constraint'][0]['config']);
        // The overlay reservation is calendar — excluded; the BASE one is kept.
        self::assertCount(1, $data['Reservation']);
        self::assertSame(4, $data['Reservation'][0]['dayOfWeek']);
        // Positive coverage of the remaining families (the D3 restore depends
        // on every one of them actually loading).
        self::assertCount(1, $data['VenueTrainingSlot']);
        self::assertSame(90, $data['VenueTrainingSlot'][0]['durationMinutes']);
        self::assertCount(1, $data['Coach']);
        self::assertSame('Jean', $data['Coach'][0]['firstName']);
        self::assertCount(1, $data['TeamCoach']);
        self::assertSame('MAIN', $data['TeamCoach'][0]['role']);
    }

    public function testCaptureReplacesThePhotoOnRegeneration(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $schedule = $this->makeSchedule($club, $season);
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(1)
            ->setName('Avant')->setSessionsPerWeek(1)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        $this->snapshotter->store($schedule, $this->snapshotter->serialize($club->getId(), $season->getId()));
        $team->setName('Après');
        $this->em->flush();
        $this->snapshotter->store($schedule, $this->snapshotter->serialize($club->getId(), $season->getId()));
        $this->em->clear();

        $rows = $this->em->getRepository(ScheduleStructureSnapshot::class)->findBy(['scheduleId' => $schedule->getId()]);
        self::assertCount(1, $rows, 'one photo per version — replaced, never duplicated');
        self::assertSame('Après', $rows[0]->getData()['Team'][0]['name']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->snapshotter = self::getContainer()->get(StructureSnapshotter::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function seedClubSeason(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Snap ' . $uid)->setSlug('snap-' . $uid)
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

    private function makeSchedule(Club $club, Season $season): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('V1');
        $schedule->setStatus(ScheduleStatus::GENERATING);
        // lot D : la version doit porter son plan AVANT l'INSERT (schedule_plan_id NOT NULL) —
        // linkSeededSchedule résout le plan SEASON, le pose, persiste et numérote.
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }
}
