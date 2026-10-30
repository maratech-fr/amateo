<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\MoveSlotService;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Décision fondateur 2026-10-30 : la pose manuelle d'une séance non placée, hors de sa règle, est PERMISE et
 * signalée. Chemin complet : base réelle, payload du VRAI builder, `MoveSlotService::place`, VRAI moteur. Angle
 * cross-stack (solveur, doublon voulu) du test moteur
 * `test_the_missing_session_of_a_forced_day_can_be_placed_by_hand_outside_its_rule_and_is_flagged`.
 *
 * Les catégories de ce décor n'ont pas d'âge : l'ordre des âges n'y joue pas. Le refus NOMMÉ quand il joue est
 * gardé par `test_a_hand_placement_refused_by_the_age_order_names_that_rule_and_both_teams`.
 */
#[Group('contract')]
final class ManualPlacementOutsideLiftedRuleTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const int MONDAY = 1;
    private const int FRIDAY = 5;

    private EntityManagerInterface $em;

    public function testAMissingSessionPlacedOutsideItsLostForcedDayIsAcceptedFlaggedAndWritten(): void
    {
        $ctx = $this->seed();

        $result = self::getContainer()->get(MoveSlotService::class)->place(
            $ctx['schedule'],
            $ctx['seniors'],
            self::MONDAY,
            new DateTimeImmutable('18:00'),
            $ctx['venue'],
            90,
        );

        self::assertTrue($result['valid'], 'la pose hors de la règle levée est permise : ' . json_encode($result['violations'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('Vendredi imposé', implode(' | ', array_column($result['compromises'], 'message')), 'et signalée, en nommant la règle');
        self::assertNotNull(
            $this->em->getRepository(ScheduleSlotTemplate::class)->findOneBy(['scheduleId' => $ctx['schedule']->getId(), 'teamId' => $ctx['seniors']]),
            'la séance est écrite',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        try {
            $alive = 200 === self::getContainer()->get('http_client')->request('GET', 'http://engine:8000/health')->getStatusCode();
        } catch (Throwable) {
            $alive = false;
        }
        if (!$alive) {
            self::markTestSkipped('moteur indisponible');
        }
    }

    /**
     * Le planning tel que la génération le laisse : Cadets sur sa réservation du vendredi, Minimes lundi 19:30.
     *
     * @return array{schedule: Schedule, seniors: string, venue: string}
     */
    private function seed(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club)->setName('Club ' . $suffix)->setSlug('pose-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))
            ->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName('Gymnase Centre')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        foreach ([[self::MONDAY, '18:00'], [self::MONDAY, '19:30'], [self::FRIDAY, '18:00']] as [$day, $start]) {
            $this->em->persist((new VenueTrainingSlot)->setClubId($clubId)->setSeasonId($seasonId)->setVenueId($venue->getId())
                ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('1970-01-01 ' . $start))->setDurationMinutes(90)->setCapacity(1));
        }

        $sport = (new Sport)->setName('Basket ' . $suffix)->setSlug('basket-' . $suffix)->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        $category = (new SportCategory)->setClubId($clubId)->setSportId($sport->getId())->setName('U13')->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();
        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = (new PriorityTier)->setId(1)->setLabel('S')->setName('Senior')->setColor('#FF0000')->setOrToolsWeight(100)->setDefaultMinSessions(2);
            $this->em->persist($tier);
            $this->em->flush();
        }

        $teams = [];
        foreach (['Seniors A', 'Cadets', 'Minimes'] as $name) {
            $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setSportCategoryId($category->getId())
                ->setPriorityTierId($tier->getId())->setName($name)->setSessionsPerWeek(1);
            $this->em->persist($team);
            $coach = (new Coach)->setClubId($clubId)->setSeasonId($seasonId)->setFirstName('Coach')->setLastName($name);
            $this->em->persist($coach);
            $this->em->flush();
            $this->em->persist((new TeamCoach)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
            $teams[$name] = $team->getId();
        }

        $this->em->persist((new Constraint)->setClubId($clubId)->setSeasonId($seasonId)->setName('Vendredi imposé')
            ->setScope(ConstraintScope::TEAM)->setScopeTargetId($teams['Seniors A'])->setFamily(ConstraintFamily::DAY)
            ->setRuleType(ConstraintRuleType::HARD)->setConfig(['forcedDays' => [self::FRIDAY]])->setIsActive(true));
        $this->em->persist((new Reservation)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($teams['Cadets'])->setVenueId($venue->getId())
            ->setDayOfWeek(self::FRIDAY)->setStartTime(new DateTimeImmutable('1970-01-01 18:00'))->setDurationMinutes(90));

        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('V1')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();
        $slot = fn (string $team, int $day, string $start): ScheduleSlotTemplate => (new ScheduleSlotTemplate)->setClubId($clubId)->setSeasonId($seasonId)
            ->setScheduleId($schedule->getId())->setTeamId($teams[$team])->setVenueId($venue->getId())->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable('1970-01-01 ' . $start))->setDurationMinutes(90);
        $this->em->persist($slot('Cadets', self::FRIDAY, '18:00')->setLockLevel(LockLevel::HARD)->setLockOrigin(LockOrigin::RESERVATION));
        $this->em->persist($slot('Minimes', self::MONDAY, '19:30'));
        $this->em->flush();

        return ['schedule' => $schedule, 'seniors' => $teams['Seniors A'], 'venue' => $venue->getId()];
    }
}
