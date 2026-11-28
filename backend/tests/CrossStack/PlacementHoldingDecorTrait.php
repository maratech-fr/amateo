<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\Person;
use App\Entity\Place;
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
use App\Matches\CompetitionRegistry;
use App\Matches\FriendlyCompetitions;
use App\Plans\PlanComposition;
use App\Service\ClubPublicHolidays;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\StagesFixturesTrait;
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
    use StagesFixturesTrait;
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

        $place = (new Place)->setClubId($this->club->getId());
        $this->em->persist($place);
        $venue = new Venue($place);
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
        // Lot 5 : une version en vigueur porte la copie de son état (la porte lève sinon).
        SeasonPlanOf::keepTheDecorInForce(static::getContainer(), $planId);

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
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        SeasonPlanOf::keepTheSeasonInForce(static::getContainer(), $this->season->getId());
        static::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2]]);
        $this->em->flush();

        return $team->getId();
    }

    /**
     * Le coach de cet identifiant existe dans le club, avec sa fiche : la porte écarte de la copie un
     * lien qui nomme un coach absent de la base (lot 5, références disparues), le décor les crée donc.
     */
    private function ensureCoach(string $coachId): void
    {
        if (null !== $this->em->find(Coach::class, $coachId)) {
            return;
        }
        $person = (new Person)->setClubId($this->club->getId())->setFirstName('Coach')->setLastName(substr($coachId, 0, 8));
        $this->em->persist($person);
        $coach = new Coach($person)
            ->setId($coachId)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setIsActive(true);
        $this->em->persist($coach);
        $this->em->flush();
    }

    private function link(string $coachId, string $teamId, TeamCoachRole $role = TeamCoachRole::MAIN): void
    {
        $this->ensureCoach($coachId);
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
        $this->ensureCoach($personId);
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
        if (null !== $coachId) {
            $this->ensureCoach($coachId);
        }
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

    /** Le match, dans `$competitionId` ; sans compétition nommée, dans « Amicaux » (un amical, l'ancien `competitionId` nul). */
    private function baseFixture(string $teamId, ?DateTimeImmutable $date, FixtureHomeAway $side, ?string $kickoff, ?string $competitionId = null): Fixture
    {
        $competitionId ??= static::getContainer()->get(FriendlyCompetitions::class)->engageInDefault($this->club->getId(), $this->season->getId(), $teamId)->getId();
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competitionId);
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
        $registry = static::getContainer()->get(CompetitionRegistry::class);
        $competition = $registry->create($this->club->getId(), $this->season->getId(), 'Championnat ' . $this->newUuid(), CompetitionType::CHAMPIONSHIP);
        $registry->engage($competition, $teamId);
        $this->em->flush();

        return $competition->getId();
    }

    /** Un domicile de championnat que le solveur doit poser. */
    private function homeToPlace(string $teamId, ?DateTimeImmutable $date = null): string
    {
        $fixture = $this->baseFixture($teamId, $date, FixtureHomeAway::HOME, null, $this->competition($teamId));
        $this->em->flush();

        return $fixture->getId();
    }

    /** Un domicile déjà posé par le solveur : au prochain « Placer », il repart à placer. */
    private function homePlacedBySolver(string $teamId, string $kickoff): string
    {
        $fixture = $this->baseFixture($teamId, null, FixtureHomeAway::HOME, $kickoff, $this->competition($teamId));
        $fixture->setVenueId($this->venueId);
        $this->stageFixture($fixture, FixtureStatus::PLACED, new DateTimeImmutable);
        $fixture->setPlacementSource(FixturePlacementSource::SOLVER);
        $this->em->flush();

        return $fixture->getId();
    }

    /** Un domicile posé à la main : figé pour le solveur. */
    private function homeFixed(string $teamId, string $kickoff, ?DateTimeImmutable $date = null): string
    {
        $fixture = $this->baseFixture($teamId, $date, FixtureHomeAway::HOME, $kickoff, $this->competition($teamId));
        $fixture->setVenueId($this->venueId);
        $this->stageFixture($fixture, FixtureStatus::PLACED, new DateTimeImmutable);
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
        $fixture = $this->baseFixture($teamId, null, FixtureHomeAway::HOME, $kickoff, $this->competition($teamId));
        $fixture->setUnplacedReason(FixtureUnplacedReason::NEUTRAL_GROUND);
        $this->em->flush();
    }

    /** Indisponibilité d'entraînement (wizard), le vendredi, sur toute la journée ou une plage. */
    private function wizardUnavailable(string $coachId, ?string $from = null, ?string $until = null): string
    {
        $this->ensureCoach($coachId);
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
        $block->setSchedulePlanId($planId ?? SeasonPlanOf::id(static::getContainer(), $this->season->getId()));
        $block->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        foreach ($teamIds as $teamId) {
            $member = new SharedTrainingBlockTeam;
            $member->setClubId($this->club->getId());
            $member->setSeasonId($this->season->getId());
            $member->setSchedulePlanId($planId ?? SeasonPlanOf::id(static::getContainer(), $this->season->getId()));
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
        // Le décor est fini : le socle en vigueur porte son état (lot 5, la porte lit la copie).
        SeasonPlanOf::keepTheSeasonInForce(static::getContainer(), $this->season->getId());
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
