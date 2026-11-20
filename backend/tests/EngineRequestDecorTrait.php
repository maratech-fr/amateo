<?php

declare(strict_types=1);

namespace App\Tests;

use App\Clock\DevClockStore;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Competition;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\MatchConstraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\CompetitionType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLinkType;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Décor des demandes au moteur : un petit club dont CHAQUE ligne qui peut paraître dans une demande
 * porte un identifiant ÉCRIT EN DUR. Une demande composée deux fois sur ce décor doit sortir à l'octet
 * près — un identifiant aléatoire la rendrait différente à chaque passage, et une référence capturée
 * ne vaudrait plus rien. Les lignes de chaque table sont insérées dans l'ordre croissant de leur
 * identifiant : l'ordre physique de départ est donc l'ordre trié, et réécrire la première ligne d'une
 * table suffit à le défaire.
 *
 * Le club : deux gymnases (Nord sécable, Sud), trois équipes, deux entraîneurs, une passerelle,
 * quatre contraintes permanentes, une réservation de socle. Au choix ensuite : le socle (versions,
 * séances, verrou), une fermeture du gymnase Sud avec son plan (deux contraintes datées dont « garder
 * ses créneaux », une réservation de période, des versions), et les matchs.
 *
 * Seuls les plans (`schedule_plan`) naissent par le provisioner, comme en production : leur
 * identifiant est aléatoire. La garde `assertEveryUuidIsFixed` prouve qu'il ne paraît dans aucune
 * demande.
 *
 * L'horloge est pinnée sur le lundi 2026-01-05 (`DevClockStore`) : `pinEngineRequestClock()` en
 * setUp, `unpinEngineRequestClock()` en tearDown.
 *
 * Consommé par `Integration/EngineRequestOrderIsStableTest` et `Integration/EngineRequestReferenceTest`.
 */
trait EngineRequestDecorTrait
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const string ER_CLUB = 'c10b0000-0000-4000-8000-000000000001';
    private const string ER_USER = '05e40000-0000-4000-8000-000000000001';
    private const string ER_SEASON = '5ea50000-0000-4000-8000-000000000001';
    private const string ER_SPORT = '590a0000-0000-4000-8000-000000000001';
    private const string ER_CATEGORY = 'ca7e0000-0000-4000-8000-000000000001';
    private const string ER_VENUE_NORD = 'be0e0000-0000-4000-8000-000000000001';
    private const string ER_VENUE_SUD = 'be0e0000-0000-4000-8000-000000000002';
    private const string ER_TEAM_U13 = '7ea00000-0000-4000-8000-000000000001';
    private const string ER_TEAM_U15 = '7ea00000-0000-4000-8000-000000000002';
    private const string ER_TEAM_SM1 = '7ea00000-0000-4000-8000-000000000003';
    private const string ER_COACH_A = 'c0ac0000-0000-4000-8000-000000000001';
    private const string ER_COACH_B = 'c0ac0000-0000-4000-8000-000000000002';
    private const string ER_ENTRY = 'e0770000-0000-4000-8000-000000000001';
    private const string ER_SOCLE_V1 = '5c4e0000-0000-4000-8000-000000000001';
    private const string ER_SOCLE_V2 = '5c4e0000-0000-4000-8000-000000000002';
    private const string ER_PERIOD_P1 = '5c4e0000-0000-4000-8000-000000000011';
    private const string ER_PERIOD_P2 = '5c4e0000-0000-4000-8000-000000000012';

    /** Lundi de référence de l'horloge pinnée. */
    private const string ER_TODAY = '2026-01-05T12:00:00+00:00';

    private static function erId(string $prefix, int $n): string
    {
        return \sprintf('%s0000-0000-4000-8000-%012d', $prefix, $n);
    }

    private function pinEngineRequestClock(): void
    {
        static::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable(self::ER_TODAY));
    }

    private function unpinEngineRequestClock(): void
    {
        static::getContainer()->get(DevClockStore::class)->set(null);
    }

    private function erEm(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Le payload du socle est servi 4 h par le pool `cache.schedule` (Redis, HORS de la transaction
     * DAMA : il survit d'un test à l'autre et d'un run à l'autre, et la clé porte les identifiants
     * fixes de ce décor). À appeler avant CHAQUE composition : sans quoi une demande relue au cache
     * rendrait le test vert sans rien prouver. Purge ciblée sur le tag du club, jamais le pool entier.
     */
    private function purgeEngineRequestCache(): void
    {
        $pool = static::getContainer()->get('cache.schedule');
        Assert::assertInstanceOf(TagAwareCacheInterface::class, $pool, 'le pool cache.schedule doit être tag-aware');
        $pool->invalidateTags([ScheduleConstraintBuilder::cacheTag(self::ER_CLUB)]);
    }

    /**
     * Le socle commun : club, saison (plan SEASON né), catégorie, gymnases, grille de saison,
     * équipes, entraîneurs, passerelle, contraintes permanentes, réservation de socle.
     */
    private function seedEngineRequestBase(): void
    {
        $em = $this->erEm();

        $club = (new Club)->setId(self::ER_CLUB)->setName('Club de référence')->setSlug('engine-request-reference')
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $em->persist($club);
        $user = (new User)->setId(self::ER_USER)->setEmail('engine-request-reference@test.com')
            ->setFirstName('Ges')->setLastName('Tion')->setPasswordHash('not-a-real-hash');
        $em->persist($user);
        $em->flush();

        $this->scopeGucToClub(self::ER_CLUB);
        $em->persist((new ClubUser)->setClubId(self::ER_CLUB)->setUserId(self::ER_USER)->setRole('admin')->setIsActive(true));

        $season = (new Season)->setId(self::ER_SEASON)->setClubId(self::ER_CLUB)->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-08-01'))->setEndDate(new DateTimeImmutable('2026-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $em->persist($season);
        $em->flush();
        static::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $em->flush();

        $sport = $em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $sport = (new Sport)->setId(self::ER_SPORT)->setName('Basket référence')->setSlug('basket-reference')->setIsActive(true);
            $em->persist($sport);
        }
        $em->persist((new SportCategory)->setId(self::ER_CATEGORY)->setClubId(self::ER_CLUB)->setSportId($sport->getId())
            ->setName('Catégorie de référence')->setMatchMinutes(80)->setWarmupMinutes(30));

        $em->persist((new Venue)->setId(self::ER_VENUE_NORD)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setName('Gymnase Nord')->setCanSplit(true)->setSource('manual'));
        $em->persist((new Venue)->setId(self::ER_VENUE_SUD)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setName('Gymnase Sud')->setCanSplit(false)->setSource('manual'));

        foreach ([
            [1, self::ER_VENUE_NORD, 1, '18:00', 2],
            [2, self::ER_VENUE_NORD, 1, '19:30', 2],
            [3, self::ER_VENUE_NORD, 3, '18:00', 2],
            [4, self::ER_VENUE_NORD, 4, '18:00', 2],
            [5, self::ER_VENUE_SUD, 2, '18:00', 1],
            [6, self::ER_VENUE_SUD, 4, '19:30', 1],
        ] as [$n, $venueId, $day, $time, $capacity]) {
            $em->persist((new VenueTrainingSlot)->setId(self::erId('a7a1', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                ->setVenueId($venueId)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))
                ->setDurationMinutes(90)->setCapacity($capacity));
        }

        foreach ([[self::ER_TEAM_U13, 'U13 F', 3], [self::ER_TEAM_U15, 'U15 M', 2], [self::ER_TEAM_SM1, 'SM1', 1]] as [$teamId, $name, $tier]) {
            $em->persist((new Team)->setId($teamId)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                ->setSportCategoryId(self::ER_CATEGORY)->setPriorityTierId($tier)->setName($name)
                ->setSessionsPerWeek(2)->setIsActive(true));
        }

        $em->persist((new Coach)->setId(self::ER_COACH_A)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setFirstName('Alix')->setLastName('Entraîneur'));
        $em->persist((new Coach)->setId(self::ER_COACH_B)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setFirstName('Bao')->setLastName('Entraîneur'));
        $em->flush();

        foreach ([[1, self::ER_TEAM_U13, self::ER_COACH_A], [2, self::ER_TEAM_U15, self::ER_COACH_A], [3, self::ER_TEAM_SM1, self::ER_COACH_B]] as [$n, $teamId, $coachId]) {
            $em->persist((new TeamCoach)->setId(self::erId('7c0a', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                ->setTeamId($teamId)->setCoachId($coachId)->setRole(TeamCoachRole::MAIN));
        }
        // L'entraîneur A joue aussi en SM1.
        $em->persist((new CoachPlayerMembership)->setId(self::erId('3e3b', 1))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setCoachId(self::ER_COACH_A)->setTeamId(self::ER_TEAM_SM1)->setIsActive(true));
        $em->persist((new TeamLink)->setId(self::erId('11c0', 1))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setTeamAId(self::ER_TEAM_U13)->setTeamBId(self::ER_TEAM_U15)->setLinkType(TeamLinkType::NOT_SIMULTANEOUS));

        $this->erConstraint(1, ConstraintScope::TEAM, self::ER_TEAM_U13, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['minStartTime' => '18:00']);
        $this->erConstraint(2, ConstraintScope::TEAM, self::ER_TEAM_U15, ConstraintFamily::DAY, ConstraintRuleType::PREFERRED, ['preferredDays' => [1, 3]]);
        $this->erConstraint(3, ConstraintScope::TEAM, self::ER_TEAM_SM1, ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => self::ER_VENUE_NORD]);
        $this->erConstraint(4, ConstraintScope::COACH, self::ER_COACH_B, ConstraintFamily::COACH_AVAILABILITY, ConstraintRuleType::HARD, ['unavailableDays' => [5]]);

        // Réservation de SOCLE (schedulePlanId null) : SM1 le mardi 18:00 au gymnase Sud.
        $em->persist((new Reservation)->setId(self::erId('e5e0', 1))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setTeamId(self::ER_TEAM_SM1)->setVenueId(self::ER_VENUE_SUD)->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        $em->flush();
    }

    /**
     * Une version du plan SEASON, numérotée par le provisioner comme au POST. `$slots` :
     * liste de [n, équipe, gymnase, jour, heure, verrou].
     *
     * @param list<array{0: int, 1: string, 2: string, 3: int, 4: string, 5: LockLevel}> $slots
     */
    private function seedSocleVersion(string $scheduleId, ScheduleStatus $status, array $slots = []): Schedule
    {
        return $this->erVersion($scheduleId, null, $status, $slots);
    }

    /**
     * La fermeture du gymnase Sud (semaine du 9 février) et son plan, né par le provisioner (copie de
     * la grille de saison), avec deux contraintes datées (U13 « garder ses créneaux de saison », U15
     * pas le mardi) et une réservation de période. Rend l'id du plan (aléatoire).
     */
    private function seedEngineRequestPeriod(): string
    {
        $em = $this->erEm();
        $em->persist((new CalendarEntry)->setId(self::ER_ENTRY)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Fermeture du gymnase Sud')
            ->setStartDate(new DateTimeImmutable('2026-02-09'))->setEndDate(new DateTimeImmutable('2026-02-15'))
            ->setStatus(CalendarEntryStatus::ACTIVE));
        $em->flush();
        $entry = $em->getRepository(CalendarEntry::class)->find(self::ER_ENTRY);
        Assert::assertInstanceOf(CalendarEntry::class, $entry);
        $planId = $this->planIdOf($entry);

        $this->erConstraint(5, ConstraintScope::TEAM, self::ER_TEAM_U13, ConstraintFamily::SEASON_SLOTS, ConstraintRuleType::PREFERRED, [], self::ER_ENTRY);
        $this->erConstraint(6, ConstraintScope::TEAM, self::ER_TEAM_U15, ConstraintFamily::DAY, ConstraintRuleType::PREFERRED, ['forbiddenDays' => [2]], self::ER_ENTRY);
        $em->persist((new Reservation)->setId(self::erId('e5e0', 2))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setSchedulePlanId($planId)->setTeamId(self::ER_TEAM_U15)->setVenueId(self::ER_VENUE_NORD)->setDayOfWeek(3)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        $em->flush();

        return $planId;
    }

    /**
     * Une version du plan de la période.
     *
     * @param list<array{0: int, 1: string, 2: string, 3: int, 4: string, 5: LockLevel}> $slots
     */
    private function seedPeriodVersion(string $scheduleId, ScheduleStatus $status, array $slots = []): Schedule
    {
        return $this->erVersion($scheduleId, self::ER_ENTRY, $status, $slots);
    }

    /** Le socle pointé (« en vigueur ») ET chargé : `chosen_schedule_id` + `live_context_schedule_id`. */
    private function chooseAndLoadSocle(string $scheduleId): void
    {
        $em = $this->erEm();
        $schedule = $em->getRepository(Schedule::class)->find($scheduleId);
        Assert::assertInstanceOf(Schedule::class, $schedule);
        Assert::assertTrue(static::getContainer()->get(SchedulePlanProvisioner::class)->choose($schedule), 'le socle doit pouvoir être pointé');
        $this->loadSocle($scheduleId);
    }

    /** « Charger » une version de socle : l'étoile de la saison. */
    private function loadSocle(string $scheduleId): void
    {
        $em = $this->erEm();
        $season = $em->getRepository(Season::class)->find(self::ER_SEASON);
        Assert::assertInstanceOf(Season::class, $season);
        $season->setLiveContextScheduleId($scheduleId);
        $em->flush();
    }

    /**
     * Les matchs : quatre rencontres (deux domiciles à placer, un domicile déjà posé par le solveur,
     * un extérieur), des créneaux de match aux deux gymnases, une habitude, une fenêtre de ligue,
     * une règle de club, une interdiction de gymnase, une indisponibilité d'entraîneur.
     */
    private function seedEngineRequestMatches(): void
    {
        $em = $this->erEm();
        foreach ([[1, self::ER_TEAM_U13], [2, self::ER_TEAM_U15], [3, self::ER_TEAM_SM1]] as [$n, $teamId]) {
            $em->persist((new Competition)->setId(self::erId('c0e9', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                ->setTeamId($teamId)->setName('Championnat ' . $n)->setCompetitionType(CompetitionType::CHAMPIONSHIP));
        }
        $em->flush();

        foreach ([
            [1, self::ER_TEAM_U13, 1, '2026-01-17', FixtureHomeAway::HOME],
            [2, self::ER_TEAM_U15, 2, '2026-01-24', FixtureHomeAway::HOME],
            [3, self::ER_TEAM_SM1, 3, '2026-01-18', FixtureHomeAway::AWAY],
            [4, self::ER_TEAM_U13, 1, '2026-01-31', FixtureHomeAway::HOME],
        ] as [$n, $teamId, $competition, $date, $homeAway]) {
            $fixture = (new Fixture)->setId(self::erId('f1c7', $n));
            $fixture->setClubId(self::ER_CLUB);
            $fixture->setSeasonId(self::ER_SEASON);
            $fixture->setTeamId($teamId);
            $fixture->setCompetitionId(self::erId('c0e9', $competition));
            $fixture->setMatchDate(new DateTimeImmutable($date));
            $fixture->setHomeAway($homeAway);
            $fixture->setOpponentLabel('Adversaire ' . $n);
            if (3 === $n) {
                $fixture->setKickoffTime(new DateTimeImmutable('15:30'));
            }
            if (4 === $n) {
                $fixture->setVenueId(self::ER_VENUE_NORD);
                $fixture->setKickoffTime(new DateTimeImmutable('16:00'));
                $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable('2026-01-02T10:00:00+00:00'));
                $fixture->setPlacementSource(FixturePlacementSource::SOLVER);
            }
            $em->persist($fixture);
        }

        foreach ([[1, self::ER_VENUE_NORD, 6, '13:00', '21:00'], [2, self::ER_VENUE_SUD, 7, '10:00', '18:00']] as [$n, $venueId, $day, $start, $end]) {
            $em->persist((new VenueMatchWindow)->setId(self::erId('a10d', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                ->setVenueId($venueId)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setEndTime(new DateTimeImmutable($end)));
        }
        $em->persist((new TeamMatchHabit)->setId(self::erId('ab17', 1))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setTeamId(self::ER_TEAM_U13)->setDayOfWeek(6)->setKickoffTime(new DateTimeImmutable('14:00'))->setVenueId(self::ER_VENUE_NORD));
        $em->persist((new ClubLeagueWindow)->setId(self::erId('1ea9', 1))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setLeague('Ligue de référence')->setCategory('U13')->setLevel('Départemental')->setGender('F')
            ->setDayOfWeek(6)->setKickoffMin(new DateTimeImmutable('13:00'))->setKickoffMax(new DateTimeImmutable('18:00')));
        $em->persist((new MatchConstraint)->setId(self::erId('acc0', 1))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setScope(ConstraintScope::CLUB)->setRuleType(ConstraintRuleType::PREFERRED)->setDaysOfWeek([6])
            ->setKickoffMin(new DateTimeImmutable('14:00'))->setKickoffMax(new DateTimeImmutable('19:00')));
        $em->persist((new MatchConstraint)->setId(self::erId('acc0', 2))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setScope(ConstraintScope::TEAM)->setScopeTargetId(self::ER_TEAM_U15)->setRuleType(ConstraintRuleType::HARD)
            ->setVenueId(self::ER_VENUE_SUD));
        $em->persist((new MatchConstraint)->setId(self::erId('acc0', 3))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setScope(ConstraintScope::COACH)->setScopeTargetId(self::ER_COACH_A)->setRuleType(ConstraintRuleType::PREFERRED)
            ->setDaysOfWeek([7])->setKickoffMin(new DateTimeImmutable('09:00'))->setKickoffMax(new DateTimeImmutable('12:00')));
        $em->flush();
    }

    /** L'id (aléatoire, né du provisioner) du plan SEASON du décor. */
    private function erSeasonPlanId(): string
    {
        $planId = $this->erEm()->getConnection()->fetchOne(
            'SELECT id FROM schedule_plan WHERE season_id = :s AND type = :t',
            ['s' => self::ER_SEASON, 't' => 'SEASON'],
        );
        Assert::assertIsString($planId);

        return $planId;
    }

    /**
     * Chaque UUID d'une demande appartient au décor fixe : sinon une ligne née d'un identifiant
     * aléatoire est entrée dans la demande, et la référence ne se rejouera pas.
     *
     * @param array<mixed> $request
     */
    private function assertEveryUuidIsFixed(array $request, string $label): void
    {
        $json = json_encode($request, \JSON_THROW_ON_ERROR);
        preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $json, $matches);
        $unknown = array_values(array_unique(array_filter(
            $matches[0],
            static fn (string $uuid): bool => 1 !== preg_match('/^[0-9a-f]{4}0000-0000-4000-8000-\d{12}$/', $uuid),
        )));
        Assert::assertSame([], $unknown, $label . ' : un identifiant hors du décor fixe est entré dans la demande');
        Assert::assertNotSame([], $matches[0], $label . ' : la garde doit avoir vu au moins un identifiant (sinon elle ne prouve rien)');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function erConstraint(int $n, ConstraintScope $scope, ?string $targetId, ConstraintFamily $family, ConstraintRuleType $ruleType, array $config, ?string $entryId = null): void
    {
        $this->erEm()->persist((new Constraint)->setId(self::erId('c057', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setCalendarEntryId($entryId)->setName('Contrainte ' . $n)->setScope($scope)->setScopeTargetId($targetId)
            ->setFamily($family)->setRuleType($ruleType)->setConfig($config));
    }

    /**
     * @param list<array{0: int, 1: string, 2: string, 3: int, 4: string, 5: LockLevel}> $slots
     */
    private function erVersion(string $scheduleId, ?string $entryId, ScheduleStatus $status, array $slots): Schedule
    {
        $em = $this->erEm();
        $provisioner = static::getContainer()->get(SchedulePlanProvisioner::class);
        $planId = null === $entryId ? $provisioner->ensureSeasonPlanId(self::ER_SEASON) : $provisioner->provisionPeriodPlan($entryId);
        Assert::assertIsString($planId);
        $plan = $em->getRepository(SchedulePlan::class)->find($planId);
        Assert::assertInstanceOf(SchedulePlan::class, $plan);

        $schedule = (new Schedule)->setId($scheduleId)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setName('Version ' . substr($scheduleId, -2))->setStatus($status)->setSchedulePlanId($planId);
        $em->persist($schedule);
        $provisioner->linkSchedule($schedule);
        $em->flush();

        foreach ($slots as [$n, $teamId, $venueId, $day, $time, $level]) {
            $em->persist((new ScheduleSlotTemplate)->setId(self::erId('5107', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                ->setScheduleId($scheduleId)->setTeamId($teamId)->setVenueId($venueId)->setDayOfWeek($day)
                ->setStartTime(new DateTimeImmutable($time))->setDurationMinutes(90)->setLockLevel($level)
                ->setLockOrigin(LockLevel::HARD === $level ? LockOrigin::MANUAL : null));
        }
        $em->flush();

        return $schedule;
    }
}
