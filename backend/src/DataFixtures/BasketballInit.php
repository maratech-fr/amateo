<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\Gender;
use App\Enum\LockLevel;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLevel;
use App\Service\LeagueResolver;
use App\Service\SchedulePlanProvisioner;
use App\Service\SchoolZoneResolver;
use App\Sport\BasketballCategoryCatalog;
use App\Storage\LogoStorage;
use App\Storage\LogoUrl;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use finfo;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BasketballInit implements FixtureInterface, ORMFixtureInterface
{
    /** FFBB code of the seeded club → academic zone 'A', league 'AURA'. */
    private const string BCCL_FFBB_CODE = 'ARA0069036';

    /** Optional default logo for the seeded club (drop a PNG here to ship one). */
    private const string BCCL_LOGO_PATH = __DIR__ . '/assets/bccl-logo.png';
    private const int LOGO_MAX_BYTES = 512_000; // 500 KB — same as ClubLogoController
    private const array LOGO_ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SchoolZoneResolver $schoolZoneResolver,
        private readonly LeagueResolver $leagueResolver,
        private readonly LogoStorage $logoStorage,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('Expected EntityManagerInterface');
        }

        // RLS guard: as app_user the purge phase silently DELETEs zero rows on
        // tenant tables (fail-closed policies) and the reload then collides
        // with the surviving data — a half-purged database. Fail fast instead.
        $superuser = (bool) $manager->getConnection()->fetchOne(
            'SELECT usesuper FROM pg_user WHERE usename = current_user',
        );
        if (!$superuser) {
            throw new RuntimeException('Fixtures must run on the admin connection (RLS silently breaks the purge as app_user). Use `make fixtures`, which injects DATABASE_URL=<DATABASE_ADMIN_URL>.');
        }

        // --- Club ---
        $existingClub = $manager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => self::BCCL_FFBB_CODE]);
        if ($existingClub instanceof Club) {
            $club = $existingClub;
        } else {
            $club = new Club;
            $club->setName('B CHARPENNES CROIX LUIZET');
            $club->setSlug('b-charpennes-croix-luizet');
            $club->setFfbbClubCode(self::BCCL_FFBB_CODE);
            $club->setTimezone('Europe/Paris');
            $club->setLocale('fr');
            // Established demo club: onboarding done → free wizard navigation
            // (FakeClub stays not-onboarded to exercise the guided flow).
            $club->setOnboardingCompleted(true);
            $manager->persist($club);
        }
        // Derive the academic zone + league from the FFBB code, exactly like the
        // registration path (AuthController::createClub) — otherwise the seeded
        // "established" club is LESS configured than a freshly registered one
        // (no vacances zone shown, no league envelope). Only fill when empty so a
        // re-run never reverts a manual PATCH correction (resolver = best-effort).
        if (null === $club->getSchoolZone()) {
            $club->setSchoolZone($this->schoolZoneResolver->resolveFromFfbbCode(self::BCCL_FFBB_CODE));
        }
        if (null === $club->getLeague()) {
            $club->setLeague($this->leagueResolver->resolveFromFfbbCode(self::BCCL_FFBB_CODE));
        }
        // Club accent = the logo red, in both themes (accentForMode lifts it for
        // legibility on dark surfaces). Only-fill-when-empty so a manual PATCH
        // survives a re-run, like schoolZone/league above.
        if (null === $club->getAccentColor()) {
            $club->setAccentColor('#E53935');
        }
        if (null === $club->getAccentColorDark()) {
            $club->setAccentColorDark('#E53935');
        }
        $manager->flush();

        $clubId = $club->getId();
        $manager->getConnection()->executeStatement('SELECT set_config(\'app.club_id\', ?, false)', [$clubId]);

        // Default club logo (optional asset): store the bytes + point logoUrl at
        // the public serve route, mirroring ClubLogoController — including its
        // size + MIME guards, so the fixture can't ship what an upload would
        // refuse. Skipped silently if absent/invalid; the fixture never fails.
        $this->seedDefaultLogo($club, $clubId, $manager);

        // --- Sport ---
        $existingSport = $manager->getRepository(Sport::class)->findOneBy(['slug' => 'basketball']);
        if ($existingSport instanceof Sport) {
            $sport = $existingSport;
        } else {
            $sport = new Sport;
            $sport->setName('BasketBall');
            $sport->setSlug('basketball');
            $sport->setIcon('basketball');
            $sport->setIsActive(true);
            $manager->persist($sport);
        }

        // --- Categories (ungendered age brackets — see BasketballCategoryCatalog) ---
        $categories = BasketballCategoryCatalog::categories();

        foreach ($categories as $cat) {
            $existing = $manager->getRepository(SportCategory::class)->findOneBy([
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

        // --- Fetch ALL sport categories in one place (every one is created by the
        // loop above; regrouped here so teams/constraints share a single source). ---
        $fetchCat = static function (string $name) use ($manager, $sport): SportCategory {
            $cat = $manager->getRepository(SportCategory::class)->findOneBy(['sportId' => $sport->getId(), 'name' => $name]);
            \assert($cat instanceof SportCategory);

            return $cat;
        };
        $u5 = $fetchCat('U5');
        $u7 = $fetchCat('U7');
        $u9 = $fetchCat('U9');
        $u11 = $fetchCat('U11');
        $u13 = $fetchCat('U13');
        $u15 = $fetchCat('U15');
        $u18 = $fetchCat('U18');
        $u21 = $fetchCat('U21');
        $senior = $fetchCat('Senior');
        $veteran = $fetchCat('Vétéran');
        $loisir = $fetchCat('Loisir');

        // ============================================================
        // SECTION 1 — PRIORITY TIERS
        // ============================================================
        $tiersData = [
            ['id' => 1, 'label' => 'S', 'name' => 'Elite', 'color' => '#FFD700', 'orToolsWeight' => 10000, 'defaultMinSessions' => 3],
            ['id' => 2, 'label' => 'A', 'name' => 'Régional+', 'color' => '#C0C0C0', 'orToolsWeight' => 1000, 'defaultMinSessions' => 2],
            ['id' => 3, 'label' => 'B', 'name' => 'Régional', 'color' => '#CD7F32', 'orToolsWeight' => 100, 'defaultMinSessions' => 2],
            ['id' => 4, 'label' => 'C', 'name' => 'Départemental', 'color' => '#3498DB', 'orToolsWeight' => 10, 'defaultMinSessions' => 2],
            ['id' => 5, 'label' => 'D', 'name' => 'Loisir', 'color' => '#95A5A6', 'orToolsWeight' => 1, 'defaultMinSessions' => 1],
        ];

        foreach ($tiersData as $tierData) {
            $existing = $manager->getRepository(PriorityTier::class)->find($tierData['id']);
            if (!$existing instanceof PriorityTier) {
                $tier = new PriorityTier;
                $tier->setId($tierData['id']);
                $tier->setLabel($tierData['label']);
                $tier->setName($tierData['name']);
                $tier->setColor($tierData['color']);
                $tier->setOrToolsWeight($tierData['orToolsWeight']);
                $tier->setDefaultMinSessions($tierData['defaultMinSessions']);
                $manager->persist($tier);
            }
        }
        $manager->flush();

        // --- Season ---
        $existingSeason = $manager->getRepository(Season::class)->findOneBy([
            'clubId' => $club->getId(),
            'name' => '2026-2027',
        ]);
        if ($existingSeason instanceof Season) {
            $season = $existingSeason;
        } else {
            $season = new Season;
            $season->setClubId($club->getId());
            $season->setName('2026-2027');
            $season->setStartDate(new DateTimeImmutable('2026-07-15'));
            $season->setEndDate(new DateTimeImmutable('2027-07-14'));
            $season->setStatus('active');
            $manager->persist($season);
        }
        // ADR-0002 Lot A: seed the season's empty SEASON plan (idempotent).
        $this->schedulePlanProvisioner->ensureSeasonPlan($season);

        // BCCL is seeded as an INCOMPLETE onboarding (cockpit state 1): all the
        // data is entered but NO plan has been generated yet, so the club lands on
        // the wizard (Récap) before its first generation — the realistic demo of a
        // freshly-onboarding club. No baseline / no validated socle is stamped.

        // --- User ---
        $existingUser = $manager->getRepository(User::class)->findOneBy(['email' => 'mara.mb@bccl.fr']);
        if ($existingUser instanceof User) {
            $user = $existingUser;
        } else {
            $user = new User;
            $user->setEmail('mara.mb@bccl.fr');
            $user->setFirstName('Mara');
            $user->setLastName('Mb');
            // Seeded accounts are pre-verified so dev/e2e/demo login works (the login
            // gate rejects emailVerifiedAt = null).
            $user->setEmailVerifiedAt(new DateTimeImmutable);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'maraboubccl'));
            $manager->persist($user);
        }

        // --- ClubUser ---
        $existingClubUser = $manager->getRepository(ClubUser::class)->findOneBy([
            'clubId' => $club->getId(),
            'userId' => $user->getId(),
        ]);
        if (null === $existingClubUser) {
            $clubUser = new ClubUser;
            $clubUser->setClubId($club->getId());
            $clubUser->setUserId($user->getId());
            $clubUser->setRole('admin');
            $clubUser->setIsActive(true);
            $manager->persist($clubUser);
        }
        $manager->flush();

        // ============================================================
        // SECTION 2 — VENUES
        // ============================================================
        // [name, var, color (hex), canSplit] — Matéo + JDR are divisible gyms.
        $venuesData = [
            ['name' => 'Armand', 'var' => 'vArmand', 'color' => '#1E88E5', 'canSplit' => false],
            ['name' => 'ADN', 'var' => 'vAdn', 'color' => '#FDD835', 'canSplit' => false],
            ['name' => 'Debarros', 'var' => 'vDebarros', 'color' => '#2E7D32', 'canSplit' => false],
            ['name' => 'Annexe', 'var' => 'vDebarrosAnnexe', 'color' => '#66BB6A', 'canSplit' => false],
            ['name' => 'Jean Vilar', 'var' => 'vJeanVilar', 'color' => '#1A237E', 'canSplit' => false],
            ['name' => 'Tonkin', 'var' => 'vTonkin', 'color' => '#FB8C00', 'canSplit' => false],
            ['name' => 'JDR', 'var' => 'vJdr', 'color' => '#F8BBD0', 'canSplit' => true],
            ['name' => 'Matéo', 'var' => 'vMateo', 'color' => '#E53935', 'canSplit' => true],
            ['name' => 'Camus', 'var' => 'vCamus', 'color' => '#8E24AA', 'canSplit' => false],
        ];

        $venues = [];
        foreach ($venuesData as $vd) {
            $existing = $manager->getRepository(Venue::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $vd['name'],
            ]);
            if ($existing instanceof Venue) {
                $venue = $existing;
            } else {
                $venue = new Venue;
                $venue->setClubId($club->getId());
                $venue->setSeasonId($season->getId());
                $venue->setName($vd['name']);
                $venue->setSource('fixture');
                $venue->setIsActive(true);
                $manager->persist($venue);
            }
            // Identity colour + divisibility (seeded, re-applied on re-run).
            $venue->setColor($vd['color']);
            $venue->setCanSplit($vd['canSplit']);
            $venues[$vd['var']] = $venue;
        }
        $manager->flush();

        // ============================================================
        // SECTION — VENUE TRAINING SLOTS
        // ============================================================
        // Purge all existing VenueTrainingSlot for this club/season
        $existingVenueSlots = $manager->getRepository(VenueTrainingSlot::class)->findBy([
            'clubId' => $club->getId(),
            'seasonId' => $season->getId(),
        ]);
        foreach ($existingVenueSlots as $existingVenueSlot) {
            $manager->remove($existingVenueSlot);
        }
        $manager->flush();

        // [venue_var, day, startTime, durationMinutes, capacity]
        // capacity=2 on slots shared by youth pairs (U13M1/M2, U9F1/F2, U9M1/M2)
        /** @var list<array{string, int, string, int, int}> $trainingSlots */
        $trainingSlots = [
            // Matéo — Mon
            ['vMateo', 1, '17:30', 90, 2],
            ['vMateo', 1, '19:00', 90, 1],
            ['vMateo', 1, '20:30', 120, 1],
            // Matéo — Tue
            ['vMateo', 2, '17:30', 90, 1],
            ['vMateo', 2, '19:00', 90, 1],
            ['vMateo', 2, '20:30', 120, 1],
            // Matéo — Wed
            ['vMateo', 3, '16:00', 90, 1],
            ['vMateo', 3, '17:30', 90, 1],
            ['vMateo', 3, '19:00', 90, 1],
            ['vMateo', 3, '20:30', 120, 1],
            // Matéo — Thu
            ['vMateo', 4, '17:30', 90, 1],
            ['vMateo', 4, '19:00', 90, 1],
            ['vMateo', 4, '20:30', 120, 1],
            // Matéo — Fri
            ['vMateo', 5, '17:30', 90, 1],
            ['vMateo', 5, '19:00', 90, 1],
            ['vMateo', 5, '20:30', 120, 1],
            // Matéo — Sat (Baby/Micro)
            ['vMateo', 6, '09:00', 45, 1],
            ['vMateo', 6, '09:45', 60, 1],
            ['vMateo', 6, '10:45', 60, 1],
            // Camus — Tue/Thu/Fri (Loisir)
            ['vCamus', 2, '20:00', 150, 1],
            ['vCamus', 4, '20:00', 150, 1],
            ['vCamus', 5, '20:00', 150, 1],
            // JDR — Tue
            ['vJdr', 2, '17:30', 90, 2],
            ['vJdr', 2, '19:00', 90, 1],
            ['vJdr', 2, '20:30', 120, 1],
            // JDR — Thu
            ['vJdr', 4, '17:30', 90, 2],
            ['vJdr', 4, '19:00', 90, 1],
            ['vJdr', 4, '20:30', 120, 1],
            // JDR — Sat (Académie)
            ['vJdr', 6, '09:00', 75, 1],
            ['vJdr', 6, '10:15', 75, 1],
            ['vJdr', 6, '11:30', 75, 1],
            // Armand — Mon
            ['vArmand', 1, '17:30', 90, 1],
            ['vArmand', 1, '19:00', 90, 1],
            ['vArmand', 1, '20:30', 120, 1],
            // Armand — Tue
            ['vArmand', 2, '17:30', 90, 1],
            ['vArmand', 2, '19:00', 90, 1],
            // Armand — Wed
            ['vArmand', 3, '16:00', 75, 1],
            ['vArmand', 3, '17:15', 90, 1],
            ['vArmand', 3, '18:45', 90, 1],
            ['vArmand', 3, '20:15', 135, 1],
            // Armand — Thu
            ['vArmand', 4, '17:30', 90, 1],
            // Armand — Fri
            ['vArmand', 5, '17:30', 90, 1],
            ['vArmand', 5, '19:00', 90, 1],
            ['vArmand', 5, '20:30', 120, 1],
            // Jean Vilar — Tue/Thu
            ['vJeanVilar', 2, '18:45', 90, 1],
            ['vJeanVilar', 2, '20:15', 135, 1],
            ['vJeanVilar', 4, '18:45', 90, 1],
            ['vJeanVilar', 4, '20:15', 135, 1],
            // Tonkin — Mon
            ['vTonkin', 1, '19:00', 90, 1],
            // Tonkin — Wed
            ['vTonkin', 3, '16:00', 90, 1],
            ['vTonkin', 3, '17:30', 90, 1],
            ['vTonkin', 3, '19:00', 90, 1],
            ['vTonkin', 3, '20:30', 120, 1],
            // Debarros — Mon
            ['vDebarros', 1, '17:30', 90, 1],
            ['vDebarros', 1, '19:00', 90, 1],
            // Debarros — Tue
            ['vDebarros', 2, '17:30', 90, 1],
            ['vDebarros', 2, '19:00', 90, 1],
            ['vDebarros', 2, '20:30', 120, 1],
            // Debarros — Thu
            ['vDebarros', 4, '17:30', 90, 1],
            ['vDebarros', 4, '19:00', 90, 1],
            ['vDebarros', 4, '20:30', 120, 1],
            // Debarros — Fri
            ['vDebarros', 5, '17:30', 90, 1],
            ['vDebarros', 5, '19:00', 90, 1],
            ['vDebarros', 5, '20:30', 120, 1],
            // Annexe (vDebarrosAnnexe) — Mon
            ['vDebarrosAnnexe', 1, '20:30', 120, 1],
            // Annexe — Tue
            ['vDebarrosAnnexe', 2, '17:30', 90, 1],
            ['vDebarrosAnnexe', 2, '19:00', 90, 1],
            // Annexe — Wed
            ['vDebarrosAnnexe', 3, '17:30', 90, 1],
            ['vDebarrosAnnexe', 3, '19:00', 90, 1],
            ['vDebarrosAnnexe', 3, '20:30', 120, 1],
            // Annexe — Fri
            ['vDebarrosAnnexe', 5, '19:00', 90, 1],
            // ADN — Wed
            ['vAdn', 3, '17:30', 90, 1],
            ['vAdn', 3, '19:00', 90, 1],
            ['vAdn', 3, '20:30', 120, 1],
        ];

        foreach ($trainingSlots as [$venueVar, $day, $startTime, $duration, $capacity]) {
            $slot = new VenueTrainingSlot;
            $slot->setClubId($club->getId());
            $slot->setSeasonId($season->getId());
            $slot->setVenueId($venues[$venueVar]->getId());
            $slot->setDayOfWeek($day);
            $slot->setStartTime(new DateTimeImmutable($startTime));
            $slot->setDurationMinutes($duration);
            $slot->setCapacity($capacity);
            $manager->persist($slot);
        }
        $manager->flush();

        // ============================================================
        // SECTION 4 — NEW TEAMS
        // (sport categories are all fetched up-front, see $fetchCat above)
        // ============================================================
        $newTeamsData = [
            ['name' => 'SM1', 'sportCategory' => $senior, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 1, 'gender' => Gender::M],
            ['name' => 'SM2', 'sportCategory' => $senior, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 2, 'gender' => Gender::M],
            ['name' => 'SF1', 'sportCategory' => $senior, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 1, 'gender' => Gender::F],
            ['name' => 'SF2', 'sportCategory' => $senior, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 2, 'gender' => Gender::F],
            ['name' => 'SM3', 'sportCategory' => $senior, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'SM4', 'sportCategory' => $senior, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::M],
            ['name' => 'Veterans', 'sportCategory' => $veteran, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::M],
            ['name' => 'U21M1', 'sportCategory' => $u21, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U21M2', 'sportCategory' => $u21, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'SF3', 'sportCategory' => $senior, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U18M1', 'sportCategory' => $u18, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U18M2', 'sportCategory' => $u18, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'U18F1', 'sportCategory' => $u18, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U18F2', 'sportCategory' => $u18, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U18F3', 'sportCategory' => $u18, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 5, 'gender' => Gender::F],
            ['name' => 'U15M1', 'sportCategory' => $u15, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U15M2', 'sportCategory' => $u15, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'U15F1', 'sportCategory' => $u15, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U15F2', 'sportCategory' => $u15, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U15F3', 'sportCategory' => $u15, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 5, 'gender' => Gender::F],
            ['name' => 'U13F1', 'sportCategory' => $u13, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U13F2', 'sportCategory' => $u13, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U13F3', 'sportCategory' => $u13, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 5, 'gender' => Gender::F],
            ['name' => 'U13M1', 'sportCategory' => $u13, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U13M2', 'sportCategory' => $u13, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'U11M1', 'sportCategory' => $u11, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U11M2', 'sportCategory' => $u11, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U11F1', 'sportCategory' => $u11, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U11F2', 'sportCategory' => $u11, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U9F1', 'sportCategory' => $u9, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U9F2', 'sportCategory' => $u9, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U9M1', 'sportCategory' => $u9, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U9M2', 'sportCategory' => $u9, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 4, 'gender' => Gender::M],
            // --- Loisir / Baby / Academie teams ---
            ['name' => 'Baby 1', 'sportCategory' => $u7, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            ['name' => 'Baby 2', 'sportCategory' => $u7, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            ['name' => 'Micro Basket', 'sportCategory' => $u5, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            ['name' => 'Academie U9-U11', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            ['name' => 'Academie U13-U15', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Academie U18', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Mercredi Shark U9-U11', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Loisir 1', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Loisir 2', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Loisir 3', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'Loisir Feminine', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::F],
            ['name' => '3x3', 'sportCategory' => $loisir, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            ['name' => 'Training Individuel', 'sportCategory' => $senior, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::MIXTE],
            // --- CEC Groups (joint training sessions — youth teams without individual EMB teams) ---
            // CEC Groupe 1 = joint training for U9F1 + U9F2 + U9M2 players (no individual teams exist)
            ['name' => 'CEC Groupe 1 (U9F1/U9F2/U9M2)', 'sportCategory' => $u9, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            // CEC Groupe 2 = joint training for U11F2 + U9M1 players
            ['name' => 'CEC Groupe 2 (U11F2/U9M1)', 'sportCategory' => $u11, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            // CEC Groupe 3 = joint training for U11F1 + U11M2 players
            ['name' => 'CEC Groupe 3 (U11F1/U11M2)', 'sportCategory' => $u11, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
        ];

        foreach ($newTeamsData as $teamData) {
            $existing = $manager->getRepository(Team::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $teamData['name'],
            ]);
            if ($existing instanceof Team) {
                $teams[$teamData['name']] = $existing;
            } else {
                $team = new Team;
                $team->setClubId($club->getId());
                $team->setSeasonId($season->getId());
                $team->setSportCategoryId($teamData['sportCategory']->getId());
                $team->setPriorityTierId($teamData['priorityTierId']);
                $team->setName($teamData['name']);
                $team->setLevel($teamData['level']);
                $team->setGender($teamData['gender']);
                $team->setSessionsPerWeek($teamData['sessionsPerWeek']);
                $team->setIsActive(true);
                $manager->persist($team);
                $teams[$teamData['name']] = $team;
            }
        }
        $manager->flush();

        // Extract typed team references for PHPStan level 8
        /** @var array<string, Team> $teams */
        $sm1 = $teams['SM1'];
        $sm2 = $teams['SM2'];
        $sf1 = $teams['SF1'];
        $sf2 = $teams['SF2'];
        $sm3 = $teams['SM3'];
        $sm4 = $teams['SM4'];
        $u21m1 = $teams['U21M1'];
        $u21m2 = $teams['U21M2'];
        $sf3 = $teams['SF3'];
        $u18m1 = $teams['U18M1'];
        $u18m2 = $teams['U18M2'];
        $u18f1 = $teams['U18F1'];
        $u18f2 = $teams['U18F2'];
        $u18f3 = $teams['U18F3'];
        $u15m1 = $teams['U15M1'];
        $u15m2 = $teams['U15M2'];
        $u15f1 = $teams['U15F1'];
        $u15f3 = $teams['U15F3'];
        $u13f1 = $teams['U13F1'];
        $baby1 = $teams['Baby 1'];
        $baby2 = $teams['Baby 2'];
        $microBasket = $teams['Micro Basket'];
        $academieU9U11 = $teams['Academie U9-U11'];
        $academieU13U15 = $teams['Academie U13-U15'];
        $academieU18 = $teams['Academie U18'];
        $loisirFeminine = $teams['Loisir Feminine'];
        $team3x3 = $teams['3x3'];
        $trainigIndiv = $teams['Training Individuel'];
        $cecGroupe1 = $teams['CEC Groupe 1 (U9F1/U9F2/U9M2)'];
        $cecGroupe2 = $teams['CEC Groupe 2 (U11F2/U9M1)'];
        $cecGroupe3 = $teams['CEC Groupe 3 (U11F1/U11M2)'];

        // ============================================================
        // SECTION 5 — NEW COACHES
        // ============================================================
        $newCoachesData = [
            ['firstName' => 'Maxime', 'lastName' => 'Dionnet'],
            ['firstName' => 'Mara', 'lastName' => ''],
            ['firstName' => 'Emerick', 'lastName' => 'Creantor'],
            ['firstName' => 'Nico', 'lastName' => 'Patin'],
            ['firstName' => 'Enzo', 'lastName' => ''],
            ['firstName' => 'Thomas', 'lastName' => ''],
            ['firstName' => 'Flo', 'lastName' => 'Tapaunat'],
            ['firstName' => 'Chris', 'lastName' => ''],
            ['firstName' => 'Marlon', 'lastName' => ''],
            ['firstName' => 'Lionel', 'lastName' => 'Lacroute'],
            ['firstName' => 'Nicolas', 'lastName' => 'Barilleau'],
            ['firstName' => 'Ines', 'lastName' => ''],
            ['firstName' => 'Florian', 'lastName' => ''],
            ['firstName' => 'Luca', 'lastName' => 'Blanchini'],
            ['firstName' => 'Thalie', 'lastName' => ''],
            ['firstName' => 'Cyril', 'lastName' => ''],
            ['firstName' => 'Mathis', 'lastName' => 'Bideaux'],
            ['firstName' => 'Anna', 'lastName' => ''],
            ['firstName' => 'Pierre', 'lastName' => 'Chauvin'],
            ['firstName' => 'Maeleen', 'lastName' => ''],
            ['firstName' => 'Jordan', 'lastName' => ''],
            ['firstName' => 'Ethan', 'lastName' => ''],
            ['firstName' => 'Ambrine', 'lastName' => ''],
            ['firstName' => 'Aela', 'lastName' => ''],
            ['firstName' => 'Charlie', 'lastName' => ''],
            ['firstName' => 'Julia', 'lastName' => ''],
        ];

        foreach ($newCoachesData as $coachData) {
            $key = '' !== $coachData['lastName'] ? $coachData['firstName'] . ' ' . $coachData['lastName'] : $coachData['firstName'];
            $existing = $manager->getRepository(Coach::class)->findOneBy([
                'clubId' => $club->getId(),
                'firstName' => $coachData['firstName'],
            ]);
            if ($existing instanceof Coach) {
                // Keep an already-seeded coach in sync with the data (e.g. a
                // last name added later) — append mode reuses the row, so
                // without this the rename would never reach the DB.
                $existing->setLastName($coachData['lastName']);
                $coaches[$key] = $existing;
            } else {
                $coach = new Coach;
                $coach->setClubId($club->getId());
                $coach->setSeasonId($season->getId());
                $coach->setFirstName($coachData['firstName']);
                $coach->setLastName($coachData['lastName']);
                $coach->setIsActive(true);
                $manager->persist($coach);
                $coaches[$key] = $coach;
            }
        }
        $manager->flush();

        // Extract typed coach references for PHPStan level 8
        /** @var array<string, Coach> $coaches */
        $coachMaxime = $coaches['Maxime Dionnet'];
        $coachMara = $coaches['Mara'];
        $coachEmerick = $coaches['Emerick Creantor'];
        $coachNicoPatin = $coaches['Nico Patin'];
        $coachEnzo = $coaches['Enzo'];
        $coachThomas = $coaches['Thomas'];
        $coachFlo = $coaches['Flo Tapaunat'];
        $coachChris = $coaches['Chris'];
        $coachMarlon = $coaches['Marlon'];
        $coachLionel = $coaches['Lionel Lacroute'];
        $coachNicolasBarilleau = $coaches['Nicolas Barilleau'];
        $coachInes = $coaches['Ines'];
        $coachFlorian = $coaches['Florian'];
        $coachLuca = $coaches['Luca Blanchini'];
        $coachThalie = $coaches['Thalie'];
        $coachJordan = $coaches['Jordan'];
        $coachEthan = $coaches['Ethan'];
        $coachCyril = $coaches['Cyril'];
        $coachMathis = $coaches['Mathis Bideaux'];
        $coachAnna = $coaches['Anna'];
        $coachMaeleen = $coaches['Maeleen'];
        $coachPierreChauvin = $coaches['Pierre Chauvin'];
        $coachAmbrine = $coaches['Ambrine'];
        $coachAela = $coaches['Aela'];
        $coachCharlie = $coaches['Charlie'];
        $coachJulia = $coaches['Julia'];

        $coachNicolasBarilleau->setIsEmployee(true);
        $coachNicoPatin->setIsEmployee(true);
        $coachEnzo->setIsEmployee(true);
        $coachEmerick->setIsEmployee(true);
        $coachThomas->setIsEmployee(true);
        $coachJordan->setIsEmployee(true);

        // ============================================================
        // SECTION 6 — NEW TEAM-COACH LINKS
        // ============================================================
        $newTeamCoachLinks = [
            ['coach' => $coachEmerick, 'team' => $sf1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMara, 'team' => $sf2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachLionel, 'team' => $sf3, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMaxime, 'team' => $sm1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachThomas, 'team' => $sm1, 'role' => TeamCoachRole::ASSISTANT],
            ['coach' => $coachNicoPatin, 'team' => $sm2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachFlo, 'team' => $sm3, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachChris, 'team' => $sm4, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachThomas, 'team' => $u21m1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMarlon, 'team' => $u21m2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachNicolasBarilleau, 'team' => $u18m1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachInes, 'team' => $u18f2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachEnzo, 'team' => $u18f1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachFlorian, 'team' => $u18f3, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachThomas, 'team' => $u15m1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachLuca, 'team' => $u15m2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachEmerick, 'team' => $teams['U15F1'], 'role' => TeamCoachRole::MAIN],
            //            ['coach' => $coachThalie, 'team' => $u15f2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachThalie, 'team' => $u15f3, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachCyril, 'team' => $teams['U13M1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMathis, 'team' => $teams['U13M2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachEnzo, 'team' => $u13f1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachJordan, 'team' => $teams['U13F2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachEthan, 'team' => $teams['U13F3'], 'role' => TeamCoachRole::MAIN],
            //            ['coach' => $coachEnzo, 'team' => $teams['U11M1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAnna, 'team' => $teams['U11M2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAnna, 'team' => $cecGroupe3, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachPierreChauvin, 'team' => $teams['U11F1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachPierreChauvin, 'team' => $cecGroupe3, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMaeleen, 'team' => $teams['U11F2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMaeleen, 'team' => $cecGroupe2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachJordan, 'team' => $teams['U9M1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachJordan, 'team' => $cecGroupe2, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAmbrine, 'team' => $teams['U9M2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAmbrine, 'team' => $cecGroupe1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAela, 'team' => $teams['U9F1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAela, 'team' => $cecGroupe1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachJulia, 'team' => $teams['U9F2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachJulia, 'team' => $cecGroupe1, 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachCharlie, 'team' => $teams['U9F2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachCharlie, 'team' => $cecGroupe1, 'role' => TeamCoachRole::MAIN],
        ];

        // Purge existing team-coach links (club/season) before recreating, so an
        // append-mode reseed can't leave a stale assignment behind when a coach
        // moves between teams — mirrors the VenueTrainingSlot purge above.
        $existingLinks = $manager->getRepository(TeamCoach::class)->findBy([
            'clubId' => $club->getId(),
            'seasonId' => $season->getId(),
        ]);
        foreach ($existingLinks as $existingLink) {
            $manager->remove($existingLink);
        }
        $manager->flush();

        foreach ($newTeamCoachLinks as $link) {
            $existing = $manager->getRepository(TeamCoach::class)->findOneBy([
                'teamId' => $link['team']->getId(),
                'coachId' => $link['coach']->getId(),
                'role' => $link['role'],
            ]);
            if (null === $existing) {
                $teamCoach = new TeamCoach;
                $teamCoach->setClubId($club->getId());
                $teamCoach->setSeasonId($season->getId());
                $teamCoach->setTeamId($link['team']->getId());
                $teamCoach->setCoachId($link['coach']->getId());
                $teamCoach->setRole($link['role']);
                $teamCoach->setIsRequired(true);
                $manager->persist($teamCoach);
            }
        }
        $manager->flush();

        // ============================================================
        // SECTION 7 — NEW COACH-PLAYER MEMBERSHIPS
        // ============================================================
        $newPlayerLinks = [
            ['coach' => $coachEnzo, 'team' => $sm1],
            ['coach' => $coachLuca, 'team' => $sm1],
            ['coach' => $coachNicolasBarilleau, 'team' => $sm2],
            ['coach' => $coachMaxime, 'team' => $sm2],
            ['coach' => $coachMara, 'team' => $sm2],
            ['coach' => $coachEmerick, 'team' => $sm2],
            ['coach' => $coachThomas, 'team' => $sm3],
            ['coach' => $coachInes, 'team' => $sf2],
            ['coach' => $coachThalie, 'team' => $sf3],
            ['coach' => $coachAela, 'team' => $sf3],
            ['coach' => $coachJordan, 'team' => $sm2],
            ['coach' => $coachAnna, 'team' => $u18f1],
            ['coach' => $coachCharlie, 'team' => $u15f1],
            ['coach' => $coachJulia, 'team' => $u15f1],
            // Mathis entraîne U13M2 mais joue aussi en U21M1 ; Florian entraîne
            // U18F3 et joue en Loisir 3 — le solveur en tire un conflit coach
            // (impossible d'être aux deux séances en même temps).
            ['coach' => $coachMathis, 'team' => $u21m1],
            ['coach' => $coachFlorian, 'team' => $teams['Loisir 3']],
        ];

        foreach ($newPlayerLinks as $link) {
            $existing = $manager->getRepository(CoachPlayerMembership::class)->findOneBy([
                'coachId' => $link['coach']->getId(),
                'teamId' => $link['team']->getId(),
            ]);
            if (null === $existing) {
                $membership = new CoachPlayerMembership;
                $membership->setClubId($club->getId());
                $membership->setSeasonId($season->getId());
                $membership->setCoachId($link['coach']->getId());
                $membership->setTeamId($link['team']->getId());
                $membership->setIsActive(true);
                $manager->persist($membership);
            }
        }
        $manager->flush();

        // Purge all existing slot templates for this club/season before recreating
        // This eliminates phantom HARD slots created by old fixture versions or manual edits
        $existingSlots = $manager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'clubId' => $club->getId(),
            'seasonId' => $season->getId(),
        ]);
        foreach ($existingSlots as $existingSlot) {
            $manager->remove($existingSlot);
        }
        $manager->flush();

        // ============================================================
        // SECTION 9 — CONSTRAINTS (regroupées par famille : TIME · DAY · FACILITY · COACH)
        // ============================================================

        // Helper idempotent (le nom fait office de clé naturelle) — défini en tête
        // pour que chaque groupe ci-dessous s'y appuie.
        $addConstraint = function (string $name, ConstraintScope $scope, ?string $targetId, ConstraintFamily $family, ConstraintRuleType $rule, array $config) use ($manager, $club, $season): void {
            $existing = $manager->getRepository(Constraint::class)->findOneBy(['clubId' => $club->getId(), 'name' => $name]);
            if ($existing instanceof Constraint) {
                return;
            }
            $c = new Constraint;
            $c->setClubId($club->getId());
            $c->setSeasonId($season->getId());
            $c->setScope($scope);
            $c->setScopeTargetId($targetId);
            $c->setFamily($family);
            $c->setRuleType($rule);
            $c->setName($name);
            $c->setConfig($config);
            $c->setIsActive(true);
            $manager->persist($c);
        };

        // Purge des contraintes renommées/retirées OU dont la config a changé à nom
        // constant (utile en mode append ; un rechargement complet truncate d'abord).
        // Sans ça, le helper name-keyed conserverait l'ancienne config (ex. EMB 18h00
        // au lieu de 17h30, Camus/SM4 en preferred/venueId au lieu de forcedVenueId).
        foreach ([
            'Jeunes - Fin entraînement 19h30',
            'Jeunes - Début maximum 20h15',
            'EMB - Début maximum 19h50',
            'Camus - Réservé loisir exclusivement',
            // Config modifiée, nom inchangé → à repurger pour un reseed propre.
            'EMB (U9/U11) - Début au premier créneau (max 17h30)',
            'SM4 - Jean Vilar obligatoire',
            'Camus - Réservé Loisir 1 exclusivement',
            'Camus - Réservé Loisir 2 exclusivement',
            'Camus - Réservé Loisir 3 exclusivement',
        ] as $retiredName) {
            $stale = $manager->getRepository(Constraint::class)->findOneBy(['clubId' => $club->getId(), 'name' => $retiredName]);
            if ($stale instanceof Constraint) {
                $manager->remove($stale);
            }
        }
        $manager->flush();

        // --- TIME (heures de début, portée club par tranche d'âge) ---
        $addConstraint('EMB (U9/U11) - Début au premier créneau (max 17h30)', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['maxStartTime' => '17:30', 'targetTag' => 'EMB']);
        $addConstraint('Adultes - Début minimum 18h50', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['minStartTime' => '18:50', 'targetTag' => 'SENIOR']);
        $addConstraint('Jeunes - Début maximum 19h50', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['maxStartTime' => '19:50', 'targetTag' => 'JEUNE']);
        // U15 : finir à 20h30 max ≈ début max 19h00 (séances ~90 min ; le modèle a maxStartTime, pas maxEndTime).
        $addConstraint('U15 - Fin 20h30 max (début max 19h00)', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['maxStartTime' => '19:00', 'targetTag' => 'U15']);
        $addConstraint('U13 - Début après 17h00', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['minStartTime' => '17:00', 'targetTag' => 'U13']);
        // U13 : ne pas commencer après 19h00 (laisse la marge du vendredi 20h30, l'exception par-jour n'étant pas exprimable).
        $addConstraint('U13 - Début préféré avant 19h00', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['maxStartTime' => '19:00', 'targetTag' => 'U13']);

        // --- DAY (jours imposés / interdits) ---
        // « uniquement » = allowedDays (whitelist : seul le vendredi permis). forcedDays
        // ne veut dire QUE « au moins une séance ce jour-là » côté engine (audit ENG-16).
        $addConstraint('Veterans - Vendredi uniquement', ConstraintScope::TEAM, $teams['Veterans']->getId(), ConstraintFamily::DAY, ConstraintRuleType::HARD, ['allowedDays' => [5]]);
        $addConstraint('SM2 - Évite le vendredi', ConstraintScope::TEAM, $teams['SM2']->getId(), ConstraintFamily::DAY, ConstraintRuleType::PREFERRED, ['forbiddenDays' => [5]]);
        // Jeunes U9/U11 : pas d'entraînement le mercredi (ils ont déjà le CEC ce jour-là).
        foreach (['U11F1', 'U11F2', 'U11M2', 'U9M1', 'U9M2', 'U9F1', 'U9F2'] as $teamName) {
            $addConstraint($teamName . ' - Pas d\'entraînement le mercredi', ConstraintScope::TEAM, $teams[$teamName]->getId(), ConstraintFamily::DAY, ConstraintRuleType::HARD, ['forbiddenDays' => [3]]);
        }

        // --- FACILITY (gymnases imposés / interdits / préférés) ---
        $addConstraint('Jean Vilar - Pas équipes féminines', ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['forbiddenVenueId' => $venues['vJeanVilar']->getId(), 'targetTag' => 'FEMININE']);
        // Venue OBLIGATOIRE (HARD) = forcedVenueId : l'engine ne force la salle que
        // via forcedVenueId (ou preferredVenueId en HARD), jamais via un `venueId` —
        // clé qu'aucune branche du parseur ne lit (sinon contrainte silencieuse).
        $addConstraint('SM4 - Jean Vilar obligatoire', ConstraintScope::TEAM, $sm4->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['forcedVenueId' => $venues['vJeanVilar']->getId()]);
        // Camus réservé EXCLUSIVEMENT aux Loisir 1/2/3 (HARD forcedVenueId, pas un simple nudge).
        foreach (['Loisir 1', 'Loisir 2', 'Loisir 3'] as $loisirName) {
            $addConstraint('Camus - Réservé ' . $loisirName . ' exclusivement', ConstraintScope::TEAM, $teams[$loisirName]->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['forcedVenueId' => $venues['vCamus']->getId()]);
        }
        // Veterans interdits sur Camus/JDR/Jean Vilar/Tonkin/ADN.
        foreach (['vCamus', 'vJdr', 'vJeanVilar', 'vTonkin', 'vAdn'] as $venueVar) {
            $venueId = $venues[$venueVar]->getId();
            $addConstraint('Veterans - Interdit ' . $venueId, ConstraintScope::TEAM, $teams['Veterans']->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['forbiddenVenueId' => $venueId]);
        }
        // Préférences de gymnase par niveau (portée club).
        $addConstraint('Matéo - Préféré équipes régionales', ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues['vMateo']->getId(), 'targetTag' => 'REGIONAL']);
        $addConstraint('De Barros Annexe - Préféré équipes départementales', ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues['vDebarrosAnnexe']->getId(), 'targetTag' => 'DEPARTEMENTAL']);
        foreach ([TeamLevel::LOISIR_ADULTE, TeamLevel::LOISIR_JEUNE] as $loisirLevel) {
            $addConstraint('De Barros Annexe - Préféré ' . $loisirLevel->value, ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues['vDebarrosAnnexe']->getId(), 'targetTag' => $loisirLevel->value]);
        }
        // Jean Vilar préféré pour les garçons U15/U18/U21.
        foreach ([$u15m1, $u15m2, $u18m1, $u18m2, $u21m1, $u21m2] as $targetTeam) {
            $addConstraint($targetTeam->getName() . ' - Jean Vilar préféré', ConstraintScope::TEAM, $targetTeam->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues['vJeanVilar']->getId()]);
        }
        // U18F2 / U18M2 : au moins une séance à Armand ou Matéo (préférence). preferredVenueId
        // ne cible qu'un gymnase → une contrainte par gymnase ; une séance dans l'un OU l'autre décroche le bonus.
        foreach (['U18F2', 'U18M2'] as $teamName) {
            foreach (['vArmand' => 'Armand', 'vMateo' => 'Matéo'] as $venueVar => $venueLabel) {
                $addConstraint(\sprintf('%s - %s préféré', $teamName, $venueLabel), ConstraintScope::TEAM, $teams[$teamName]->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues[$venueVar]->getId()]);
            }
        }

        // --- COACH_AVAILABILITY (indisponibilités ; 5 = vendredi, 4 = jeudi) ---
        // Variables coach déjà résolues (l.618+) : un coach manquant lève une erreur PHP au lieu de disparaître en silence.
        foreach ([[$coachLionel, 5], [$coachThomas, 5], [$coachEnzo, 5], [$coachJordan, 5], [$coachNicoPatin, 4], [$coachEmerick, 4]] as [$coach, $day]) {
            $label = 5 === $day ? 'le vendredi' : 'le jeudi';
            $addConstraint(\sprintf('%s - Indisponible %s', $coach->getFirstName(), $label), ConstraintScope::COACH, $coach->getId(), ConstraintFamily::COACH_AVAILABILITY, ConstraintRuleType::HARD, ['coachId' => $coach->getId(), 'unavailableDays' => [$day]]);
        }

        $manager->flush();

        // ============================================================
        // SECTION 10 — ADDITIONAL SLOT TEMPLATES
        // ============================================================

        // JDR Saturday — Academie hard-locked sessions
        $additionalSlots = [
            // SM1
            ['team' => $sm1, 'venue' => 'vMateo', 'day' => 2, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            ['team' => $sm1, 'venue' => 'vMateo', 'day' => 4, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            // SF1
            ['team' => $sf1, 'venue' => 'vDebarros', 'day' => 2, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            ['team' => $sf1, 'venue' => 'vMateo', 'day' => 3, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            // Loisir Feminine
            ['team' => $loisirFeminine, 'venue' => 'vDebarros', 'day' => 4, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            // 3x3
            ['team' => $team3x3, 'venue' => 'vAdn', 'day' => 3, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            // SM3
            ['team' => $sm3, 'venue' => 'vArmand', 'day' => 3, 'startTime' => '20:15', 'duration' => 135, 'lock' => LockLevel::HARD],
            // Training indiv
            ['team' => $trainigIndiv, 'venue' => 'vArmand', 'day' => 1, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            // JDR Saturday academies
            ['team' => $academieU9U11, 'venue' => 'vJdr', 'day' => 6, 'startTime' => '09:00', 'duration' => 75, 'lock' => LockLevel::HARD],
            ['team' => $academieU13U15, 'venue' => 'vJdr', 'day' => 6, 'startTime' => '10:15', 'duration' => 75, 'lock' => LockLevel::HARD],
            ['team' => $academieU18, 'venue' => 'vJdr', 'day' => 6, 'startTime' => '11:30', 'duration' => 75, 'lock' => LockLevel::HARD],
            // Matéo Saturday morning — Baby & Micro Basket
            ['team' => $microBasket, 'venue' => 'vMateo', 'day' => 6, 'startTime' => '09:00', 'duration' => 45, 'lock' => LockLevel::HARD],
            ['team' => $baby1, 'venue' => 'vMateo', 'day' => 6, 'startTime' => '09:45', 'duration' => 60, 'lock' => LockLevel::HARD],
            ['team' => $baby2, 'venue' => 'vMateo', 'day' => 6, 'startTime' => '10:45', 'duration' => 60, 'lock' => LockLevel::HARD],
            // CEC Groupe 1 — ADN Wednesday 17:30 (ADN can be split into 3 courts)
            ['team' => $cecGroupe1, 'venue' => 'vAdn', 'day' => 3, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            // CEC Groupe 1 — Mateo Wednesday 16:00
            ['team' => $cecGroupe2, 'venue' => 'vMateo', 'day' => 3, 'startTime' => '16:00', 'duration' => 90, 'lock' => LockLevel::HARD],
            // CEC Groupe 1 — Mateo Wednesday 17:30
            ['team' => $cecGroupe3, 'venue' => 'vMateo', 'day' => 3, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            // mercredi shark a mateo
            ['team' => $teams['Mercredi Shark U9-U11'], 'venue' => 'vMateo', 'day' => 3, 'startTime' => '09:30', 'duration' => 75, 'lock' => LockLevel::HARD],
        ];

        // These are pre-generation RESERVATIONS (durable HARD team→slot pins), not
        // schedule-bound templates: base plan → calendarEntryId NULL. The generation
        // pipeline reads them into the engine's slotTemplates payload, and the
        // wizard "Réserver" tab lists them from the server.
        foreach ($additionalSlots as $slotData) {
            $startTime = new DateTimeImmutable($slotData['startTime']);
            $existing = $manager->getRepository(Reservation::class)->findOneBy([
                'teamId' => $slotData['team']->getId(),
                'venueId' => $venues[$slotData['venue']]->getId(),
                'dayOfWeek' => $slotData['day'],
                'startTime' => $startTime,
            ]);
            if (!$existing instanceof Reservation) {
                $reservation = new Reservation;
                $reservation->setClubId($club->getId());
                $reservation->setSeasonId($season->getId());
                $reservation->setSchedulePlanId(null); // réservation de BASE (structure partagée, inv. 6)
                $reservation->setTeamId($slotData['team']->getId());
                $reservation->setVenueId($venues[$slotData['venue']]->getId());
                $reservation->setDayOfWeek($slotData['day']);
                $reservation->setStartTime($startTime);
                $reservation->setDurationMinutes($slotData['duration']);
                $manager->persist($reservation);
            }
        }

        $manager->flush();

        $this->loadFakeClubFromScratch($manager);
    }

    /**
     * Fresh "from scratch" account (N'Gnima EBLIN / FakeClub, ffbb ARA00TEST).
     * Mirrors AuthController::register for a NEW club: active admin membership,
     * seeded active season + sport + sport categories, onboarding NOT completed
     * (so the account lands on the wizard). No teams/venues/coaches on purpose.
     */
    private function loadFakeClubFromScratch(EntityManagerInterface $manager): void
    {
        $ara = 'ARA00TEST';

        $club = $manager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        if (!$club instanceof Club) {
            $club = new Club;
            $club->setName('FakeClub');
            $club->setSlug('fakeclub');
            $club->setFfbbClubCode($ara);
            $club->setTimezone('Europe/Paris');
            $club->setLocale('fr');
            $club->setOnboardingCompleted(false);
            $manager->persist($club);
            $manager->flush();
        }

        $clubId = $club->getId();
        $manager->getConnection()->executeStatement('SELECT set_config(\'app.club_id\', ?, false)', [$clubId]);

        $user = $manager->getRepository(User::class)->findOneBy(['email' => 'n.eblin@gmail.com']);
        if (!$user instanceof User) {
            $user = new User;
            $user->setEmail('n.eblin@gmail.com');
            $user->setFirstName('N\'Gnima');
            $user->setLastName('EBLIN');
            $user->setEmailVerifiedAt(new DateTimeImmutable);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'jennifer'));
            $manager->persist($user);
            $manager->flush();
        }

        $existingMembership = $manager->getRepository(ClubUser::class)->findOneBy([
            'clubId' => $club->getId(),
            'userId' => $user->getId(),
        ]);
        if (null === $existingMembership) {
            $clubUser = new ClubUser;
            $clubUser->setClubId($club->getId());
            $clubUser->setUserId($user->getId());
            $clubUser->setRole('admin');
            $clubUser->setIsActive(true);
            $manager->persist($clubUser);
        }

        $existingSeason = $manager->getRepository(Season::class)->findOneBy([
            'clubId' => $club->getId(),
            'status' => 'active',
        ]);
        if (null === $existingSeason) {
            $currentYear = (int) (new DateTimeImmutable)->format('Y');
            $season = new Season;
            $season->setClubId($club->getId());
            $season->setName((string) $currentYear);
            $season->setStartDate(new DateTimeImmutable($currentYear . '-08-01'));
            $season->setEndDate(new DateTimeImmutable($currentYear . '-07-15'));
            $season->setStatus('active');
            $season->setTransitionData([]);
            $manager->persist($season);
            // ADR-0002 Lot A: seed the season's empty SEASON plan.
            $this->schedulePlanProvisioner->ensureSeasonPlan($season);

            $sport = $manager->getRepository(Sport::class)->findOneBy(['slug' => 'basketball']);
            if (!$sport instanceof Sport) {
                $sport = new Sport;
                $sport->setName('Basketball');
                $sport->setSlug('basketball');
                $sport->setIsActive(true);
                $manager->persist($sport);
                $manager->flush();
            }

            $categories = BasketballCategoryCatalog::categories();
            foreach ($categories as $categoryData) {
                $sportCategory = new SportCategory;
                $sportCategory->setClubId($club->getId());
                $sportCategory->setSportId($sport->getId());
                $sportCategory->setName($categoryData['name']);
                $sportCategory->setAgeMin($categoryData['ageMin']);
                $sportCategory->setAgeMax($categoryData['ageMax']);
                $sportCategory->setIsCustom(false);
                $sportCategory->setSortOrder($categoryData['sortOrder']);
                $manager->persist($sportCategory);
            }
        }

        $manager->flush();
    }

    /**
     * Store the optional default logo for the seeded club, applying the same
     * size + MIME guards as ClubLogoController so the fixture never ships an
     * asset the real upload would reject. Any problem (absent, empty, too big,
     * wrong type) skips silently — the fixture must not fail on a demo asset.
     */
    private function seedDefaultLogo(Club $club, string $clubId, EntityManagerInterface $manager): void
    {
        if (null !== $club->getLogoUrl() || !is_file(self::BCCL_LOGO_PATH)) {
            return;
        }
        $size = filesize(self::BCCL_LOGO_PATH);
        if (false === $size || 0 === $size || $size > self::LOGO_MAX_BYTES) {
            return;
        }
        $mime = new finfo(\FILEINFO_MIME_TYPE)->file(self::BCCL_LOGO_PATH);
        if (!\in_array($mime, self::LOGO_ALLOWED_MIME, true)) {
            return;
        }
        $bytes = file_get_contents(self::BCCL_LOGO_PATH);
        if (false === $bytes || '' === $bytes) {
            return;
        }
        $this->logoStorage->store($clubId, $bytes);
        $club->setLogoUrl(LogoUrl::build($clubId, $bytes));
        $manager->flush();
    }
}
