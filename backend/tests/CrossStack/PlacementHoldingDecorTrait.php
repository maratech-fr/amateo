<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\CoachPlayerMembership;
use App\Entity\Competition;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Enum\CompetitionType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\ClubPublicHolidays;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le décor des tests « un coach tient la séance » côté placement : un club, un planning de saison
 * pointé, un vendredi d'octobre (jamais férié), puis équipes, séances et matchs posés par entités.
 */
trait PlacementHoldingDecorTrait
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $scheduleId;

    private string $venueId;

    private DateTimeImmutable $friday;

    private function seedDecor(): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Tenue Placement ' . $uid);
        $this->club->setSlug('tenue-placement-' . $uid);
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

        $this->friday = $this->fridayFromNow(1);
        $this->scheduleId = $this->pointedVersion($this->seasonPlanIdOf($this->season));

        $venue = new Venue;
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName('Gymnase de test');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $this->venueId = $venue->getId();
    }

    /**
     * Le n-ième vendredi à venir qui n'est pas férié : le radar ignore les dates passées, et un jour
     * férié n'a pas de séance. Le club de test n'a pas de territoire, mais le décor n'en dépend pas.
     */
    private function fridayFromNow(int $rank): DateTimeImmutable
    {
        $holidays = self::getContainer()->get(ClubPublicHolidays::class)->datesInWindow($this->club->getId(), $this->season->getStartDate(), $this->season->getEndDate());
        $friday = new DateTimeImmutable('today')->modify('next friday');
        while ($rank > 0) {
            if (!\in_array($friday->format('Y-m-d'), $holidays, true)) {
                --$rank;
            }
            if ($rank > 0) {
                $friday = $friday->modify('+7 days');
            }
        }

        return $friday;
    }

    /** Une version terminée, pointée par le plan donné : le calendrier en vigueur de ce plan. */
    private function pointedVersion(string $planId): string
    {
        $schedule = new Schedule;
        $schedule->setClubId($this->club->getId());
        $schedule->setSeasonId($this->season->getId());
        $schedule->setSchedulePlanId($planId);
        $schedule->setName('v1');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        $this->em->flush();

        $plan = $this->em->getRepository(SchedulePlan::class)->find($planId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        $plan->setChosenScheduleId($schedule->getId());
        $this->em->flush();

        return $schedule->getId();
    }

    private function team(string $name): string
    {
        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($this->season->getId());
        $team->setSportCategoryId($this->newUuid());
        $team->setPriorityTierId(3);
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    private function link(string $coachId, string $teamId, TeamCoachRole $role = TeamCoachRole::MAIN): void
    {
        $link = new TeamCoach;
        $link->setClubId($this->club->getId());
        $link->setSeasonId($this->season->getId());
        $link->setTeamId($teamId);
        $link->setCoachId($coachId);
        $link->setRole($role);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function player(string $personId, string $teamId): void
    {
        $membership = new CoachPlayerMembership;
        $membership->setClubId($this->club->getId());
        $membership->setSeasonId($this->season->getId());
        $membership->setTeamId($teamId);
        $membership->setCoachId($personId);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();
    }

    /** Une séance du vendredi, 90 minutes, dans la version donnée (par défaut le planning de saison). */
    private function session(string $teamId, ?string $coachId, string $start = '20:00', ?string $scheduleId = null): void
    {
        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($this->club->getId());
        $slot->setSeasonId($this->season->getId());
        $slot->setScheduleId($scheduleId ?? $this->scheduleId);
        $slot->setTeamId($teamId);
        $slot->setVenueId($this->venueId);
        $slot->setCoachId($coachId);
        $slot->setDayOfWeek(5);
        $slot->setStartTime(new DateTimeImmutable($start));
        $slot->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();
    }

    private function baseFixture(string $teamId, ?DateTimeImmutable $date, FixtureHomeAway $side, ?string $kickoff): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate($date ?? $this->friday);
        $fixture->setHomeAway($side);
        $fixture->setOpponentLabel('Adversaire');
        if (null !== $kickoff) {
            $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null);
        }
        $this->em->persist($fixture);

        return $fixture;
    }

    private function competition(string $teamId): string
    {
        $competition = new Competition;
        $competition->setClubId($this->club->getId());
        $competition->setSeasonId($this->season->getId());
        $competition->setTeamId($teamId);
        $competition->setName('Championnat ' . $this->newUuid());
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition->getId();
    }

    /** Un domicile de championnat que le solveur doit poser. */
    private function homeToPlace(string $teamId, ?DateTimeImmutable $date = null): string
    {
        $fixture = $this->baseFixture($teamId, $date, FixtureHomeAway::HOME, null);
        $fixture->setCompetitionId($this->competition($teamId));
        $this->em->flush();

        return $fixture->getId();
    }

    /** Un domicile déjà posé par le solveur : au prochain « Placer », il repart à placer. */
    private function homePlacedBySolver(string $teamId, string $kickoff): string
    {
        $fixture = $this->baseFixture($teamId, null, FixtureHomeAway::HOME, $kickoff);
        $fixture->setCompetitionId($this->competition($teamId));
        $fixture->setVenueId($this->venueId);
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $fixture->setPlacementSource(FixturePlacementSource::SOLVER);
        $this->em->flush();

        return $fixture->getId();
    }

    /** Un domicile posé à la main : figé pour le solveur. */
    private function homeFixed(string $teamId, string $kickoff, ?DateTimeImmutable $date = null): string
    {
        $fixture = $this->baseFixture($teamId, $date, FixtureHomeAway::HOME, $kickoff);
        $fixture->setCompetitionId($this->competition($teamId));
        $fixture->setVenueId($this->venueId);
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
        $this->em->flush();

        return $fixture->getId();
    }

    private function away(string $teamId, ?string $kickoff): Fixture
    {
        $fixture = $this->baseFixture($teamId, null, FixtureHomeAway::AWAY, $kickoff);
        $this->em->flush();

        return $fixture;
    }

    private function neutralGround(string $teamId, string $kickoff): void
    {
        $fixture = $this->baseFixture($teamId, null, FixtureHomeAway::HOME, $kickoff);
        $fixture->setCompetitionId($this->competition($teamId));
        $fixture->setUnplacedReason(FixtureUnplacedReason::NEUTRAL_GROUND);
        $this->em->flush();
    }

    /** Indisponibilité d'entraînement (wizard), le vendredi, sur toute la journée ou une plage. */
    private function wizardUnavailable(string $coachId, ?string $from = null, ?string $until = null): string
    {
        $config = ['unavailableDays' => [5]];
        if (null !== $from) {
            $config['fromTime'] = $from;
        }
        if (null !== $until) {
            $config['untilTime'] = $until;
        }
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId($coachId);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setName('Indisponible le vendredi');
        $constraint->setConfig($config);
        $constraint->setIsActive(true);
        $constraint->setSortOrder(0);
        $this->em->persist($constraint);
        $this->em->flush();

        return $constraint->getId();
    }

    /** @param list<string> $teamIds */
    private function declareGroup(array $teamIds, ?string $planId = null): void
    {
        $block = new SharedTrainingBlock;
        $block->setClubId($this->club->getId());
        $block->setSeasonId($this->season->getId());
        $block->setSchedulePlanId($planId);
        $block->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        foreach ($teamIds as $teamId) {
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

    /**
     * @param array{from: string, to: string}|null $window
     *
     * @return array<string, mixed>
     */
    private function payload(?array $window = null): array
    {
        $this->em->clear();

        return self::getContainer()->get(MatchPlacementPayloadBuilder::class)->build($this->club, $this->season->getId(), $window)['payload'];
    }

    /**
     * Les personnes retenues par une séance à cette date dans le payload, triées et sans doublon.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function occupiedOn(array $payload, ?DateTimeImmutable $date = null): array
    {
        $day = ($date ?? $this->friday)->format('Y-m-d');
        $persons = [];
        foreach ($payload['trainingOccupancies'] as $occupancy) {
            if ($day === $occupancy['date']) {
                $persons[$occupancy['coachId']] = true;
            }
        }
        $persons = array_map(strval(...), array_keys($persons));
        sort($persons);

        return $persons;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
