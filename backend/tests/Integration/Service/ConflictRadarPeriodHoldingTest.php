<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\TeamCoach;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\ConflictRadarLoader;
use App\Service\SeasonResolver;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse : qui tient une séance se juge sur le plan EFFECTIF de la date — les indisponibilités
 * d'entraînement et les groupes déclarés du plan de période à ses dates, ceux du socle ailleurs,
 * jamais un mélange des deux.
 */
#[Group('integration')]
final class ConflictRadarPeriodHoldingTest extends WebTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const string COACH_B = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const string COACH_E = 'eeeeeeee-0000-4000-8000-000000000002';
    private const string COACH_D = 'dddddddd-0000-4000-8000-000000000003';
    private const string TEAM_MATCH = 'aaaaaaaa-0000-4000-8000-000000000011';
    private const string TEAM_SESSION = 'aaaaaaaa-0000-4000-8000-000000000012';
    private const string TEAM_PARTNER = 'aaaaaaaa-0000-4000-8000-000000000013';
    private const string VENUE = 'cccccccc-0000-4000-8000-000000000021';

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $seasonScheduleId;

    private string $periodPlanId;

    private string $periodScheduleId;

    private DateTimeImmutable $periodFriday;

    private DateTimeImmutable $ordinaryFriday;

    /** L'override qui coupe l'indispo dans le plan de période libère ses seules dates. */
    public function testAPeriodOverrideOfTheWizardUnavailabilityFreesOnlyThePeriodDates(): void
    {
        $this->link(self::COACH_B, self::TEAM_MATCH);
        $this->link(self::COACH_B, self::TEAM_SESSION);
        $this->link(self::COACH_E, self::TEAM_SESSION);
        $this->sessionOn($this->seasonScheduleId, self::TEAM_SESSION, self::COACH_B);
        $this->sessionOn($this->periodScheduleId, self::TEAM_SESSION, self::COACH_B);
        $unavailability = $this->wizardUnavailabilityOfE();
        $inPeriod = $this->homeMatch($this->periodFriday);
        $ordinary = $this->homeMatch($this->ordinaryFriday);

        $override = new ConstraintPeriodOverride;
        $override->setClubId($this->club->getId());
        $override->setSeasonId($this->season->getId());
        $override->setSchedulePlanId($this->periodPlanId);
        $override->setConstraintId($unavailability);
        $override->setIsActive(false);
        $this->em->persist($override);
        $this->em->flush();

        $radar = $this->radar();

        self::assertSame(self::COACH_E, $this->heldBy($radar, $inPeriod), 'l\'indispo coupée dans la période : Emma tient');
        self::assertFalse($this->isConflict($radar, $inPeriod));
        self::assertTrue($this->isConflict($radar, $ordinary), 'hors période, l\'indispo du socle vaut encore : rouge');
        self::assertNull($this->heldBy($radar, $ordinary));
    }

    /** Un groupe déclaré sur le seul plan de période ne joue pas aux dates du socle. */
    public function testAGroupDeclaredOnThePeriodPlanOnlyCountsOnThePeriodDates(): void
    {
        $this->groupedSessionsDecor();
        $this->declareGroup($this->periodPlanId);
        $inPeriod = $this->homeMatch($this->periodFriday);
        $ordinary = $this->homeMatch($this->ordinaryFriday);

        $radar = $this->radar();

        self::assertSame(self::COACH_D, $this->heldBy($radar, $inPeriod));
        self::assertTrue($this->isConflict($radar, $ordinary));
    }

    /** Un groupe du socle ne déborde pas sur une période qui ne le déclare pas. */
    public function testAGroupDeclaredOnTheSeasonDoesNotLeakIntoThePeriodDates(): void
    {
        $this->groupedSessionsDecor();
        $this->declareGroup(null);
        $inPeriod = $this->homeMatch($this->periodFriday);
        $ordinary = $this->homeMatch($this->ordinaryFriday);

        $radar = $this->radar();

        self::assertTrue($this->isConflict($radar, $inPeriod));
        self::assertSame(self::COACH_D, $this->heldBy($radar, $ordinary));
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $this->club = new Club;
        $this->club->setName('BC Tenue Période ' . $uid);
        $this->club->setSlug('bc-tenue-periode-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName((string) $year);
        $this->season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $this->season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->season->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->periodFriday = new DateTimeImmutable('today')->modify('next friday')->modify('+7 days');
        $this->ordinaryFriday = $this->periodFriday->modify('+14 days');

        $seasonPlanId = $this->seasonPlanIdOf($this->season);
        $this->seasonScheduleId = $this->completedVersion($seasonPlanId);

        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Fermeture tenue');
        $entry->setStartDate($this->periodFriday->modify('-4 days'));
        $entry->setEndDate($this->periodFriday->modify('+2 days'));
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->periodPlanId = $this->planIdOf($entry);
        $this->periodScheduleId = $this->completedVersion($this->periodPlanId);

        $this->point($seasonPlanId, $this->seasonScheduleId);
        $this->point($this->periodPlanId, $this->periodScheduleId);
    }

    private function completedVersion(string $planId): string
    {
        $schedule = new Schedule;
        $schedule->setClubId($this->club->getId());
        $schedule->setSeasonId($this->season->getId());
        $schedule->setSchedulePlanId($planId);
        $schedule->setName('v1');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule->getId();
    }

    private function point(string $planId, string $scheduleId): void
    {
        $plan = $this->em->getRepository(SchedulePlan::class)->find($planId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        $plan->setChosenScheduleId($scheduleId);
        $this->em->flush();
    }

    private function link(string $coachId, string $teamId): void
    {
        $link = new TeamCoach;
        $link->setClubId($this->club->getId());
        $link->setSeasonId($this->season->getId());
        $link->setTeamId($teamId);
        $link->setCoachId($coachId);
        $link->setRole(TeamCoachRole::MAIN);
        $this->em->persist($link);
        $this->em->flush();
    }

    /** Séance du vendredi 20:00-21:30, sur le gymnase commun. */
    private function sessionOn(string $scheduleId, string $teamId, ?string $coachId): void
    {
        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($this->club->getId());
        $slot->setSeasonId($this->season->getId());
        $slot->setScheduleId($scheduleId);
        $slot->setTeamId($teamId);
        $slot->setVenueId(self::VENUE);
        $slot->setCoachId($coachId);
        $slot->setDayOfWeek(5);
        $slot->setStartTime(new DateTimeImmutable('20:00'));
        $slot->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();
    }

    private function wizardUnavailabilityOfE(): string
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId(self::COACH_E);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setName('Emma indisponible le vendredi');
        $constraint->setConfig(['unavailableDays' => [5]]);
        $constraint->setIsActive(true);
        $constraint->setSortOrder(0);
        $this->em->persist($constraint);
        $this->em->flush();

        return $constraint->getId();
    }

    private function homeMatch(DateTimeImmutable $date): string
    {
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId(self::TEAM_MATCH);
        $fixture->setMatchDate($date);
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', '20:30') ?: null);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    /** B coache le match et une équipe ; D coache l'équipe partenaire, même case dans les deux plans. */
    private function groupedSessionsDecor(): void
    {
        $this->link(self::COACH_B, self::TEAM_MATCH);
        $this->link(self::COACH_B, self::TEAM_SESSION);
        $this->link(self::COACH_D, self::TEAM_PARTNER);
        foreach ([$this->seasonScheduleId, $this->periodScheduleId] as $scheduleId) {
            $this->sessionOn($scheduleId, self::TEAM_SESSION, self::COACH_B);
            $this->sessionOn($scheduleId, self::TEAM_PARTNER, self::COACH_D);
        }
    }

    private function declareGroup(?string $planId): void
    {
        $block = new SharedTrainingBlock;
        $block->setClubId($this->club->getId());
        $block->setSeasonId($this->season->getId());
        $block->setSchedulePlanId($planId);
        $block->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        foreach ([self::TEAM_SESSION, self::TEAM_PARTNER] as $teamId) {
            $member = new SharedTrainingBlockTeam;
            $member->setClubId($this->club->getId());
            $member->setSeasonId($this->season->getId());
            $member->setSchedulePlanId($planId);
            $member->setBlockId($block->getId());
            $member->setTeamId($teamId);
            $this->em->persist($member);
        }
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function radar(): array
    {
        $this->em->clear();

        return self::getContainer()->get(ConflictRadarLoader::class)->conflicts($this->club->getId(), $this->season->getId());
    }

    /** @param array<string, mixed> $radar */
    private function isConflict(array $radar, string $fixtureId): bool
    {
        foreach ($radar['conflicts'] as $conflict) {
            if ('MATCH_TRAINING' === $conflict['type'] && $fixtureId === ($conflict['fixture']['fixtureId'] ?? null) && self::COACH_B === $conflict['coachId']) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $radar */
    private function heldBy(array $radar, string $fixtureId): ?string
    {
        foreach ($radar['held'] ?? [] as $line) {
            if ('MATCH_TRAINING' === $line['type'] && $fixtureId === ($line['fixture']['fixtureId'] ?? null) && self::COACH_B === $line['coachId']) {
                return $line['heldBy'];
            }
        }

        return null;
    }
}
