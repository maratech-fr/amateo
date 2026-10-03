<?php

declare(strict_types=1);

namespace App\Seed;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\MatchConstraint;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueTrainingSlot;
use App\Enum\CompetitionType;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\Gender;
use App\Enum\LockLevel;
use App\Enum\MatchWeek;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLevel;
use App\Enum\TeamLinkType;
use App\Service\Basketball\CategoryCatalog;
use App\Service\ClubLeagueWindowSeeder;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeder ISOLÉ des clubs de charge « rail placement » : des clubs 100 % FICTIFS,
 * structurés comme un vrai club (catégories, gymnases + fenêtres d'accès match,
 * habitudes, coachs mutualisés + joueurs, passerelles, contraintes de match,
 * planning d'entraînement VALIDÉ, matchs de championnat), à trois tailles
 * ({@see LoadTestClubSize}). Aucune donnée réelle : ni vrai club, ni vrais noms de
 * coachs/adversaires (RGPD, cf. audit « dépôt public »).
 *
 * N'emprunte RIEN à {@see BcclSeeder} ni à {@see BcclSeedProfile} (décision
 * fondateur 2026-10-03) : le vrai club BCCL et ses profils dev/démo/prod ne sont
 * pas touchés. Le planning validé est posé par la MÊME primitive que le seed réel
 * (« VALIDER = POINTER » : {@see SchedulePlanProvisioner::choose}) — aucune logique
 * métier neuve, aucun contournement d'ADR-0002.
 *
 * ⚠ Dev-only, connexion ADMIN (traverse la RLS) : garde superuser comme BcclSeeder.
 */
final class LoadTestClubSeeder
{
    private const string SEASON_NAME = '2026-2027';
    private const string SCHEDULE_MARKER = 'load-test-seed';
    private const string MANAGER_PASSWORD = 'charge-load-test-pwd';

    /** Premier samedi de championnat + nombre de week-ends (grand club ≈ 290 matchs domicile). */
    private const string FIRST_SATURDAY = '2026-09-05';
    private const int CHAMPIONSHIP_WEEKENDS = 18;

    /**
     * Gabarits de catégorie cyclés pour fabriquer les équipes : [catalogue, estJeune,
     * niveau, tier, séances/sem, code d'affichage]. « estJeune » → 3 phases d'aller
     * simple ; sinon aller-retour (U21/seniors/loisir).
     *
     * @var list<array{string, bool, TeamLevel, int, int, string}>
     */
    private const array TEAM_TEMPLATES = [
        ['U9', true, TeamLevel::DEPARTEMENTAL, 4, 1, 'U9'],
        ['U11', true, TeamLevel::DEPARTEMENTAL, 4, 1, 'U11'],
        ['U13', true, TeamLevel::DEPARTEMENTAL, 3, 2, 'U13'],
        ['U15', true, TeamLevel::REGIONAL, 3, 2, 'U15'],
        ['U18', true, TeamLevel::REGIONAL, 2, 2, 'U18'],
        ['U21', false, TeamLevel::REGIONAL, 2, 2, 'U21'],
        ['Senior', false, TeamLevel::REGIONAL, 1, 2, 'S'],
        ['Vétéran', false, TeamLevel::LOISIR_ADULTE, 5, 1, 'VET'],
        ['Loisir', false, TeamLevel::LOISIR_ADULTE, 5, 1, 'LOIS'],
    ];

    /** Prénoms/noms FICTIFS de coachs (longueurs premières entre elles → couples uniques). */
    private const array COACH_FIRST_NAMES = ['Alix', 'Sacha', 'Noa', 'Lou', 'Milo', 'Eden', 'Swann'];
    private const array COACH_LAST_NAMES = ['Valmont', 'Brise', 'Montclair', 'Ferrel', 'Dombes', 'Ancely', 'Varèse', 'Lunel', 'Cressac', 'Mirbel', 'Soline'];

    /** Adversaires FICTIFS (jamais un vrai club). */
    private const array OPPONENTS = [
        'BC Fictif Nord', 'Union Sportive Test', 'Étoile Imaginaire', 'AL Nulle Part',
        'CS Mirage', 'Olympique Factice', 'ASB Chimère', 'Entente Virtuelle',
        'Les Sans-Nom', 'Club Témoin', 'BC Repère', 'US Placeholder', 'AC Échantillon',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ClubLeagueWindowSeeder $clubLeagueWindowSeeder,
    ) {}

    /**
     * Sème (ou ré-sème, idempotent) le club de charge d'ordinal $index à la taille $size.
     */
    public function run(EntityManagerInterface $manager, int $index, LoadTestClubSize $size): Club
    {
        if ($index < 1 || $index > 99) {
            throw new RuntimeException(\sprintf('Load-test club index must be 1..99, got %d.', $index));
        }

        $superuser = (bool) $manager->getConnection()->fetchOne('SELECT usesuper FROM pg_user WHERE usename = current_user');
        if (!$superuser) {
            throw new RuntimeException('The load-test seeder must run on the admin connection (RLS silently breaks writes as amateo_app).');
        }

        $club = $this->seedClub($manager, $index);
        $clubId = $club->getId();
        $manager->getConnection()->executeStatement('SELECT set_config(\'app.club_id\', ?, false)', [$clubId]);

        $sport = $this->seedSport($manager);
        $club->setSportId($sport->getId());
        $categoryIdByName = $this->seedCategories($manager, $clubId, $sport);
        $this->seedPriorityTiers($manager);
        $manager->flush();

        $season = $this->seedSeason($manager, $clubId);
        $seasonId = $season->getId();
        $this->schedulePlanProvisioner->ensureSeasonPlan($season);
        // Copie club de l'enveloppe ligue (no-op si le catalogue global n'est pas semé
        // dans l'env — le harnais exécute `app:league-windows:seed` en préalable).
        $this->clubLeagueWindowSeeder->seedForSeason($clubId, $seasonId, $club->getLeague());
        $this->seedManager($manager, $club, $index);
        $manager->flush();

        $this->purgeCollections($manager, $clubId, $seasonId);
        $manager->flush();

        $venues = $this->seedVenues($manager, $clubId, $seasonId, $index, $size);
        $teams = $this->seedTeams($manager, $clubId, $seasonId, $size, $categoryIdByName);
        $manager->flush();

        $coaches = $this->seedCoaches($manager, $clubId, $seasonId, $size, $teams);
        $trainingSlots = $this->seedTrainingGridAndHabits($manager, $clubId, $seasonId, $teams, $venues);
        $manager->flush();

        $this->seedValidatedSeasonPlan($manager, $club, $season, $trainingSlots);
        $this->seedMatchConstraints($manager, $clubId, $seasonId, $teams, $venues, $coaches);
        $this->seedChampionship($manager, $clubId, $seasonId, $teams, $venues);
        $manager->flush();

        return $club;
    }

    private function seedClub(EntityManagerInterface $manager, int $index): Club
    {
        $slug = \sprintf('club-placement-%d', $index);
        $existing = $manager->getRepository(Club::class)->findOneBy(['slug' => $slug]);
        if ($existing instanceof Club) {
            return $existing;
        }

        $club = new Club;
        $club->setName(\sprintf('Club Placement %d', $index));
        $club->setSlug($slug);
        // Plage FFBB fictive distincte des clubs dev/démo/charge-génération.
        $club->setFfbbClubCode(\sprintf('ARA88800%02d', $index));
        $club->setIsDemo(false);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setWeekendAlternates(true);
        $club->setSchoolZone('A');
        $club->setLatitude(45.75);
        $club->setLongitude(4.85);
        $manager->persist($club);
        $manager->flush();

        $beta = $manager->getRepository(SubscriptionPlan::class)->findOneBy(['code' => 'beta']);
        if ($beta instanceof SubscriptionPlan) {
            $club->setPlanId($beta->getId());
            $club->setPaidSeasonYear(SeasonResolver::seasonYear(new DateTimeImmutable));
        }
        $manager->flush();

        return $club;
    }

    private function seedSport(EntityManagerInterface $manager): Sport
    {
        $existing = $manager->getRepository(Sport::class)->findOneBy(['slug' => 'basketball']);
        if ($existing instanceof Sport) {
            return $existing;
        }

        $sport = new Sport;
        $sport->setName('BasketBall');
        $sport->setSlug('basketball');
        $sport->setIcon('basketball');
        $sport->setIsActive(true);
        $manager->persist($sport);

        return $sport;
    }

    /**
     * @return array<string, string> nom de catégorie → sportCategoryId
     */
    private function seedCategories(EntityManagerInterface $manager, string $clubId, Sport $sport): array
    {
        foreach (CategoryCatalog::categories() as $cat) {
            $existing = $manager->getRepository(SportCategory::class)->findOneBy([
                'clubId' => $clubId,
                'sportId' => $sport->getId(),
                'name' => $cat['name'],
            ]);
            if (null === $existing) {
                $entity = new SportCategory;
                $entity->setName($cat['name']);
                $entity->setAgeMin($cat['ageMin']);
                $entity->setAgeMax($cat['ageMax']);
                $entity->setSortOrder($cat['sortOrder']);
                $entity->setSport($sport);
                $entity->setIsCustom(false);
                $entity->setClubId($clubId);
                $manager->persist($entity);
            }
        }
        $manager->flush();

        $ids = [];
        foreach ($manager->getRepository(SportCategory::class)->findBy(['clubId' => $clubId, 'sportId' => $sport->getId()]) as $cat) {
            $ids[$cat->getName()] = $cat->getId();
        }

        return $ids;
    }

    private function seedPriorityTiers(EntityManagerInterface $manager): void
    {
        $tiers = [
            [1, 'S', 'Elite', '#FFD700', 10000, 3],
            [2, 'A', 'Régional+', '#C0C0C0', 1000, 2],
            [3, 'B', 'Régional', '#CD7F32', 100, 2],
            [4, 'C', 'Départemental', '#3498DB', 10, 2],
            [5, 'D', 'Loisir', '#95A5A6', 1, 1],
        ];
        foreach ($tiers as [$id, $label, $name, $color, $weight, $minSessions]) {
            if ($manager->getRepository(PriorityTier::class)->find($id) instanceof PriorityTier) {
                continue;
            }
            $tier = new PriorityTier;
            $tier->setId($id);
            $tier->setLabel($label);
            $tier->setName($name);
            $tier->setColor($color);
            $tier->setOrToolsWeight($weight);
            $tier->setDefaultMinSessions($minSessions);
            $manager->persist($tier);
        }
    }

    private function seedSeason(EntityManagerInterface $manager, string $clubId): Season
    {
        $existing = $manager->getRepository(Season::class)->findOneBy(['clubId' => $clubId, 'name' => self::SEASON_NAME]);
        if ($existing instanceof Season) {
            return $existing;
        }

        $season = new Season;
        $season->setClubId($clubId);
        $season->setName(self::SEASON_NAME);
        $season->setStartDate(new DateTimeImmutable('2026-07-15'));
        $season->setEndDate(new DateTimeImmutable('2027-07-14'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $manager->persist($season);
        $manager->flush();

        return $season;
    }

    private function seedManager(EntityManagerInterface $manager, Club $club, int $index): void
    {
        $email = \sprintf('place-%d@amateo.local', $index);
        $user = $manager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $user = new User;
            $user->setEmail($email);
            $user->setFirstName('Placement');
            $user->setLastName(\sprintf('Manager %d', $index));
            $user->setEmailVerifiedAt(new DateTimeImmutable);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, self::MANAGER_PASSWORD));
            $manager->persist($user);
            $manager->flush();
        }

        $existing = $manager->getRepository(ClubUser::class)->findOneBy(['clubId' => $club->getId(), 'userId' => $user->getId()]);
        if (null === $existing) {
            $clubUser = new ClubUser;
            $clubUser->setClubId($club->getId());
            $clubUser->setUserId($user->getId());
            $clubUser->setRole('admin');
            $clubUser->setIsActive(true);
            $manager->persist($clubUser);
        }
    }

    /**
     * Purge des collections saison-scopées (idempotence d'un reseed) — ordre FK-safe :
     * matchs avant compétitions, créneaux de planning avant le planning lui-même.
     */
    private function purgeCollections(EntityManagerInterface $manager, string $clubId, string $seasonId): void
    {
        $scope = ['clubId' => $clubId, 'seasonId' => $seasonId];
        foreach ([Fixture::class, Competition::class, MatchConstraint::class, TeamMatchHabit::class, VenueMatchWindow::class, VenueTrainingSlot::class, TeamLink::class, CoachPlayerMembership::class, TeamCoach::class] as $class) {
            foreach ($manager->getRepository($class)->findBy($scope) as $row) {
                $manager->remove($row);
            }
        }
        // Les ScheduleSlotTemplate de NOTRE version transcrite (purge via le schedule marqueur).
        $plan = $manager->getRepository(Schedule::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'solverVersion' => self::SCHEDULE_MARKER]);
        foreach ($plan as $schedule) {
            foreach ($manager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $schedule->getId()]) as $slot) {
                $manager->remove($slot);
            }
        }
    }

    /**
     * @return list<Venue> index 0..n-1
     */
    private function seedVenues(EntityManagerInterface $manager, string $clubId, string $seasonId, int $index, LoadTestClubSize $size): array
    {
        $venues = [];
        for ($k = 0; $k < $size->venueCount(); ++$k) {
            $name = \sprintf('Gymnase Charge %d-%d', $index, $k + 1);
            $venue = $manager->getRepository(Venue::class)->findOneBy(['clubId' => $clubId, 'name' => $name]);
            if (!$venue instanceof Venue) {
                $venue = new Venue;
                $venue->setClubId($clubId);
                $venue->setSeasonId($seasonId);
                $venue->setName($name);
                $venue->setSource('fixture');
                $venue->setIsActive(true);
                $venue->setCanSplit(0 === $k % 2);
                $manager->persist($venue);
            }
            $venues[] = $venue;

            // Fenêtres d'accès match : samedi + dimanche sur CHAQUE gymnase (sinon zéro
            // candidat au placement). L'heure est large : le solveur borne par ClubLeagueWindow.
            foreach ([[6, '10:00', '22:00'], [7, '09:00', '20:00']] as [$day, $start, $end]) {
                $window = new VenueMatchWindow;
                $window->setClubId($clubId);
                $window->setSeasonId($seasonId);
                $window->setVenueId($venue->getId());
                $window->setDayOfWeek($day);
                $window->setStartTime(new DateTimeImmutable($start));
                $window->setEndTime(new DateTimeImmutable($end));
                $manager->persist($window);
            }
        }

        return $venues;
    }

    /**
     * @param array<string, string> $categoryIdByName
     *
     * @return list<array{entity: Team, isYouth: bool, hasHabit: bool, idealDay: int, idealKickoff: string, idealVenueIndex: int}>
     */
    private function seedTeams(EntityManagerInterface $manager, string $clubId, string $seasonId, LoadTestClubSize $size, array $categoryIdByName): array
    {
        $kickoffs = ['13:00', '15:00', '17:00', '19:00'];
        $templateCount = \count(self::TEAM_TEMPLATES);
        $nameCounters = [];
        $tierOrders = [];
        $teams = [];

        for ($i = 0; $i < $size->teamCount(); ++$i) {
            [$catName, $isYouth, $level, $tier, $sessions, $code] = self::TEAM_TEMPLATES[$i % $templateCount];
            $gender = 0 === intdiv($i, $templateCount) % 2 ? Gender::M : Gender::F;
            $genderLetter = Gender::M === $gender ? 'M' : 'F';
            $nameKey = $code . $genderLetter;
            $nameCounters[$nameKey] = ($nameCounters[$nameKey] ?? 0) + 1;
            $name = $nameKey . $nameCounters[$nameKey];
            $tierOrders[$tier] = ($tierOrders[$tier] ?? -1) + 1;

            $team = $manager->getRepository(Team::class)->findOneBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'name' => $name]);
            if (!$team instanceof Team) {
                $team = new Team;
                $team->setClubId($clubId);
                $team->setSeasonId($seasonId);
                $team->setName($name);
                $team->setSportCategoryId($categoryIdByName[$catName]);
                $team->setPriorityTierId($tier);
                $team->setLevel($level);
                $team->setGender($gender);
                $team->setSessionsPerWeek($sessions);
                $team->setTierOrder($tierOrders[$tier]);
                $team->setIsActive(true);
                $manager->persist($team);
            }

            // ~2/3 des équipes reçoivent (habitude de match) ; le reste ne joue qu'à l'extérieur.
            $hasHabit = 0 !== $i % 3;
            $teams[] = [
                'entity' => $team,
                'isYouth' => $isYouth,
                'hasHabit' => $hasHabit,
                'idealDay' => 0 === $i % 2 ? 6 : 7,
                'idealKickoff' => $kickoffs[$i % 4],
                'idealVenueIndex' => $i % $size->venueCount(),
            ];
        }

        return $teams;
    }

    /**
     * Coachs FICTIFS mutualisés + quelques joueurs (coach jouant dans une autre équipe).
     *
     * @param list<array{entity: Team, isYouth: bool, hasHabit: bool, idealDay: int, idealKickoff: string, idealVenueIndex: int}> $teams
     *
     * @return list<Coach>
     */
    private function seedCoaches(EntityManagerInterface $manager, string $clubId, string $seasonId, LoadTestClubSize $size, array $teams): array
    {
        $teamCount = $size->teamCount();
        $coachCount = max(2, (int) ceil($teamCount * 0.7));
        $coaches = [];
        for ($k = 0; $k < $coachCount; ++$k) {
            $firstName = self::COACH_FIRST_NAMES[$k % \count(self::COACH_FIRST_NAMES)];
            $lastName = self::COACH_LAST_NAMES[$k % \count(self::COACH_LAST_NAMES)];
            $coach = $manager->getRepository(Coach::class)->findOneBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'firstName' => $firstName, 'lastName' => $lastName]);
            if (!$coach instanceof Coach) {
                $coach = new Coach;
                $coach->setClubId($clubId);
                $coach->setSeasonId($seasonId);
                $coach->setFirstName($firstName);
                $coach->setLastName($lastName);
                $coach->setIsActive(true);
                $manager->persist($coach);
            }
            $coaches[] = $coach;
        }
        $manager->flush();

        foreach ($teams as $i => $team) {
            $teamId = $team['entity']->getId();
            $main = $coaches[$i % $coachCount];
            $this->linkCoach($manager, $clubId, $seasonId, $teamId, $main->getId(), TeamCoachRole::MAIN);
            // Mutualisation : un adjoint partagé tous les 4 clubs.
            if (0 === $i % 4 && $i + 1 < \count($teams)) {
                $assistant = $coaches[($i + 1) % $coachCount];
                $this->linkCoach($manager, $clubId, $seasonId, $teamId, $assistant->getId(), TeamCoachRole::ASSISTANT);
            }
            // Un coach jouant comme JOUEUR dans une autre équipe (protégé comme un MAIN).
            if (0 === $i % 5) {
                $player = $coaches[($i + 2) % $coachCount];
                $membership = new CoachPlayerMembership;
                $membership->setClubId($clubId);
                $membership->setSeasonId($seasonId);
                $membership->setCoachId($player->getId());
                $membership->setTeamId($teamId);
                $membership->setIsActive(true);
                $manager->persist($membership);
            }
        }
        $counter = \count($teams);

        // Quelques passerelles « pas en même temps » entre équipes consécutives.
        for ($i = 0; $i + 1 < $counter; $i += 2) {
            $a = $teams[$i]['entity']->getId();
            $b = $teams[$i + 1]['entity']->getId();
            [$teamAId, $teamBId] = $a < $b ? [$a, $b] : [$b, $a];
            $link = new TeamLink;
            $link->setClubId($clubId);
            $link->setSeasonId($seasonId);
            $link->setTeamAId($teamAId);
            $link->setTeamBId($teamBId);
            $link->setLinkType(TeamLinkType::NOT_SIMULTANEOUS);
            $manager->persist($link);
        }

        return $coaches;
    }

    private function linkCoach(EntityManagerInterface $manager, string $clubId, string $seasonId, string $teamId, string $coachId, TeamCoachRole $role): void
    {
        $teamCoach = new TeamCoach;
        $teamCoach->setClubId($clubId);
        $teamCoach->setSeasonId($seasonId);
        $teamCoach->setTeamId($teamId);
        $teamCoach->setCoachId($coachId);
        $teamCoach->setRole($role);
        $teamCoach->setIsRequired(TeamCoachRole::MAIN === $role);
        $manager->persist($teamCoach);
    }

    /**
     * Grille d'entraînement (VenueTrainingSlot) + habitudes de match (TeamMatchHabit),
     * et renvoie la liste des créneaux d'entraînement à transcrire dans le planning validé.
     *
     * @param list<array{entity: Team, isYouth: bool, hasHabit: bool, idealDay: int, idealKickoff: string, idealVenueIndex: int}> $teams
     * @param list<Venue>                                                                                                         $venues
     *
     * @return list<array{teamId: string, venueId: string, day: int, start: string, duration: int}>
     */
    private function seedTrainingGridAndHabits(EntityManagerInterface $manager, string $clubId, string $seasonId, array $teams, array $venues): array
    {
        $starts = ['17:30', '19:00', '20:30'];
        $slots = [];
        foreach ($teams as $i => $team) {
            $venue = $venues[$i % \count($venues)];
            $day = ($i % 5) + 1; // lundi..vendredi
            $start = $starts[$i % 3];

            $gridSlot = new VenueTrainingSlot;
            $gridSlot->setClubId($clubId);
            $gridSlot->setSeasonId($seasonId);
            $gridSlot->setVenueId($venue->getId());
            $gridSlot->setDayOfWeek($day);
            $gridSlot->setStartTime(new DateTimeImmutable($start));
            $gridSlot->setDurationMinutes(90);
            $gridSlot->setCapacity(1);
            $manager->persist($gridSlot);

            $slots[] = ['teamId' => $team['entity']->getId(), 'venueId' => $venue->getId(), 'day' => $day, 'start' => $start, 'duration' => 90];

            if ($team['hasHabit']) {
                $habitVenue = $venues[$team['idealVenueIndex']];
                $habit = new TeamMatchHabit;
                $habit->setClubId($clubId);
                $habit->setSeasonId($seasonId);
                $habit->setTeamId($team['entity']->getId());
                $habit->setDayOfWeek($team['idealDay']);
                $habit->setKickoffTime(new DateTimeImmutable($team['idealKickoff']));
                $habit->setVenueId($habitVenue->getId());
                $habit->setWeek(0 === $i % 2 ? MatchWeek::A : MatchWeek::B);
                $manager->persist($habit);
            }
        }

        return $slots;
    }

    /**
     * Planning d'entraînement FICTIF, VALIDÉ (plan SEASON pointé) — même rail que le
     * seed réel : Schedule COMPLETED → linkSchedule → choose → ScheduleSlotTemplate.
     * Sans ce plan choisi, le placement serait refusé 409 (SocleGuard) et les
     * occupations d'entraînement seraient invisibles au solveur.
     *
     * @param list<array{teamId: string, venueId: string, day: int, start: string, duration: int}> $trainingSlots
     */
    private function seedValidatedSeasonPlan(EntityManagerInterface $manager, Club $club, Season $season, array $trainingSlots): void
    {
        $clubId = $club->getId();
        $seasonId = $season->getId();
        $seasonPlanId = $this->schedulePlanProvisioner->ensureSeasonPlan($season)->getId();

        $schedule = $manager->getRepository(Schedule::class)->findOneBy(['schedulePlanId' => $seasonPlanId, 'solverVersion' => self::SCHEDULE_MARKER]);
        if (!$schedule instanceof Schedule) {
            $schedule = new Schedule;
            $schedule->setClubId($clubId);
            $schedule->setSeasonId($seasonId);
            $schedule->setSchedulePlanId($seasonPlanId);
            $schedule->setName($this->schedulePlanProvisioner->versionNameFor($seasonPlanId));
            $schedule->setStatus(ScheduleStatus::COMPLETED);
            $schedule->setSolverVersion(self::SCHEDULE_MARKER);
            $manager->persist($schedule);
            $this->schedulePlanProvisioner->linkSchedule($schedule);
        }

        $snapshot = ['loadTest' => true, 'slots' => \count($trainingSlots)];
        $schedule->setSnapshotData($snapshot);
        $schedule->setSnapshotHash(hash('sha256', json_encode($snapshot, \JSON_THROW_ON_ERROR)));
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $manager->flush();

        $this->schedulePlanProvisioner->choose($schedule);

        foreach ($trainingSlots as $slot) {
            $template = new ScheduleSlotTemplate;
            $template->setClubId($clubId);
            $template->setSeasonId($seasonId);
            $template->setScheduleId($schedule->getId());
            $template->setTeamId($slot['teamId']);
            $template->setVenueId($slot['venueId']);
            $template->setCoachId(null);
            $template->setDayOfWeek($slot['day']);
            $template->setStartTime(new DateTimeImmutable($slot['start']));
            $template->setDurationMinutes($slot['duration']);
            $template->setLockLevel(LockLevel::NONE);
            $template->setLockOrigin(null);
            $manager->persist($template);
        }
        $schedule->setManuallyEditedSinceGeneration(false);
        $schedule->setConstraintsChangedSinceGeneration(false);
        $schedule->setResourcesChangedSinceGeneration(false);
        $manager->flush();
    }

    /**
     * Quelques vraies contraintes de match que le solveur doit honorer / pénaliser.
     *
     * @param list<array{entity: Team, isYouth: bool, hasHabit: bool, idealDay: int, idealKickoff: string, idealVenueIndex: int}> $teams
     * @param list<Venue>                                                                                                         $venues
     * @param list<Coach>                                                                                                         $coaches
     */
    private function seedMatchConstraints(EntityManagerInterface $manager, string $clubId, string $seasonId, array $teams, array $venues, array $coaches): void
    {
        // CLUB PREFERRED : le samedi, pas avant 14h00 (pénalité).
        $manager->persist($this->clubRule($clubId, $seasonId, ConstraintRuleType::PREFERRED, [6], '14:00', null));
        // CLUB HARD : le dimanche, pas après 19h00 (honorée).
        $manager->persist($this->clubRule($clubId, $seasonId, ConstraintRuleType::HARD, [7], null, '19:00'));

        // TEAM : la première équipe s'interdit le DERNIER gymnase (HARD, tous jours/toute heure).
        if (\count($venues) > 1 && [] !== $teams) {
            $ban = new MatchConstraint;
            $ban->setClubId($clubId);
            $ban->setSeasonId($seasonId);
            $ban->setScope(ConstraintScope::TEAM);
            $ban->setScopeTargetId($teams[0]['entity']->getId());
            $ban->setVenueId($venues[\count($venues) - 1]->getId());
            $ban->setRuleType(ConstraintRuleType::HARD);
            $ban->setDaysOfWeek([]);
            $manager->persist($ban);
        }

        // COACH : le premier coach indisponible le samedi soir (PREFERRED, toujours SOFT).
        if ([] !== $coaches) {
            $unavail = new MatchConstraint;
            $unavail->setClubId($clubId);
            $unavail->setSeasonId($seasonId);
            $unavail->setScope(ConstraintScope::COACH);
            $unavail->setScopeTargetId($coaches[0]->getId());
            $unavail->setRuleType(ConstraintRuleType::PREFERRED);
            $unavail->setDaysOfWeek([6]);
            $unavail->setKickoffMin(new DateTimeImmutable('18:00'));
            $unavail->setKickoffMax(new DateTimeImmutable('22:00'));
            $manager->persist($unavail);
        }
    }

    /**
     * @param list<int> $daysOfWeek
     */
    private function clubRule(string $clubId, string $seasonId, ConstraintRuleType $ruleType, array $daysOfWeek, ?string $min, ?string $max): MatchConstraint
    {
        $rule = new MatchConstraint;
        $rule->setClubId($clubId);
        $rule->setSeasonId($seasonId);
        $rule->setScope(ConstraintScope::CLUB);
        $rule->setRuleType($ruleType);
        $rule->setDaysOfWeek($daysOfWeek);
        $rule->setKickoffMin(null === $min ? null : new DateTimeImmutable($min));
        $rule->setKickoffMax(null === $max ? null : new DateTimeImmutable($max));

        return $rule;
    }

    /**
     * Championnat : une compétition CHAMPIONSHIP par (équipe, phase) + ses matchs
     * (via {@see LoadTestMatchPlan}). Les dates de compétition bornent la phase pour
     * que le harnais calcule la fenêtre `{from,to}` du 1er passage.
     *
     * @param list<array{entity: Team, isYouth: bool, hasHabit: bool, idealDay: int, idealKickoff: string, idealVenueIndex: int}> $teams
     * @param list<Venue>                                                                                                         $venues
     */
    private function seedChampionship(EntityManagerInterface $manager, string $clubId, string $seasonId, array $teams, array $venues): void
    {
        $weekends = $this->championshipWeekends();
        $planInput = [];
        $teamById = [];
        foreach ($teams as $team) {
            $teamId = $team['entity']->getId();
            $teamById[$teamId] = $team;
            $planInput[] = [
                'teamId' => $teamId,
                'isYouth' => $team['isYouth'],
                'hasHabit' => $team['hasHabit'],
                'idealDay' => $team['idealDay'],
                'idealKickoff' => $team['idealKickoff'],
                'idealVenueId' => $team['hasHabit'] ? $venues[$team['idealVenueIndex']]->getId() : null,
            ];
        }

        $matches = LoadTestMatchPlan::build($planInput, $weekends, self::OPPONENTS);

        // 1er passage : grouper par (équipe, phase) pour une compétition + ses bornes.
        /** @var array<string, array{competition: Competition, min: string, max: string}> $competitions */
        $competitions = [];
        foreach ($matches as $match) {
            $key = $match['teamId'] . '|' . $match['phase'];
            if (!isset($competitions[$key])) {
                $competition = new Competition;
                $competition->setClubId($clubId);
                $competition->setSeasonId($seasonId);
                $competition->setTeamId($match['teamId']);
                $competition->setName(\sprintf('Championnat Phase %d', $match['phase']));
                $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
                $manager->persist($competition);
                $competitions[$key] = ['competition' => $competition, 'min' => $match['date'], 'max' => $match['date']];
            }
            if ($match['date'] < $competitions[$key]['min']) {
                $competitions[$key]['min'] = $match['date'];
            }
            if ($match['date'] > $competitions[$key]['max']) {
                $competitions[$key]['max'] = $match['date'];
            }
        }
        foreach ($competitions as $entry) {
            $entry['competition']->setStartDate(new DateTimeImmutable($entry['min']));
            $entry['competition']->setEndDate(new DateTimeImmutable($entry['max']));
        }

        $now = new DateTimeImmutable;
        foreach ($matches as $match) {
            $competition = $competitions[$match['teamId'] . '|' . $match['phase']]['competition'];
            $fixture = new Fixture;
            $fixture->setClubId($clubId);
            $fixture->setSeasonId($seasonId);
            $fixture->setTeamId($match['teamId']);
            $fixture->setCompetitionId($competition->getId());
            $fixture->setMatchDate(new DateTimeImmutable($match['date']));
            $fixture->setHomeAway(FixtureHomeAway::from($match['homeAway']));
            $fixture->setOpponentLabel($match['opponentLabel']);
            // Déjà fixé (ligue / à la main) : ancre que le solveur contourne. MANUAL (pas SOLVER),
            // sinon le builder le re-proposerait au placement.
            if ($match['fixed'] && null !== $match['venueId'] && null !== $match['kickoff']) {
                $fixture->setVenueId($match['venueId']);
                $fixture->setKickoffTime(new DateTimeImmutable($match['kickoff']));
                $fixture->setStatus(FixtureStatus::PLACED, $now);
                $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
            }
            $manager->persist($fixture);
        }
    }

    /**
     * Les week-ends de championnat (paires samedi/dimanche), déterministes.
     *
     * @return list<array{saturday: string, sunday: string}>
     */
    private function championshipWeekends(): array
    {
        $saturday = new DateTimeImmutable(self::FIRST_SATURDAY);
        $weekends = [];
        for ($w = 0; $w < self::CHAMPIONSHIP_WEEKENDS; ++$w) {
            $sat = $saturday->modify(\sprintf('+%d days', 7 * $w));
            $weekends[] = ['saturday' => $sat->format('Y-m-d'), 'sunday' => $sat->modify('+1 day')->format('Y-m-d')];
        }

        return $weekends;
    }
}
