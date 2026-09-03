<?php

declare(strict_types=1);

namespace App\Seed;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\ImplicitRuleSetting;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\TeamPeriodOverride;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\Gender;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLevel;
use App\Enum\TeamLinkType;
use App\Enum\VenuePeriodMode;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Service\Basketball\CategoryCatalog;
use App\Service\LeagueResolver;
use App\Service\OverlayManager;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\SchoolZoneResolver;
use App\Service\SeasonResolver;
use App\Storage\LogoStorage;
use App\Storage\LogoUrl;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use finfo;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P2-4 PR 2bis — le SEED BCCL, extrait des fixtures pour être appelable en PROD.
 *
 * Extrait des fixtures pour être appelable en PROD : le club de démonstration
 * permanent doit s'y (re)seeder (`app:demo:seed`). Ce service porte donc TOUTE la
 * logique ; les commandes ne sont que des habillages qui l'appellent avec un
 * profil — `app:bccl:seed` pour `dev()` (le club réel), `app:demo:seed` pour
 * `demo()`. L'identité (club, gestionnaire, coachs, logo) vient du
 * {@see BcclSeedProfile} — le corps du seed est identique pour les deux visages.
 */
final class BcclSeeder
{
    /**
     * Provenance de la version transcrite (P5-17) : marqueur de solverVersion, aussi la
     * clé de find-or-create du Schedule. Une transcription du planning réel, pas un solve.
     */
    private const string SEED_TRANSCRIPTION_MARKER = 'seed-transcription';

    /** Optional default logo for the seeded club (drop a PNG here to ship one). */
    private const string BCCL_LOGO_PATH = __DIR__ . '/../DataFixtures/assets/bccl-logo.png';
    private const int LOGO_MAX_BYTES = 512_000; // 500 KB — same as ClubLogoController
    private const array LOGO_ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SchoolZoneResolver $schoolZoneResolver,
        private readonly LeagueResolver $leagueResolver,
        private readonly LogoStorage $logoStorage,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ScheduleConstraintBuilder $constraintBuilder,
        private readonly SchoolHolidayPeriodRepository $schoolHolidayRepository,
        private readonly OverlayManager $overlayManager,
    ) {}

    public function run(EntityManagerInterface $manager, BcclSeedProfile $profile): Club
    {
        // RLS guard: as amateo_app the purge phase silently DELETEs zero rows on
        // tenant tables (fail-closed policies) and the reload then collides
        // with the surviving data — a half-purged database. Fail fast instead.
        $superuser = (bool) $manager->getConnection()->fetchOne(
            'SELECT usesuper FROM pg_user WHERE usename = current_user',
        );
        if (!$superuser) {
            throw new RuntimeException('The seed must run on the admin connection (RLS silently breaks the purge as amateo_app). Use `make seed-bccl` / `make seed-demo`, which inject DATABASE_URL=<DATABASE_ADMIN_URL>.');
        }

        // --- Club ---
        $existingClub = $manager->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $profile->ffbbCode]);
        if ($existingClub instanceof Club) {
            $club = $existingClub;
        } else {
            $club = new Club;
            $club->setName($profile->clubName);
            $club->setSlug($profile->clubSlug);
            $club->setFfbbClubCode($profile->ffbbCode);
            $club->setIsDemo($profile->isDemo);
            $club->setTimezone('Europe/Paris');
            $club->setLocale('fr');
            // Established club: onboarding done → free wizard navigation.
            $club->setOnboardingCompleted(true);
            $manager->persist($club);
        }
        // Derive the academic zone + league from the FFBB code, exactly like the
        // registration path (AuthController::createClub) — otherwise the seeded
        // "established" club is LESS configured than a freshly registered one
        // (no vacances zone shown, no league envelope). Only fill when empty so a
        // re-run never reverts a manual PATCH correction (resolver = best-effort).
        if (null === $club->getSchoolZone()) {
            $club->setSchoolZone($this->schoolZoneResolver->resolveFromFfbbCode($profile->ffbbCode));
        }
        if (null === $club->getLeague()) {
            $club->setLeague($this->leagueResolver->resolveFromFfbbCode($profile->ffbbCode));
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
        // P1-3 — le club dev/démo vit en Bêta (tout illimité) : « les tests sont de
        // facto en bêta » (fondateur). Only-fill-when-empty, comme les champs ci-dessus.
        if (null === $club->getPlanId()) {
            $beta = $manager->getRepository(SubscriptionPlan::class)->findOneBy(['code' => 'beta']);
            if ($beta instanceof SubscriptionPlan) {
                $club->setPlanId($beta->getId());
                // L'attribution est en DEUX gestes (set-plan + saison réglée) :
                // sans paidSeasonYear, la bêta naît EXPIRÉE → Découverte effective
                // → les smokes décomptent le pool puis se font refuser à 10.
                // Constaté sur la PR B ; le seeder fait donc les deux gestes.
                if (null === $club->getPaidSeasonYear()) {
                    $club->setPaidSeasonYear(SeasonResolver::seasonYear(new DateTimeImmutable));
                }
            }
        }
        $manager->flush();

        $clubId = $club->getId();
        $manager->getConnection()->executeStatement('SELECT set_config(\'app.club_id\', ?, false)', [$clubId]);

        // Default club logo (optional asset): store the bytes + point logoUrl at
        // the public serve route, mirroring ClubLogoController — including its
        // size + MIME guards, so the fixture can't ship what an upload would
        // refuse. Skipped silently if absent/invalid; the fixture never fails.
        if ($profile->seedLogo) {
            $this->seedDefaultLogo($club, $clubId, $manager);
        }

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
        // Le club connaît son sport de première main (comme le register).
        $club->setSportId($sport->getId());

        // --- Categories (ungendered age brackets — see CategoryCatalog) ---
        $categories = CategoryCatalog::categories();

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
            $season->setStatus(SeasonStatus::ACTIVE);
            $manager->persist($season);
        }
        // ADR-0002 Lot A: seed the season's empty SEASON plan (idempotent).
        $this->schedulePlanProvisioner->ensureSeasonPlan($season);

        // Le socle (baseline/socle validé) n'est PAS stampé ici. Deux visages ensuite
        // (P5-17, tout en fin de run() sous le drapeau `transcribeRealSchedule`) :
        // - profil dev : le plan SEASON POINTE une version COMPLETED transcrivant le
        //   planning réel — le club dev ouvre sur son planning, pas sur le wizard ;
        // - profils démo/charge : rien n'est pointé (cockpit state 1) — toute la donnée
        //   est saisie mais AUCUN planning généré, le club atterrit sur le wizard (Récap)
        //   avant sa première génération, la démo réaliste d'un club en cours d'onboarding.

        // --- User ---
        $existingUser = $manager->getRepository(User::class)->findOneBy(['email' => $profile->managerEmail]);
        if ($existingUser instanceof User) {
            $user = $existingUser;
        } else {
            $user = new User;
            $user->setEmail($profile->managerEmail);
            $user->setFirstName($profile->managerFirstName);
            $user->setLastName($profile->managerLastName);
            // Seeded accounts are pre-verified so dev/e2e/demo login works (the login
            // gate rejects emailVerifiedAt = null).
            $user->setEmailVerifiedAt(new DateTimeImmutable);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $profile->managerPassword));
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

        // P5-13 — gestionnaires ADDITIONNELS du profil (Nicolas en dev). Find-or-create par
        // email, jamais écrasés : même patron que le gestionnaire principal ci-dessus.
        $this->seedAdditionalManagers($manager, $club, $profile);

        // ============================================================
        // SECTION 2 — VENUES
        // ============================================================
        // [name, var, color (hex), canSplit, ref (n° fédéral FFBB), lat, lng] —
        // Matéo + JDR (en 2) et ADN (en 3, en travers) are divisible gyms.
        // Refs/GPS = l'état LIÉ du 2026-08-05
        // (« Associer à… », salles FFBB de Villeurbanne) — le nom d'usage reste
        // celui du club, seule l'ancre fédérale est portée.
        $venuesData = [
            ['name' => 'Armand', 'var' => 'vArmand', 'color' => '#1E88E5', 'canSplit' => true, 'ref' => '166926610', 'lat' => '45.77935', 'lng' => '4.88604'],
            ['name' => 'ADN', 'var' => 'vAdn', 'color' => '#FDD835', 'canSplit' => true, 'ref' => '6926617', 'lat' => '45.77184', 'lng' => '4.87672'],
            ['name' => 'Debarros', 'var' => 'vDebarros', 'color' => '#2E7D32', 'canSplit' => false, 'ref' => '166926603', 'lat' => '45.76799', 'lng' => '4.88853'],
            ['name' => 'Annexe', 'var' => 'vDebarrosAnnexe', 'color' => '#66BB6A', 'canSplit' => false, 'ref' => '166926616', 'lat' => '45.76294', 'lng' => '4.91014'],
            ['name' => 'Jean Vilar', 'var' => 'vJeanVilar', 'color' => '#1A237E', 'canSplit' => false, 'ref' => '166926613', 'lat' => '45.77926', 'lng' => '4.90377'],
            ['name' => 'Tonkin', 'var' => 'vTonkin', 'color' => '#FB8C00', 'canSplit' => false, 'ref' => '166926601', 'lat' => '45.77591', 'lng' => '4.86471'],
            ['name' => 'JDR', 'var' => 'vJdr', 'color' => '#F8BBD0', 'canSplit' => true, 'ref' => '6926616', 'lat' => '45.76499', 'lng' => '4.90510'],
            ['name' => 'Matéo', 'var' => 'vMateo', 'color' => '#E53935', 'canSplit' => true, 'ref' => '166926608', 'lat' => '45.77999', 'lng' => '4.88473'],
            ['name' => 'Camus', 'var' => 'vCamus', 'color' => '#8E24AA', 'canSplit' => false, 'ref' => '166926606', 'lat' => '45.75222', 'lng' => '4.91417'],
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
            // Identity colour + divisibility + ancre FFBB (seeded, re-applied on re-run).
            $venue->setColor($vd['color']);
            $venue->setCanSplit($vd['canSplit']);
            $venue->setExternalRef($vd['ref']);
            $venue->setLatitude($vd['lat']);
            $venue->setLongitude($vd['lng']);
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
        // Capacité 1 partout : les créneaux jadis en cap 2/3 (paires jeunes, CEC) étaient un
        // PALLIATIF à la mutualisation. Le partage se dit désormais par un bloc socle (P2-51,
        // seedTeamLinksAndSharedBlocks) — un groupe complet compte pour UN occupant.
        /** @var list<array{string, int, string, int, int}> $trainingSlots */
        $trainingSlots = [
            // Matéo — Mon
            ['vMateo', 1, '16:00', 90, 1],
            ['vMateo', 1, '17:30', 90, 1], // bloc {U9F1, U9F2}
            ['vMateo', 1, '19:00', 90, 1],
            ['vMateo', 1, '20:30', 120, 1],
            // Matéo — Tue
            ['vMateo', 2, '17:30', 90, 1],
            ['vMateo', 2, '19:00', 105, 1],
            ['vMateo', 2, '20:45', 105, 1],
            // Matéo — Wed
            ['vMateo', 3, '09:30', 75, 1],
            // Mer 16:00 bloc CEC2 {U9M1, U11F2}, 17:30 bloc CEC3 {U11M2, U11F1} — libellé CEC gardé (D8).
            ['vMateo', 3, '16:00', 90, 1, 'CEC'],
            ['vMateo', 3, '17:30', 90, 1, 'CEC'],
            ['vMateo', 3, '19:00', 90, 1],
            ['vMateo', 3, '20:30', 120, 1],
            // Matéo — Thu
            ['vMateo', 4, '16:00', 90, 1],
            ['vMateo', 4, '17:30', 90, 1],
            ['vMateo', 4, '19:00', 90, 1],
            ['vMateo', 4, '20:30', 120, 1],
            // Matéo — Fri
            ['vMateo', 5, '16:00', 90, 1],
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
            ['vJdr', 2, '17:30', 90, 1], // bloc {U13F2, U13F3}
            ['vJdr', 2, '19:00', 90, 1],
            ['vJdr', 2, '20:30', 120, 1],
            // JDR — Thu
            ['vJdr', 4, '17:30', 90, 1], // bloc {U9M1, U9M2}
            ['vJdr', 4, '19:00', 90, 1],
            ['vJdr', 4, '20:30', 120, 1],
            // JDR — Sat (Académie)
            ['vJdr', 6, '09:00', 75, 1],
            ['vJdr', 6, '10:15', 75, 1],
            ['vJdr', 6, '11:30', 75, 1],
            // Armand — Mon
            ['vArmand', 1, '17:30', 90, 1], // bloc {U13M1, U13M2}
            ['vArmand', 1, '19:00', 90, 1],
            ['vArmand', 1, '20:30', 120, 1],
            // Armand — Tue
            ['vArmand', 2, '17:30', 90, 1],
            ['vArmand', 2, '19:00', 90, 1],
            // Armand — Wed
            // Mer après-midi réel 2026-08-05 : 14:00 (105', bloc {U13F1, U13F2}) puis 15:45.
            ['vArmand', 3, '14:00', 105, 1],
            ['vArmand', 3, '15:45', 90, 1],
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
            ['vJeanVilar', 2, '18:45', 105, 1],
            ['vJeanVilar', 2, '20:30', 120, 1],
            ['vJeanVilar', 4, '19:00', 90, 1],
            ['vJeanVilar', 4, '20:30', 120, 1],
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
            ['vDebarros', 4, '16:00', 90, 1],
            ['vDebarros', 4, '17:30', 90, 1],
            ['vDebarros', 4, '19:00', 90, 1],
            ['vDebarros', 4, '20:30', 120, 1],
            // Debarros — Fri
            ['vDebarros', 5, '16:00', 90, 1],
            ['vDebarros', 5, '17:30', 90, 1],
            ['vDebarros', 5, '19:00', 90, 1],
            ['vDebarros', 5, '20:30', 120, 1],
            // Annexe (vDebarrosAnnexe) — Mon
            ['vDebarrosAnnexe', 1, '20:30', 120, 1],
            // Annexe — Tue
            ['vDebarrosAnnexe', 2, '19:00', 90, 1],
            // Annexe — Wed
            ['vDebarrosAnnexe', 3, '17:30', 90, 1],
            ['vDebarrosAnnexe', 3, '10:45', 75, 1],
            ['vDebarrosAnnexe', 3, '19:00', 90, 1],
            ['vDebarrosAnnexe', 3, '20:30', 120, 1],
            // Annexe — Fri
            ['vDebarrosAnnexe', 5, '19:00', 90, 1],
            // ADN — Wed
            // Mer 17:30 bloc CEC1 {U9F1, U9F2, U9M2} — libellé CEC gardé (D8).
            ['vAdn', 3, '17:30', 90, 1, 'CEC'],
            ['vAdn', 3, '19:00', 90, 1],
            ['vAdn', 3, '20:30', 120, 1],
        ];

        // 6e élément optionnel : libellé de groupe d'un créneau mutualisé (« CEC », P2-17).
        /** @var list<array{string, int, string, int, int, 5?: string}> $trainingSlots */
        foreach ($trainingSlots as $row) {
            [$venueVar, $day, $startTime, $duration, $capacity] = $row;
            $slot = new VenueTrainingSlot;
            $slot->setGroupLabel($row[5] ?? null);
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
            ['name' => 'SM3', 'sportCategory' => $senior, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'SM4', 'sportCategory' => $senior, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 1, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'Veterans', 'sportCategory' => $veteran, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::M],
            ['name' => 'U21M1', 'sportCategory' => $u21, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 2, 'gender' => Gender::M],
            ['name' => 'U21M2', 'sportCategory' => $u21, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'SF3', 'sportCategory' => $senior, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U18M1', 'sportCategory' => $u18, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 2, 'gender' => Gender::M],
            ['name' => 'U18M2', 'sportCategory' => $u18, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U18F1', 'sportCategory' => $u18, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 2, 'gender' => Gender::F],
            ['name' => 'U18F2', 'sportCategory' => $u18, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U18F3', 'sportCategory' => $u18, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U15M1', 'sportCategory' => $u15, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 2, 'gender' => Gender::M],
            ['name' => 'U15M2', 'sportCategory' => $u15, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U15F1', 'sportCategory' => $u15, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 2, 'gender' => Gender::F],
            ['name' => 'U15F2', 'sportCategory' => $u15, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U15F3', 'sportCategory' => $u15, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U13F1', 'sportCategory' => $u13, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 2, 'gender' => Gender::F],
            ['name' => 'U13F2', 'sportCategory' => $u13, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U13F3', 'sportCategory' => $u13, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U13M1', 'sportCategory' => $u13, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 2, 'gender' => Gender::M],
            ['name' => 'U13M2', 'sportCategory' => $u13, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 3, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U11M1', 'sportCategory' => $u11, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U11M2', 'sportCategory' => $u11, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
            ['name' => 'U11F1', 'sportCategory' => $u11, 'level' => TeamLevel::REGIONAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U11F2', 'sportCategory' => $u11, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U9F1', 'sportCategory' => $u9, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::F],
            ['name' => 'U9F2', 'sportCategory' => $u9, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::F],
            ['name' => 'U9M1', 'sportCategory' => $u9, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 3, 'gender' => Gender::M],
            ['name' => 'U9M2', 'sportCategory' => $u9, 'level' => TeamLevel::DEPARTEMENTAL, 'sessionsPerWeek' => 2, 'priorityTierId' => 4, 'gender' => Gender::M],
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
            ['name' => 'Training Individuel', 'sportCategory' => $senior, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            // --- Sections ajoutées sur le terrain (2026-08-05) ---
            ['name' => 'Basket Santé', 'sportCategory' => $veteran, 'level' => TeamLevel::LOISIR_ADULTE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::MIXTE],
            ['name' => 'Section J.Macé', 'sportCategory' => $u15, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 3, 'priorityTierId' => 5, 'gender' => null],
            ['name' => 'U18M Fays', 'sportCategory' => $u18, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::M],
            ['name' => 'U18F Fays', 'sportCategory' => $u18, 'level' => TeamLevel::LOISIR_JEUNE, 'sessionsPerWeek' => 1, 'priorityTierId' => 5, 'gender' => Gender::F],
        ];

        // Rang interne d'une équipe DANS son tier (0-based) — l'état réel du club au
        // 2026-08-12. Le seed n'en posait aucun (tout à 0) ; le rang global se dérive
        // de (ordre du tier, tierOrder). Les trous du rang D (7→9) sont RÉELS et
        // reproduits tels quels (des équipes intercalées puis retirées à la main).
        $tierOrderByName = [
            // Rang S
            'SM1' => 0, 'SF1' => 1,
            // Rang A
            'SM2' => 0, 'SF2' => 1, 'U21M1' => 2, 'U18M1' => 3, 'U18F1' => 4, 'U15M1' => 5, 'U15F1' => 6, 'U13M1' => 7, 'U13F1' => 8,
            // Rang B
            'SM3' => 0, 'U18M2' => 1, 'U18F2' => 2, 'U15M2' => 3, 'U15F2' => 4, 'U13F2' => 5, 'U13M2' => 6, 'U11M1' => 7, 'U11F1' => 8, 'U9M1' => 9, 'U9F1' => 10,
            // Rang C
            'SM4' => 0, 'SF3' => 1, 'U21M2' => 2, 'U18F3' => 3, 'U15F3' => 4, 'U13F3' => 5, 'U11M2' => 6, 'U11F2' => 7, 'U9F2' => 8, 'U9M2' => 9,
            // Rang D (trous 7-9 réels)
            '3x3' => 0, 'Academie U13-U15' => 1, 'Academie U18' => 2, 'Academie U9-U11' => 3, 'Baby 1' => 4, 'Baby 2' => 5, 'Basket Santé' => 6,
            'Loisir 1' => 10, 'Loisir 2' => 11, 'Loisir 3' => 12, 'Loisir Feminine' => 13, 'Mercredi Shark U9-U11' => 14, 'Micro Basket' => 15,
            'Section J.Macé' => 16, 'Training Individuel' => 17, 'U18F Fays' => 18, 'U18M Fays' => 19, 'Veterans' => 20,
        ];

        foreach ($newTeamsData as $teamData) {
            // Clé garantie exhaustive : PHPStan échoue si une équipe n'a pas de rang.
            $tierOrder = $tierOrderByName[$teamData['name']];
            $existing = $manager->getRepository(Team::class)->findOneBy([
                'clubId' => $club->getId(),
                'name' => $teamData['name'],
            ]);
            if ($existing instanceof Team) {
                // Reseed d'un club déjà seedé (mode append) : recaler le rang, qui
                // valait 0 avant cet alignement — comme le lastName des coachs plus bas.
                $existing->setTierOrder($tierOrder);
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
                $team->setTierOrder($tierOrder);
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

        // ============================================================
        // SECTION 5 — NEW COACHES
        // ============================================================
        $newCoachesData = [
            ['firstName' => 'Maxime', 'lastName' => 'Dionnet'],
            ['firstName' => 'Mara', 'lastName' => ''],
            ['firstName' => 'Emerick', 'lastName' => 'Creantor'],
            ['firstName' => 'Nico', 'lastName' => 'Patin'],
            ['firstName' => 'Enzo', 'lastName' => 'Camerino'],
            ['firstName' => 'Thomas', 'lastName' => 'Francon'],
            ['firstName' => 'Florian', 'lastName' => 'Tapaunat'],
            ['firstName' => 'Christophe', 'lastName' => 'Renaud'],
            ['firstName' => 'Marlon', 'lastName' => 'Depierre'],
            ['firstName' => 'Lionel', 'lastName' => 'Lacroute'],
            ['firstName' => 'Nicolas', 'lastName' => 'Barilleau'],
            ['firstName' => 'Ines', 'lastName' => ''],
            ['firstName' => 'Florian', 'lastName' => ''],
            ['firstName' => 'Luca', 'lastName' => 'Blanchini'],
            ['firstName' => 'Thalie', 'lastName' => 'Charpenet'],
            ['firstName' => 'Cyril', 'lastName' => 'Benveniste'],
            ['firstName' => 'Mathis', 'lastName' => 'Bidaux'],
            ['firstName' => 'Anna', 'lastName' => 'Textoris'],
            ['firstName' => 'Pierre', 'lastName' => 'Chauvin'],
            ['firstName' => 'Maeleen', 'lastName' => 'Creantor'],
            ['firstName' => 'Jordan', 'lastName' => 'Rabeuf'],
            ['firstName' => 'Ethan', 'lastName' => 'Barale-Reghellin'],
            ['firstName' => 'Ambrine', 'lastName' => 'Azizi'],
            ['firstName' => 'Aela', 'lastName' => 'Desplanque'],
            ['firstName' => 'Charlie', 'lastName' => 'Lefort'],
            ['firstName' => 'Julia', 'lastName' => 'Patin'],
            // Ajoutés par le gestionnaire dans l'app le 2026-08-18 (relevé de la base réelle) :
            // le seed les ignorait, un environnement neuf repartait donc sans eux.
            ['firstName' => 'Alexis', 'lastName' => 'Kuriyan'],
            ['firstName' => 'Ambrine', 'lastName' => 'Hamani'],
            ['firstName' => 'Chaima', 'lastName' => 'Othmane'],
            ['firstName' => 'Joseph', 'lastName' => 'Enama'],
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

        // P2-4 PR 2bis — ANONYMISATION (profil démo) : remplacement POSITIONNEL des
        // identités de coachs, ICI et pas en post-passe. Tout ce qui se construit
        // plus bas lit l'ENTITÉ (« %s - Indisponible mercredi » via getFirstName())
        // et suit donc automatiquement — aucun libellé à réécrire après coup. Les
        // clés du tableau $coaches restent les noms du seed : c'est un index
        // interne au fichier, il ne sort jamais de cette méthode.
        if (null !== $profile->coachNames) {
            // STRICT : une liste fictive plus courte que le seed laisserait des vrais
            // noms à l'écran en silence — c'est précisément ce que le profil démo
            // existe pour empêcher. On refuse, on n'anonymise jamais « en partie ».
            if (\count($profile->coachNames) < \count($newCoachesData)) {
                throw new RuntimeException(\sprintf('Anonymisation incomplète : %d identités fictives pour %d coachs seedés.', \count($profile->coachNames), \count($newCoachesData)));
            }
            $position = 0;
            foreach ($newCoachesData as $coachData) {
                $key = '' !== $coachData['lastName'] ? $coachData['firstName'] . ' ' . $coachData['lastName'] : $coachData['firstName'];
                $replacement = $profile->coachNames[$position] ?? null;
                if (null !== $replacement && isset($coaches[$key])) {
                    $coaches[$key]->setFirstName($replacement['firstName']);
                    $coaches[$key]->setLastName($replacement['lastName']);
                }
                ++$position;
            }
            $manager->flush();
        }

        // Extract typed coach references for PHPStan level 8
        /** @var array<string, Coach> $coaches */
        $coachMaxime = $coaches['Maxime Dionnet'];
        $coachMara = $coaches['Mara'];
        $coachEmerick = $coaches['Emerick Creantor'];
        $coachNicoPatin = $coaches['Nico Patin'];
        $coachEnzo = $coaches['Enzo Camerino'];
        $coachThomas = $coaches['Thomas Francon'];
        $coachFlo = $coaches['Florian Tapaunat'];
        $coachChris = $coaches['Christophe Renaud'];
        $coachMarlon = $coaches['Marlon Depierre'];
        $coachLionel = $coaches['Lionel Lacroute'];
        $coachNicolasBarilleau = $coaches['Nicolas Barilleau'];
        $coachInes = $coaches['Ines'];
        $coachFlorian = $coaches['Florian'];
        $coachLuca = $coaches['Luca Blanchini'];
        $coachThalie = $coaches['Thalie Charpenet'];
        $coachJordan = $coaches['Jordan Rabeuf'];
        $coachEthan = $coaches['Ethan Barale-Reghellin'];
        $coachCyril = $coaches['Cyril Benveniste'];
        $coachMathis = $coaches['Mathis Bidaux'];
        $coachAnna = $coaches['Anna Textoris'];
        $coachAlexis = $coaches['Alexis Kuriyan'];
        $coachAmbrineHamani = $coaches['Ambrine Hamani'];
        $coachChaima = $coaches['Chaima Othmane'];
        $coachJoseph = $coaches['Joseph Enama'];
        $coachMaeleen = $coaches['Maeleen Creantor'];
        $coachPierreChauvin = $coaches['Pierre Chauvin'];
        $coachAmbrine = $coaches['Ambrine Azizi'];
        $coachAela = $coaches['Aela Desplanque'];
        $coachCharlie = $coaches['Charlie Lefort'];
        $coachJulia = $coaches['Julia Patin'];

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
            // Correction 2026-08-18 (fondateur) : Anna est sur U11M1, pas U11M2 — l'erreur
            // venait du seed lui-même, d'où deux coachs principaux sur U11M2 et U11M1 sans coach.
            ['coach' => $coachAnna, 'team' => $teams['U11M1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachJoseph, 'team' => $teams['U11M2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAlexis, 'team' => $teams['U18M2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAmbrineHamani, 'team' => $teams['Baby 1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachChaima, 'team' => $teams['Baby 2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachPierreChauvin, 'team' => $teams['U11F1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachMaeleen, 'team' => $teams['U11F2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachJordan, 'team' => $teams['U9M1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAmbrine, 'team' => $teams['U9M2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachAela, 'team' => $teams['U9F1'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachJulia, 'team' => $teams['U9F2'], 'role' => TeamCoachRole::MAIN],
            ['coach' => $coachCharlie, 'team' => $teams['U9F2'], 'role' => TeamCoachRole::MAIN],
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
        // Doctrine fondateur (2026-09-01, définitive) : un lien coach-joueur ne se coupe JAMAIS —
        // ni globalement, ni par plan. Quand le réel le contredit (reprises : Enzo, Emerick, Mara,
        // Thomas), le gestionnaire IMPOSE le créneau par RÉSERVATION : le verrou est souverain,
        // le moteur crie en diagnostic, et ce cri est pleinement assumé — « si ça crie, c'est que
        // j'ai fait une réservation, donc un geste volontaire ». Le planning transcrit pointé
        // s'affiche tel quel ; une régénération libre, elle, respecte les liens.
        /** @var list<array{coach: Coach, team: Team, active?: bool}> $newPlayerLinks */
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
                $membership->setIsActive($link['active'] ?? true);
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

        // Libellés EXACTEMENT comme le wizard les écrit (décision fondateur 2026-08-15 :
        // « on doit croire que la donnée vient de l'app »). Jours en toutes lettres
        // (`dayLabelLong`, front) ; nom de coach = « prénom nom » comme `coachName` du wizard.
        $dayLong = static fn (int $iso): string => ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][$iso];
        $coachDisplay = static fn (Coach $coach): string => trim($coach->getFirstName() . ' ' . $coach->getLastName());

        // Purge des contraintes renommées/retirées OU dont la config a changé à nom
        // constant (utile en mode append ; un rechargement complet truncate d'abord).
        // Sans ça, le helper name-keyed conserverait l'ancienne config (ex. EMB 18h00
        // au lieu de 17h30, Camus/SM4 en preferred/venueId au lieu de forcedVenueId).
        $staleNames = [
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
            // Alignement 2026-08-12 : renommées ou retirées de la base réelle.
            'SM2 - Évite le vendredi',            // → « SM2 · pas Ven », et devient HARD
            'Matéo - Préféré équipes régionales', // retirée (la base ne l'a plus)
            'U18F2 - Matéo préféré',              // U18F2/U18M2 ne préfèrent plus qu'Armand
            'U18M2 - Matéo préféré',
            // Renommage 2026-08-15 : noms alignés EXACTEMENT sur le wizard (« <cible> ·
            // <prédicat> », cible tag = « Groupe <libellé affiché> »). Anciens noms à purger
            // pour qu'un reseed en append ne garde pas la ligne d'avant (helper name-keyed).
            'Seniors en compétition - Début minimum 18h50',
            'Jeunes - Début maximum 19h50',
            'U15 - Fin 20h30 max (début max 19h00)',
            'U13 - Début après 17h00',
            'U13 - Début préféré avant 19h00',
            'Veterans - Vendredi uniquement',
            'Veterans - Début après 20h',
            'SM2 · pas Ven',
            'SF2 · pas Ven',
            'Compétition · pas samedi, dimanche',
            'Jean Vilar - Pas équipes féminines',
            'Groupe DEPARTEMENTAL · préfère Tonkin',
            'De Barros Annexe - Préféré équipes départementales',
            'De Barros Annexe - Préféré LOISIR_ADULTE',
            'De Barros Annexe - Préféré LOISIR_JEUNE',
            // Lot tags PR 4 : la règle des 18h50 ne vise plus le tag maison SENIOR_COMPETITION
            // (qui disparaît) mais la cible combinée « ADULTE sauf LOISIR_ADULTE ». Son nom
            // change → l'ancienne ligne doit partir pour qu'un reseed en append ne la garde pas.
            'Groupe SENIOR_COMPETITION · pas avant 18:50',
        ];
        // Renommage 2026-08-15 (suite) — anciens noms DYNAMIQUES (équipe/coach).
        foreach (['U15M2', 'U18M2', 'U21M2'] as $teamName) {
            $staleNames[] = $teamName . ' - Jean Vilar préféré';
        }
        foreach (['U18F2', 'U18M2'] as $teamName) {
            $staleNames[] = $teamName . ' - Armand préféré';
        }
        // Retrait fondateur 2026-08-15 : U18F2 ne préfère plus Armand (terrain réel = JDR mardi
        // + Tonkin mercredi, zéro Armand — la préférence contredisait le réel). Nom au format
        // wizard, à purger pour qu'un reseed en append ne garde pas la ligne d'avant.
        $staleNames[] = 'U18F2 · préfère ' . $venues['vArmand']->getName();
        foreach ([$coachLionel, $coachThomas, $coachEnzo, $coachJordan] as $coach) {
            $staleNames[] = \sprintf('%s - Indisponible le vendredi', $coach->getFirstName());
        }
        foreach ([$coachEmerick, $coachNicolasBarilleau] as $coach) {
            $staleNames[] = \sprintf('%s - Indisponible le jeudi', $coach->getFirstName());
        }
        // Ancienne indispo coach du jeudi retirée de la base (Nico Patin) — nom dérivé
        // de l'entité pour suivre l'anonymisation du profil démo.
        $staleNames[] = \sprintf('%s - Indisponible le jeudi', $coachNicoPatin->getFirstName());
        // « <équipe> - Pas d'entraînement le mercredi » : la base réelle n'en a aucune
        // (le CEC du mercredi a disparu, les jeunes s'entraînent ce jour-là).
        foreach (['U11F1', 'U11F2', 'U11M2', 'U9M1', 'U9M2', 'U9F1', 'U9F2'] as $teamName) {
            $staleNames[] = $teamName . ' - Pas d\'entraînement le mercredi';
        }
        // « Veterans - Interdit <venueId> » renommées « Veterans · évite <gymnase> ».
        foreach (['vCamus', 'vJdr', 'vJeanVilar', 'vTonkin', 'vAdn'] as $venueVar) {
            $staleNames[] = 'Veterans - Interdit ' . $venues[$venueVar]->getId();
        }
        // Interdit JDR supprimé de la base réelle (erreur de saisie du planning de saison,
        // décision fondateur 2026-09-02) : purgé des bases déjà seedées.
        $staleNames[] = 'Veterans · évite JDR';
        // « Jean Vilar préféré » n'existe plus que pour les équipes départementales -2.
        foreach ([$u15m1, $u18m1, $u21m1] as $droppedTeam) {
            $staleNames[] = $droppedTeam->getName() . ' - Jean Vilar préféré';
        }
        foreach ($staleNames as $retiredName) {
            $stale = $manager->getRepository(Constraint::class)->findOneBy(['clubId' => $club->getId(), 'name' => $retiredName]);
            if ($stale instanceof Constraint) {
                $manager->remove($stale);
            }
        }
        $manager->flush();

        // --- Tags MAISON retirés (lot tags PR 4) ------------------------------------------
        // COMPETITION et SENIOR_COMPETITION étaient deux tags MAISON créés en dépannage quand
        // une contrainte ne pouvait cibler QU'UN tag. Les cibles multiples existent désormais :
        //   • COMPETITION est devenu un tag SYSTÈME (dérivé du niveau non-loisir par
        //     TeamTagService::determineTagNames) — le sync d'équipe le crée et l'assigne aux 32
        //     équipes en compétition. Plus aucun geste maison à faire : le bloc de création a
        //     disparu, la règle du week-end continue de le cibler par son nom (désormais système).
        //   • SENIOR_COMPETITION disparaît : sa règle des 18h50 se ré-exprime en cible combinée
        //     « ADULTE sauf LOISIR_ADULTE » (voir plus bas). On purge donc le tag maison ET ses
        //     assignations, pour qu'un reseed en append ne laisse pas un tag orphelin en base.
        $seniorCompTag = $manager->getRepository(TeamTag::class)->findOneBy(['clubId' => $club->getId(), 'name' => 'SENIOR_COMPETITION']);
        if ($seniorCompTag instanceof TeamTag) {
            foreach ($manager->getRepository(TeamTagAssignment::class)->findBy(['tagId' => $seniorCompTag->getId()]) as $assignment) {
                $manager->remove($assignment);
            }
            $manager->remove($seniorCompTag);
            $manager->flush();
        }

        // --- TIME (heures de début, portée club par tranche d'âge) ---
        $addConstraint('Groupe EMB (U9-U11) · pas après 17:30', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['maxStartTime' => '17:30', 'targetTag' => 'EMB']);
        // La règle des 18h50 vise « les adultes EN COMPÉTITION » : Senior + U21, sans le Basket
        // Santé (senior d'âge mais LOISIR). Cible combinée « ADULTE sauf LOISIR_ADULTE » — prouvé
        // en PR 2 : résout exactement SM1-4, SF1-3, U21M1-2. (Ex-tag maison SENIOR_COMPETITION,
        // retiré en PR 4 : une contrainte peut désormais croiser cible et exclusion.)
        $addConstraint('Groupe Adulte (+ de 18) sauf Loisir adulte · pas avant 18:50', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['minStartTime' => '18:50', 'targetTags' => ['ADULTE'], 'excludeTags' => ['LOISIR_ADULTE']]);
        // Saisie PAR LE FONDATEUR dans l'UI le 2026-08-15 (13:00), portée ici le même jour :
        // elle n'existait qu'en base — donc perdue au prochain reseed, et absente pour tout
        // autre poste ou pour la CI. C'est le SENIOR d'âge (+22) EN COMPÉTITION qui ne commence
        // pas avant 20:00 — plus tard que le plancher 18:50 des adultes ci-dessus, qu'elle
        // resserre pour ce sous-groupe (les deux HARD coexistent : la plus stricte gagne).
        $addConstraint('Groupe Senior (+ de 22) + Compétition (hors loisir) · pas avant 20:00', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['minStartTime' => '20:00', 'targetTags' => ['SENIOR', 'COMPETITION']]);
        $addConstraint('Groupe Jeune (U13-U18) · pas après 19:50', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['maxStartTime' => '19:50', 'targetTag' => 'JEUNE']);
        // U15 : finir à 20h30 max ≈ début max 19h00 (séances ~90 min ; le modèle a maxStartTime, pas maxEndTime).
        $addConstraint('Groupe U15 · pas après 19:00', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['maxStartTime' => '19:00', 'targetTag' => 'U15']);
        $addConstraint('Groupe U13 · pas avant 17:00', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['minStartTime' => '17:00', 'targetTag' => 'U13']);
        // U13 : ne pas commencer après 19h00 (laisse la marge du vendredi 20h30, l'exception par-jour n'étant pas exprimable).
        $addConstraint('Groupe U13 · pas après 19:00', ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['maxStartTime' => '19:00', 'targetTag' => 'U13']);

        // --- DAY (jours imposés / interdits) ---
        // « uniquement » = allowedDays (whitelist : seul le vendredi permis). forcedDays
        // ne veut dire QUE « au moins une séance ce jour-là » côté engine (audit ENG-16).
        $addConstraint(\sprintf('Veterans · uniquement %s', $dayLong(5)), ConstraintScope::TEAM, $teams['Veterans']->getId(), ConstraintFamily::DAY, ConstraintRuleType::HARD, ['allowedDays' => [5]]);
        // Vétérans : créneau du soir imposé — début à 20h00 au plus tôt (réalité terrain :
        // ils passent après les jeunes/adultes, jamais avant 20h).
        $addConstraint('Veterans · pas avant 20:00', ConstraintScope::TEAM, $teams['Veterans']->getId(), ConstraintFamily::TIME, ConstraintRuleType::HARD, ['minStartTime' => '20:00']);
        // U21M2 : même plancher du soir (jeu Jean Vilar 20:30 + ven Debarros 20:30, réalité
        // terrain). La règle Senior+22 en compétition ci-dessus exclut les U21 (âge < 22),
        // d'où cette contrainte TEAM dédiée pour ce sous-groupe.
        $addConstraint('U21M2 · pas avant 20:00', ConstraintScope::TEAM, $u21m2->getId(), ConstraintFamily::TIME, ConstraintRuleType::HARD, ['minStartTime' => '20:00']);
        // SM2 / SF2 : pas de séance le vendredi (nom auto-généré par le wizard « … · pas vendredi »).
        $addConstraint(\sprintf('SM2 · pas %s', $dayLong(5)), ConstraintScope::TEAM, $teams['SM2']->getId(), ConstraintFamily::DAY, ConstraintRuleType::HARD, ['forbiddenDays' => [5]]);
        $addConstraint(\sprintf('SF2 · pas %s', $dayLong(5)), ConstraintScope::TEAM, $teams['SF2']->getId(), ConstraintFamily::DAY, ConstraintRuleType::HARD, ['forbiddenDays' => [5]]);
        // Ajoutées dans l'app par le gestionnaire le 2026-08-18 (relevé de la base réelle) :
        // SM1 ne s'entraîne que le mardi et le jeudi, l'école de basket du mercredi que le
        // mercredi. Même forme que « Veterans · uniquement vendredi » : une whitelist de jours
        // (`allowedDays`), donc le nom auto-généré par le wizard énumère les jours permis.
        $addConstraint(\sprintf('SM1 · uniquement %s, %s', $dayLong(2), $dayLong(4)), ConstraintScope::TEAM, $sm1->getId(), ConstraintFamily::DAY, ConstraintRuleType::HARD, ['allowedDays' => [2, 4]]);
        $addConstraint(\sprintf('Mercredi Shark U9-U11 · uniquement %s', $dayLong(3)), ConstraintScope::TEAM, $teams['Mercredi Shark U9-U11']->getId(), ConstraintFamily::DAY, ConstraintRuleType::HARD, ['allowedDays' => [3]]);

        // Aucune séance le week-end pour les équipes EN COMPÉTITION (samedi ET dimanche) —
        // décision fondateur 2026-08-12. Le builder éclate cette règle CLUB en une contrainte
        // par équipe ; les réservations HARD du samedi (académies, Baby) restent posées.
        $addConstraint(\sprintf('Groupe Compétition (hors loisir) · pas %s, %s', $dayLong(6), $dayLong(7)), ConstraintScope::CLUB, null, ConstraintFamily::DAY, ConstraintRuleType::HARD, ['forbiddenDays' => [6, 7], 'targetTag' => 'COMPETITION']);

        // --- FACILITY (gymnases imposés / interdits / préférés) ---
        $addConstraint('Groupe Femme · évite ' . $venues['vJeanVilar']->getName(), ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['forbiddenVenueId' => $venues['vJeanVilar']->getId(), 'targetTag' => 'FEMININE']);
        // Venue OBLIGATOIRE (HARD) = forcedVenueId : l'engine ne force la salle que
        // via forcedVenueId (ou preferredVenueId en HARD), jamais via un `venueId` —
        // clé qu'aucune branche du parseur ne lit (sinon contrainte silencieuse).
        $addConstraint('SM4 · impose ' . $venues['vJeanVilar']->getName(), ConstraintScope::TEAM, $sm4->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['forcedVenueId' => $venues['vJeanVilar']->getId()]);
        // SM2 : au moins une séance à Matéo (compte plancher minAtVenueId, toujours dur).
        $addConstraint('SM2 · au moins 1 à ' . $venues['vMateo']->getName(), ConstraintScope::TEAM, $teams['SM2']->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['minAtVenueId' => $venues['vMateo']->getId(), 'minAtVenueCount' => 1]);
        // Camus réservé EXCLUSIVEMENT aux Loisir 1/2/3 (HARD forcedVenueId, pas un simple nudge).
        foreach (['Loisir 1', 'Loisir 2', 'Loisir 3'] as $loisirName) {
            $addConstraint($loisirName . ' · impose ' . $venues['vCamus']->getName(), ConstraintScope::TEAM, $teams[$loisirName]->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['forcedVenueId' => $venues['vCamus']->getId()]);
        }
        // Veterans interdits sur Camus/Jean Vilar/Tonkin/ADN (nom auto « Veterans · évite <gymnase> »).
        // JDR retiré de la liste (décision fondateur 2026-09-02) : l'interdit était une erreur du
        // planning de saison — l'overlay Matéo place précisément Veterans à JDR le vendredi 20:30.
        foreach (['vCamus', 'vJeanVilar', 'vTonkin', 'vAdn'] as $venueVar) {
            $venue = $venues[$venueVar];
            $addConstraint('Veterans · évite ' . $venue->getName(), ConstraintScope::TEAM, $teams['Veterans']->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['forbiddenVenueId' => $venue->getId()]);
        }
        // Préférences de gymnase par niveau (portée club). Libellé du tag AFFICHÉ (« Départemental »,
        // « Loisir adulte/jeune ») exactement comme le sélecteur de cible du wizard.
        $addConstraint('Groupe Départemental · préfère ' . $venues['vTonkin']->getName(), ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['targetTag' => 'DEPARTEMENTAL', 'preferredVenueId' => $venues['vTonkin']->getId()]);
        $addConstraint('Groupe Départemental · préfère ' . $venues['vDebarrosAnnexe']->getName(), ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues['vDebarrosAnnexe']->getId(), 'targetTag' => 'DEPARTEMENTAL']);
        $loisirLabels = [TeamLevel::LOISIR_ADULTE->value => 'Loisir adulte', TeamLevel::LOISIR_JEUNE->value => 'Loisir jeune'];
        foreach ([TeamLevel::LOISIR_ADULTE, TeamLevel::LOISIR_JEUNE] as $loisirLevel) {
            $addConstraint('Groupe ' . $loisirLabels[$loisirLevel->value] . ' · préfère ' . $venues['vDebarrosAnnexe']->getName(), ConstraintScope::CLUB, null, ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues['vDebarrosAnnexe']->getId(), 'targetTag' => $loisirLevel->value]);
        }
        // Jean Vilar préféré pour les équipes départementales -2 (U15M2/U18M2/U21M2).
        foreach ([$u15m2, $u18m2, $u21m2] as $targetTeam) {
            $addConstraint($targetTeam->getName() . ' · préfère ' . $venues['vJeanVilar']->getName(), ConstraintScope::TEAM, $targetTeam->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues['vJeanVilar']->getId()]);
        }
        // U18M2 : Armand préféré. (U18F2 retirée le 2026-08-15 — terrain réel = JDR mardi +
        // Tonkin mercredi, zéro Armand ; voir la purge dans $staleNames.)
        $addConstraint('U18M2 · préfère ' . $venues['vArmand']->getName(), ConstraintScope::TEAM, $u18m2->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues['vArmand']->getId()]);

        // Préférences de gymnase saisies par le gestionnaire dans l'app le 2026-08-18 (relevé de
        // la base réelle). PREFERRED : le solveur s'y tient quand il peut, et signale l'écart
        // quand il ne peut pas — aucun risque d'infaisabilité, contrairement aux règles de jour.
        foreach ([['Baby 1', 'vMateo'], ['Baby 2', 'vMateo'], ['Section J.Macé', 'vMateo'], ['3x3', 'vAdn']] as [$teamName, $venueVar]) {
            $addConstraint($teamName . ' · préfère ' . $venues[$venueVar]->getName(), ConstraintScope::TEAM, $teams[$teamName]->getId(), ConstraintFamily::FACILITY, ConstraintRuleType::PREFERRED, ['preferredVenueId' => $venues[$venueVar]->getId()]);
        }

        // --- COACH_AVAILABILITY (indisponibilités ; 5 = vendredi, 4 = jeudi) ---
        // Variables coach déjà résolues (l.618+) : un coach manquant lève une erreur PHP au lieu de disparaître en silence.
        foreach ([[$coachLionel, 5], [$coachThomas, 5], [$coachEnzo, 5], [$coachJordan, 5], [$coachEmerick, 4], [$coachNicolasBarilleau, 4]] as [$coach, $day]) {
            // SEC-13 : la cible du coach est le SCOPE (3e argument), plus une clé du config —
            // `coachId` en doublon est refusé depuis la validation stricte, et un club seedé
            // qui le porte ferme le gate du récap (bouton « Continuer » gris, e2e bloquée).
            // Nom auto-généré par le wizard : « <coach> · indispo <jour> » (indisponibilité).
            $addConstraint(\sprintf('%s · indispo %s', $coachDisplay($coach), $dayLong($day)), ConstraintScope::COACH, $coach->getId(), ConstraintFamily::COACH_AVAILABILITY, ConstraintRuleType::HARD, ['unavailableDays' => [$day]]);
        }

        $manager->flush();

        // ============================================================
        // SECTION 10 — ADDITIONAL SLOT TEMPLATES
        // ============================================================

        // JDR Saturday — Academie hard-locked sessions
        $additionalSlots = [
            // SM1
            ['team' => $sm1, 'venue' => 'vMateo', 'day' => 2, 'startTime' => '20:45', 'duration' => 105, 'lock' => LockLevel::HARD],
            ['team' => $sm1, 'venue' => 'vMateo', 'day' => 4, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            // SM2
            ['team' => $sm2, 'venue' => 'vJdr', 'day' => 4, 'startTime' => '19:00', 'duration' => 90, 'lock' => LockLevel::HARD],
            // SF2
            ['team' => $sf2, 'venue' => 'vJdr', 'day' => 4, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
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
            // Mercredi jeunes — blocs de mutualisation socle (P2-51) : ADN 17:30 = CEC1
            // {U9F1/U9F2/U9M2}, Matéo 16:00 = CEC2 {U11F2/U9M1}, Matéo 17:30 = CEC3
            // {U11F1/U11M2}. Chaque case en cap 1 : le bloc y compte pour UN occupant, et
            // l'ensemble réservé égale EXACTEMENT ses membres (reservedSetMatchesABlock).
            ['team' => $teams['U9F1'], 'venue' => 'vAdn', 'day' => 3, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U9F2'], 'venue' => 'vAdn', 'day' => 3, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U9M2'], 'venue' => 'vAdn', 'day' => 3, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U11F2'], 'venue' => 'vMateo', 'day' => 3, 'startTime' => '16:00', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U9M1'], 'venue' => 'vMateo', 'day' => 3, 'startTime' => '16:00', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U11F1'], 'venue' => 'vMateo', 'day' => 3, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U11M2'], 'venue' => 'vMateo', 'day' => 3, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            // --- Réservations posées sur le terrain (état 2026-08-05) ---
            ['team' => $teams['Basket Santé'], 'venue' => 'vDebarrosAnnexe', 'day' => 3, 'startTime' => '10:45', 'duration' => 75, 'lock' => LockLevel::HARD],
            ['team' => $teams['Section J.Macé'], 'venue' => 'vMateo', 'day' => 1, 'startTime' => '16:00', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['Section J.Macé'], 'venue' => 'vMateo', 'day' => 4, 'startTime' => '16:00', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['Section J.Macé'], 'venue' => 'vMateo', 'day' => 5, 'startTime' => '16:00', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U18M Fays'], 'venue' => 'vDebarros', 'day' => 5, 'startTime' => '16:00', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U18F Fays'], 'venue' => 'vDebarros', 'day' => 4, 'startTime' => '16:00', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U13M1'], 'venue' => 'vArmand', 'day' => 1, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U13M2'], 'venue' => 'vArmand', 'day' => 1, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U13F1'], 'venue' => 'vArmand', 'day' => 3, 'startTime' => '14:00', 'duration' => 105, 'lock' => LockLevel::HARD],
            ['team' => $teams['U13F2'], 'venue' => 'vArmand', 'day' => 3, 'startTime' => '14:00', 'duration' => 105, 'lock' => LockLevel::HARD],
            ['team' => $teams['U13F2'], 'venue' => 'vJdr', 'day' => 2, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U13F3'], 'venue' => 'vJdr', 'day' => 2, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U18F3'], 'venue' => 'vJdr', 'day' => 2, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            ['team' => $teams['U18F3'], 'venue' => 'vArmand', 'day' => 5, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
            ['team' => $teams['U9F1'], 'venue' => 'vMateo', 'day' => 1, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U9F2'], 'venue' => 'vMateo', 'day' => 1, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U9M1'], 'venue' => 'vJdr', 'day' => 4, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            ['team' => $teams['U9M2'], 'venue' => 'vJdr', 'day' => 4, 'startTime' => '17:30', 'duration' => 90, 'lock' => LockLevel::HARD],
            // mercredi shark a mateo
            ['team' => $teams['Mercredi Shark U9-U11'], 'venue' => 'vMateo', 'day' => 3, 'startTime' => '09:30', 'duration' => 75, 'lock' => LockLevel::HARD],
            // --- Ancre saison (reconstruction du planning validé, 2026-08-14) ---
            // SM2 : sa 2e séance est un créneau fixe le lundi soir à Matéo (l'autre reste JDR jeu 19:00).
            ['team' => $teams['SM2'], 'venue' => 'vMateo', 'day' => 1, 'startTime' => '20:30', 'duration' => 120, 'lock' => LockLevel::HARD],
        ];

        // Les 4 règles de bien-être en PRÉFÉRÉ : l'état RÉEL du club (fondateur,
        // 2026-08-14) — son planning viole repos coach, enchaînements et âge
        // croissant en connaissance de cause (coach-joueurs, exceptions terrain).
        // Sans ces réglages, la transcription ci-dessus serait INFEASIBLE.
        foreach (ImplicitRuleKey::cases() as $ruleKey) {
            $existingSetting = $manager->getRepository(ImplicitRuleSetting::class)->findOneBy([
                'clubId' => $club->getId(),
                'seasonId' => $season->getId(),
                'ruleKey' => $ruleKey,
            ]);
            if (!$existingSetting instanceof ImplicitRuleSetting) {
                $setting = new ImplicitRuleSetting;
                $setting->setClubId($club->getId());
                $setting->setSeasonId($season->getId());
                $setting->setRuleKey($ruleKey);
                $setting->setIntensity(ImplicitRuleIntensity::PREFERRED);
                $manager->persist($setting);
            }
        }

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

        // ============================================================
        // SECTION 10b — PASSERELLES (P5-23) & MUTUALISATIONS SOCLE (P2-51)
        // ============================================================
        // Les 10 liens d'équipes réels et les 8 blocs de mutualisation de SAISON (socle,
        // schedulePlanId NULL). Données fondateur (session du 2026-08-31) : posées telles
        // quelles. Le MODÈLE s'enrichit — la transcription (90 placements) ne bouge pas.
        $this->seedTeamLinksAndSharedBlocks($manager, $season, $clubId, $teams);

        // ============================================================
        // SECTION 11 — TRANSCRIPTION DU PLANNING RÉEL (P5-17, profil dev)
        // ============================================================
        // Le plan SEASON pointe une version COMPLETED transcrivant, à la lettre, le
        // planning réel du club (samedi compris), sans jamais appeler le solveur. Démo et
        // charge restent avant première génération (drapeau à false).
        if ($profile->transcribeRealSchedule) {
            $this->pointSeasonPlanAtRealSchedule($manager, $season, $clubId, $teams, $venues, $additionalSlots);
        }

        // ============================================================
        // SECTION 12 — PLANS DE REPRISE (P5-13, profil dev)
        // ============================================================
        // Placée APRÈS la transcription de saison (le socle est pointé d'abord) : deux plans
        // de PÉRIODE (semaines 17 et 24 août) sous une mère « Vacances d'été » sans plan,
        // chacun né avec son plan, sa grille reconstruite, ses overrides et sa version pointée.
        if ($profile->seedReprisePeriods) {
            $this->seedReprisePeriods($manager, $club, $season, $clubId, $teams, $venues, $coaches);
        }

        // ============================================================
        // SECTION 13 — INCIDENT MATÉO (P5-13 « incident Matéo », profil dev)
        // ============================================================
        // L'état d'adaptation EN COURS du gestionnaire : l'incident de fermeture de Matéo (le
        // FAIT — entrée racine + datée `venue_closed`) et le plan d'ajustement qu'il a commencé
        // sur un segment de 3 semaines (réglages posés, NON validé, sans version). Placée après
        // les reprises : sa fenêtre (07-27/09) ne recoupe aucun autre plan du seed.
        if ($profile->seedMateoIncident) {
            $this->seedMateoIncident($manager, $club, $season, $clubId, $teams, $venues);
        }

        // ============================================================
        // SECTION 13bis — RÉPARTITION WE DES MATCHS (profil dev)
        // ============================================================
        // L'état terrain du week-end : fenêtres d'accès match des gymnases, habitudes de match des
        // équipes et créneaux de match partagés (rotations A/B). Placée AVANT la remise à zéro des
        // drapeaux de péremption (section 14) : ces trois entités sont écoutées par
        // ResourceChangeStaleScheduleListener — écrire APRÈS ferait naître les versions transcrites
        // « périmées ». Démo/charge restent sans donnée WE (drapeau à false).
        if ($profile->seedWeekendMatchLayout) {
            $this->seedWeekendMatchLayout($manager, $season, $clubId, $teams, $venues);
        }

        // ============================================================
        // SECTION 14 — LES VERSIONS TRANSCRITES NAISSENT FRAÎCHES
        // ============================================================
        // Le seed crée les versions transcrites (sections 11-12) PUIS continue d'insérer
        // (incident, liens, blocs…) : chaque écriture post-transcription déclenche les
        // écouteurs de péremption (Constraint/ResourceChangeStaleScheduleListener), et une
        // base FRAÎCHE naissait avec ses 3 plannings marqués « périmés » — faux par
        // construction, la transcription égale l'état FINAL seedé (défaut consigné au
        // programme plannings-bccl, §5). Dernier geste du run : remise à zéro des deux
        // drapeaux sur TOUTES les versions du club. En exploitation réelle rien ne repasse
        // par ici — les écouteurs gardent leur plein sens hors seed.
        $manager->flush();
        $manager->createQuery(
            'UPDATE ' . Schedule::class . ' s
             SET s.constraintsChangedSinceGeneration = false, s.resourcesChangedSinceGeneration = false
             WHERE s.schedulePlanId IN (SELECT sp.id FROM ' . SchedulePlan::class . ' sp WHERE sp.clubId = :clubId)',
        )->setParameter('clubId', $clubId)->execute();

        return $club;
    }

    /**
     * Répartition WE des matchs (données fondateur, xlsx importé le 2026-09-02) — l'état terrain du
     * week-end du club, en trois entités du module matchs (toutes saison-scopées, hors plan) :
     *
     *  1. 4 {@see VenueMatchWindow} — les fenêtres d'accès match des gymnases : Matéo sam 13:00→22:30
     *     et dim 09:00→18:30, Armand sam 10:45→21:00, Debarros sam 13:00→18:30. Cette table n'a
     *     AUCUNE unicité DB : idempotence par PURGE des fenêtres du club/saison puis recréation
     *     (patron des créneaux d'entraînement).
     *  2. 32 {@see TeamMatchHabit} — l'habitude de match (jour + heure de coup d'envoi + gymnase)
     *     de chaque équipe qui reçoit le WE. Find-or-create sur la clé unique (club, saison, équipe,
     *     jour) ; heure + gymnase réappliqués au re-run. Aucune équipe ne reçoit deux fois le même
     *     jour, donc une habitude par équipe.
     *  3. 8 {@see MatchSlotRotation} + 16 {@see MatchSlotRotationTeam} — les créneaux partagés A/B
     *     d'Armand et Debarros (mêmes heures en semaine A et B, deux équipes en alternance) :
     *     position 0 = équipe de la semaine A, 1 = semaine B. Matéo ne porte AUCUNE rotation (ses
     *     heures diffèrent d'une semaine à l'autre). Purge des membres puis des rotations du
     *     club/saison, puis recréation — sémantique du {@see MatchSlotRotationStateProcessor}.
     *
     * @param array<string, Team>  $teams
     * @param array<string, Venue> $venues
     */
    private function seedWeekendMatchLayout(EntityManagerInterface $manager, Season $season, string $clubId, array $teams, array $venues): void
    {
        $seasonId = $season->getId();

        // 1. Fenêtres d'accès match — purge du club/saison (table sans unicité DB) puis recréation.
        foreach ($manager->getRepository(VenueMatchWindow::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $existingWindow) {
            $manager->remove($existingWindow);
        }
        $manager->flush();

        // [gymnase, jour ISO, début, fin] — horaires exacts, aucun arrondi.
        /** @var list<array{string, int, string, string}> $windows */
        $windows = [
            ['vMateo', 6, '13:00', '22:30'],
            ['vMateo', 7, '09:00', '18:30'],
            ['vArmand', 6, '10:45', '21:00'],
            ['vDebarros', 6, '13:00', '18:30'],
        ];
        foreach ($windows as [$venueVar, $day, $start, $end]) {
            $window = new VenueMatchWindow;
            $window->setClubId($clubId);
            $window->setSeasonId($seasonId);
            $window->setVenueId($venues[$venueVar]->getId());
            $window->setDayOfWeek($day);
            $window->setStartTime(new DateTimeImmutable($start));
            $window->setEndTime(new DateTimeImmutable($end));
            $manager->persist($window);
        }
        $manager->flush();

        // 2. Habitudes de match — find-or-create sur (club, saison, équipe, jour), heure + gymnase
        // réappliqués. [équipe, jour ISO, coup d'envoi, gymnase].
        /** @var list<array{string, int, string, string}> $habits */
        $habits = [
            // Semaine A — samedi Matéo
            ['U13F1', 6, '13:00', 'vMateo'], ['U18F2', 6, '15:00', 'vMateo'], ['U13M1', 6, '17:00', 'vMateo'], ['U18M2', 6, '19:00', 'vMateo'],
            // Semaine A — samedi Armand
            ['U9F2', 6, '10:45', 'vArmand'], ['U9F1', 6, '12:15', 'vArmand'], ['U11F1', 6, '13:45', 'vArmand'], ['U11M2', 6, '15:30', 'vArmand'], ['U18F3', 6, '17:15', 'vArmand'],
            // Semaine A — samedi Debarros
            ['U13M2', 6, '13:00', 'vDebarros'], ['U13F3', 6, '15:00', 'vDebarros'], ['U15F3', 6, '17:00', 'vDebarros'],
            // Semaine A — dimanche Matéo
            ['SM3', 7, '10:00', 'vMateo'], ['U18M1', 7, '12:00', 'vMateo'], ['U18F1', 7, '14:15', 'vMateo'], ['SF3', 7, '16:30', 'vMateo'],
            // Semaine B — samedi Matéo
            ['U15F1', 6, '13:45', 'vMateo'], ['U15M1', 6, '16:00', 'vMateo'], ['SF1', 6, '18:30', 'vMateo'], ['SM1', 6, '20:45', 'vMateo'],
            // Semaine B — samedi Armand
            ['U9M1', 6, '10:45', 'vArmand'], ['U9M2', 6, '12:15', 'vArmand'], ['U11F2', 6, '13:45', 'vArmand'], ['U11M1', 6, '15:30', 'vArmand'], ['U21M2', 6, '17:15', 'vArmand'],
            // Semaine B — samedi Debarros
            ['U15M2', 6, '13:00', 'vDebarros'], ['U13F2', 6, '15:00', 'vDebarros'], ['U15F2', 6, '17:00', 'vDebarros'],
            // Semaine B — dimanche Matéo
            ['SM4', 7, '09:00', 'vMateo'], ['SF2', 7, '11:00', 'vMateo'], ['U21M1', 7, '13:15', 'vMateo'], ['SM2', 7, '15:30', 'vMateo'],
        ];
        foreach ($habits as [$teamName, $day, $kickoff, $venueVar]) {
            $teamId = $teams[$teamName]->getId();
            $venueId = $venues[$venueVar]->getId();
            $existing = $manager->getRepository(TeamMatchHabit::class)->findOneBy([
                'clubId' => $clubId,
                'seasonId' => $seasonId,
                'teamId' => $teamId,
                'dayOfWeek' => $day,
            ]);
            $habit = $existing instanceof TeamMatchHabit ? $existing : new TeamMatchHabit;
            if (!$existing instanceof TeamMatchHabit) {
                $habit->setClubId($clubId);
                $habit->setSeasonId($seasonId);
                $habit->setTeamId($teamId);
                $habit->setDayOfWeek($day);
                $manager->persist($habit);
            }
            $habit->setKickoffTime(new DateTimeImmutable($kickoff));
            $habit->setVenueId($venueId);
        }
        $manager->flush();

        // 3. Créneaux partagés A/B — purge des membres puis des rotations du club/saison, puis
        // recréation (sémantique du processor : pas de clé naturelle par composition).
        foreach ($manager->getRepository(MatchSlotRotation::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $existingRotation) {
            foreach ($manager->getRepository(MatchSlotRotationTeam::class)->findBy(['rotationId' => $existingRotation->getId()]) as $existingMember) {
                $manager->remove($existingMember);
            }
            $manager->remove($existingRotation);
        }
        $manager->flush();

        // [gymnase, jour ISO, coup d'envoi, [équipe semaine A, équipe semaine B]] — position 0 = A,
        // 1 = B. Matéo n'a aucune rotation (heures A ≠ B). Les heures d'une paire sont identiques
        // en A et B, ce qui EST le créneau physique partagé.
        /** @var list<array{string, int, string, array{string, string}}> $rotations */
        $rotations = [
            ['vArmand', 6, '10:45', ['U9F2', 'U9M1']],
            ['vArmand', 6, '12:15', ['U9F1', 'U9M2']],
            ['vArmand', 6, '13:45', ['U11F1', 'U11F2']],
            ['vArmand', 6, '15:30', ['U11M2', 'U11M1']],
            ['vArmand', 6, '17:15', ['U18F3', 'U21M2']],
            ['vDebarros', 6, '13:00', ['U13M2', 'U15M2']],
            ['vDebarros', 6, '15:00', ['U13F3', 'U13F2']],
            ['vDebarros', 6, '17:00', ['U15F3', 'U15F2']],
        ];
        foreach ($rotations as [$venueVar, $day, $kickoff, $memberNames]) {
            $rotation = new MatchSlotRotation;
            $rotation->setClubId($clubId);
            $rotation->setSeasonId($seasonId);
            $rotation->setVenueId($venues[$venueVar]->getId());
            $rotation->setDayOfWeek($day);
            $rotation->setKickoffTime(new DateTimeImmutable($kickoff));
            $manager->persist($rotation);

            foreach ($memberNames as $position => $memberName) {
                $member = new MatchSlotRotationTeam;
                $member->setClubId($clubId);
                $member->setSeasonId($seasonId);
                $member->setRotationId($rotation->getId());
                $member->setTeamId($teams[$memberName]->getId());
                $member->setPosition($position);
                $manager->persist($member);
            }
        }
        $manager->flush();
    }

    /**
     * P5-23 (passerelles) + P2-51 (mutualisations SOCLE) — les 10 liens d'équipes réels du BCCL
     * et ses 8 blocs de mutualisation de saison (schedulePlanId NULL). Données fondateur (session
     * du 2026-08-31) : posées à la lettre, sans interprétation.
     *
     * Passerelles : le club DÉCLARE des équipes qui « partagent des joueurs » ⇒
     * {@see TeamLinkType::NOT_SIMULTANEOUS} (le type de base de la passerelle, cf. docblock
     * {@see TeamLink}). Intensité côté entraînement laissée au défaut du modèle (PREFERRED,
     * fondateur non précisé). Find-or-create sur le couple normalisé (teamAId < teamBId),
     * l'unicité DB (club, saison, A, B) faisant la clé.
     *
     * Blocs socle : commonSessions = 1 chacun ; les membres sont EXACTEMENT l'ensemble réservé
     * sur la case physique (garde `reservedSetMatchesABlock` du rail unitaire), dont la capacité
     * est redescendue à 1 — le bloc y compte pour UN occupant (plus de palliatif). La garde Σ du
     * processor tient (pour chaque équipe, Σ des commonSessions de ses blocs socle ≤ ses
     * séances/semaine). Purge+recréation des blocs socle (pas de clé naturelle par composition ;
     * les blocs de PÉRIODE, ancrés à un plan, ne sont pas touchés).
     *
     * @param array<string, Team> $teams
     */
    private function seedTeamLinksAndSharedBlocks(EntityManagerInterface $manager, Season $season, string $clubId, array $teams): void
    {
        // --- 10 passerelles (find-or-create, couple normalisé A<B). ---
        /** @var list<array{string, string}> $links */
        $links = [
            ['SM1', 'SM2'], ['SM1', 'U21M1'], ['U18M1', 'U18M2'], ['U15M1', 'U15M2'],
            ['U13M1', 'U13M2'], ['SF1', 'SF2'], ['SF1', 'U18F1'], ['U18F2', 'U18F1'],
            ['U15F1', 'U15F2'], ['U13F1', 'U13F2'],
        ];
        foreach ($links as [$nameA, $nameB]) {
            $idA = $teams[$nameA]->getId();
            $idB = $teams[$nameB]->getId();
            [$teamAId, $teamBId] = $idA < $idB ? [$idA, $idB] : [$idB, $idA];
            $existing = $manager->getRepository(TeamLink::class)->findOneBy([
                'clubId' => $clubId,
                'seasonId' => $season->getId(),
                'teamAId' => $teamAId,
                'teamBId' => $teamBId,
            ]);
            if ($existing instanceof TeamLink) {
                continue;
            }
            $link = new TeamLink;
            $link->setClubId($clubId);
            $link->setSeasonId($season->getId());
            $link->setTeamAId($teamAId);
            $link->setTeamBId($teamBId);
            $link->setLinkType(TeamLinkType::NOT_SIMULTANEOUS);
            // trainingIntensity laissée au défaut PREFERRED (fondateur non précisé).
            $manager->persist($link);
        }
        $manager->flush();

        // --- 8 mutualisations SOCLE : purge des blocs de socle puis recréation. ---
        foreach ($manager->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $clubId, 'seasonId' => $season->getId(), 'schedulePlanId' => null]) as $existingBlock) {
            foreach ($manager->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $existingBlock->getId()]) as $existingMember) {
                $manager->remove($existingMember);
            }
            $manager->remove($existingBlock);
        }
        $manager->flush();

        /** @var list<list<string>> $blocks */
        $blocks = [
            ['U9M2', 'U9F1', 'U9F2'], // CEC1 — ADN mer 17:30
            ['U9M1', 'U11F2'],        // CEC2 — Matéo mer 16:00
            ['U11M2', 'U11F1'],       // CEC3 — Matéo mer 17:30
            ['U9F1', 'U9F2'],         // Matéo lun 17:30
            ['U13M1', 'U13M2'],       // Armand lun 17:30
            ['U13F2', 'U13F3'],       // JDR mar 17:30
            ['U13F1', 'U13F2'],       // Armand mer 14:00
            ['U9M1', 'U9M2'],         // JDR jeu 17:30
        ];
        foreach ($blocks as $members) {
            $block = new SharedTrainingBlock;
            $block->setClubId($clubId);
            $block->setSeasonId($season->getId());
            $block->setSchedulePlanId(null);
            $block->setCommonSessions(1);
            $manager->persist($block);
            foreach ($members as $teamName) {
                $member = new SharedTrainingBlockTeam;
                $member->setClubId($clubId);
                $member->setSeasonId($season->getId());
                $member->setSchedulePlanId(null);
                $member->setBlockId($block->getId());
                $member->setTeamId($teams[$teamName]->getId());
                $manager->persist($member);
            }
        }
        $manager->flush();
    }

    /**
     * Clé de placement d'un créneau (équipe:gymnase:jour:HH:MM) — même identité que
     * l'importer ({@see App\Service\ScheduleResultImporter::placementKey}), pour dériver
     * les verrous. Partagée par la transcription de saison (section 11) et les reprises (P5-13).
     */
    private function placementKey(string $teamId, string $venueId, int $day, string $start): string
    {
        return \sprintf('%s:%s:%d:%s', $teamId, $venueId, $day, substr($start, 0, 5));
    }

    /**
     * P5-17 — le plan SEASON pointe une version COMPLETED qui TRANSCRIT le planning réel
     * du club (business/5-donnees/plannings-bccl/planning-saison.txt), sans solveur. Rien
     * du seed n'est touché : on AJOUTE une version + son pointeur, par les primitives de
     * l'app (linkSchedule puis choose). Idempotent : find-or-create de la version par
     * (plan SEASON, provenance), et les créneaux sont réinsérés après la purge (l.853).
     *
     * @param array<string, Team>                                                                                 $teams
     * @param array<string, Venue>                                                                                $venues
     * @param list<array{team: Team, venue: string, day: int, startTime: string, duration: int, lock: LockLevel}> $reservations
     */
    private function pointSeasonPlanAtRealSchedule(
        EntityManagerInterface $manager,
        Season $season,
        string $clubId,
        array $teams,
        array $venues,
        array $reservations,
    ): void {
        // Clé de placement d'un créneau (équipe:gymnase:jour:HH:MM) — même identité que
        // l'importer (ScheduleResultImporter::placementKey), pour dériver les verrous.
        // Méthode partagée avec la section reprises (P5-13), promue en callable de première classe.
        $placementKey = $this->placementKey(...);

        // Placements des RÉSERVATIONS seedées : un créneau transcrit qui coïncide porte un
        // verrou HARD/RESERVATION (exactement ce qu'un import de résultat solveur produit) ;
        // tous les autres NONE/null.
        $reservationPlacements = [];
        foreach ($reservations as $reservation) {
            $reservationPlacements[$placementKey($reservation['team']->getId(), $venues[$reservation['venue']]->getId(), $reservation['day'], $reservation['startTime'])] = true;
        }

        // Transcription de business/5-donnees/plannings-bccl/planning-saison.txt, état au
        // 2026-08-17. [équipe, gymnase, jour ISO, 'HH:MM', durée]. Un créneau partagé
        // (« U9F1 + U9F2 ») donne UNE entrée PAR équipe (même gymnase/heure/durée) — le
        // regroupement d'affichage vient du groupLabel des VenueTrainingSlot, pas d'ici.
        // « Vétérans » du fichier = l'équipe seed « Veterans ». Lignes transcrites TELLES
        // QU'ÉCRITES (les « échangements de créneau » du fichier décrivent l'état corrigé).
        /** @var list<array{string, string, int, string, int}> $transcription */
        $transcription = [
            // LUNDI
            ['Section J.Macé', 'vMateo', 1, '16:00', 90],
            ['U9F1', 'vMateo', 1, '17:30', 90],
            ['U9F2', 'vMateo', 1, '17:30', 90],
            ['U15F1', 'vMateo', 1, '19:00', 90],
            ['SM2', 'vMateo', 1, '20:30', 120],
            ['U13M1', 'vArmand', 1, '17:30', 90],
            ['U13M2', 'vArmand', 1, '17:30', 90],
            ['U18M1', 'vArmand', 1, '19:00', 90],
            ['Training Individuel', 'vArmand', 1, '20:30', 120],
            ['U13F2', 'vTonkin', 1, '19:00', 90],
            ['U13F1', 'vDebarros', 1, '17:30', 90],
            ['U18F1', 'vDebarros', 1, '19:00', 90],
            ['SF3', 'vDebarrosAnnexe', 1, '20:30', 120],
            // MARDI
            ['U11M1', 'vArmand', 2, '17:30', 90],
            ['U18M2', 'vArmand', 2, '19:00', 90],
            ['U11F2', 'vDebarros', 2, '17:30', 90],
            ['U15F3', 'vDebarros', 2, '19:00', 90],
            ['SF1', 'vDebarros', 2, '20:30', 120],
            ['U15F2', 'vDebarrosAnnexe', 2, '19:00', 90],
            ['Loisir 1', 'vCamus', 2, '20:00', 150],
            ['U13F2', 'vJdr', 2, '17:30', 90],
            ['U13F3', 'vJdr', 2, '17:30', 90],
            ['U18F2', 'vJdr', 2, '19:00', 90],
            ['U18F3', 'vJdr', 2, '20:30', 120],
            ['U15M1', 'vMateo', 2, '17:30', 90],
            ['U21M1', 'vMateo', 2, '19:00', 105],
            ['SM1', 'vMateo', 2, '20:45', 105],
            ['U15M2', 'vJeanVilar', 2, '18:45', 105],
            ['SM4', 'vJeanVilar', 2, '20:30', 120],
            // MERCREDI
            ['U9F1', 'vAdn', 3, '17:30', 90],
            ['U9F2', 'vAdn', 3, '17:30', 90],
            ['U9M2', 'vAdn', 3, '17:30', 90],
            ['U18F1', 'vAdn', 3, '19:00', 90],
            ['Mercredi Shark U9-U11', 'vMateo', 3, '09:30', 75],
            ['U11F2', 'vMateo', 3, '16:00', 90],
            ['U9M1', 'vMateo', 3, '16:00', 90],
            ['U11F1', 'vMateo', 3, '17:30', 90],
            ['U11M2', 'vMateo', 3, '17:30', 90],
            ['U15F1', 'vMateo', 3, '19:00', 90],
            ['SF1', 'vMateo', 3, '20:30', 120],
            ['U13F1', 'vArmand', 3, '14:00', 105],
            ['U13F2', 'vArmand', 3, '14:00', 105],
            ['U13M1', 'vArmand', 3, '15:45', 90],
            ['U15M1', 'vArmand', 3, '17:15', 90],
            ['U18M1', 'vArmand', 3, '18:45', 90],
            ['SM3', 'vArmand', 3, '20:15', 135],
            ['U13F3', 'vTonkin', 3, '16:00', 90],
            ['U13M2', 'vTonkin', 3, '17:30', 90],
            ['U18F2', 'vTonkin', 3, '19:00', 90],
            ['SF2', 'vTonkin', 3, '20:30', 120],
            ['Basket Santé', 'vDebarrosAnnexe', 3, '10:45', 75],
            ['U11M1', 'vDebarrosAnnexe', 3, '17:30', 90],
            ['U15F3', 'vDebarrosAnnexe', 3, '19:00', 90],
            ['SF3', 'vDebarrosAnnexe', 3, '20:30', 120],
            ['3x3', 'vAdn', 3, '20:30', 120],
            // JEUDI
            ['U11M2', 'vArmand', 4, '17:30', 90],
            ['U18F Fays', 'vDebarros', 4, '16:00', 90],
            ['U15M1', 'vDebarros', 4, '17:30', 90],
            ['U21M1', 'vDebarros', 4, '19:00', 90],
            ['Loisir Feminine', 'vDebarros', 4, '20:30', 120],
            ['Loisir 3', 'vCamus', 4, '20:00', 150],
            ['U9M1', 'vJdr', 4, '17:30', 90],
            ['U9M2', 'vJdr', 4, '17:30', 90],
            ['SM2', 'vJdr', 4, '19:00', 90],
            ['SF2', 'vJdr', 4, '20:30', 120],
            ['Section J.Macé', 'vMateo', 4, '16:00', 90],
            ['U13F1', 'vMateo', 4, '17:30', 90],
            ['U18F1', 'vMateo', 4, '19:00', 90],
            ['SM1', 'vMateo', 4, '20:30', 120],
            ['U18M2', 'vJeanVilar', 4, '19:00', 90],
            ['U21M2', 'vJeanVilar', 4, '20:30', 120],
            // VENDREDI
            ['Section J.Macé', 'vMateo', 5, '16:00', 90],
            ['U13M1', 'vMateo', 5, '17:30', 90],
            ['U18M1', 'vMateo', 5, '19:00', 90],
            ['Veterans', 'vMateo', 5, '20:30', 120],
            ['U11F1', 'vArmand', 5, '17:30', 90],
            ['U15M2', 'vArmand', 5, '19:00', 90],
            ['U18F3', 'vArmand', 5, '20:30', 120],
            ['U18M Fays', 'vDebarros', 5, '16:00', 90],
            ['U13M2', 'vDebarros', 5, '17:30', 90],
            ['U15F1', 'vDebarros', 5, '19:00', 90],
            ['U21M2', 'vDebarros', 5, '20:30', 120],
            ['U15F2', 'vDebarrosAnnexe', 5, '19:00', 90],
            ['Loisir 2', 'vCamus', 5, '20:00', 150],
            // SAMEDI
            ['Micro Basket', 'vMateo', 6, '09:00', 45],
            ['Baby 1', 'vMateo', 6, '09:45', 60],
            ['Baby 2', 'vMateo', 6, '10:45', 60],
            ['Academie U9-U11', 'vJdr', 6, '09:00', 75],
            ['Academie U13-U15', 'vJdr', 6, '10:15', 75],
            ['Academie U18', 'vJdr', 6, '11:30', 75],
        ];

        // Find-or-create de la version transcrite : clé (plan SEASON, provenance) — idempotent.
        $seasonPlanId = $this->schedulePlanProvisioner->ensureSeasonPlan($season)->getId();
        $schedule = $manager->getRepository(Schedule::class)->findOneBy([
            'schedulePlanId' => $seasonPlanId,
            'solverVersion' => self::SEED_TRANSCRIPTION_MARKER,
        ]);
        if (!$schedule instanceof Schedule) {
            $schedule = new Schedule;
            $schedule->setClubId($clubId);
            $schedule->setSeasonId($season->getId());
            $schedule->setSchedulePlanId($seasonPlanId);
            $schedule->setName($this->schedulePlanProvisioner->versionNameFor($seasonPlanId));
            $schedule->setStatus(ScheduleStatus::COMPLETED);
            $schedule->setSolverVersion(self::SEED_TRANSCRIPTION_MARKER);
            $manager->persist($schedule);
            // Primitive de l'app : NUMÉROTE la version dans son plan (V1), sous verrou (idempotent).
            $this->schedulePlanProvisioner->linkSchedule($schedule);
        }

        // Snapshot = la STRUCTURE courante (même recette que GenerateScheduleHandler et
        // currentStructureHash), pour que « Régénérer » soit honnêtement grisé (structure
        // inchangée depuis la « génération »).
        $payload = $this->constraintBuilder->buildForClubSeason($clubId, $season->getId());
        $schedule->setSnapshotData($payload);
        $schedule->setSnapshotHash(hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR)));
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $manager->flush();

        // VALIDER = POINTER (primitive de l'app) : le plan SEASON nomme cette version sa choisie.
        $this->schedulePlanProvisioner->choose($schedule);

        // Les créneaux : coachId null ; verrou DÉRIVÉ des réservations seedées (HARD/RESERVATION
        // sur coïncidence, NONE/null sinon) — l'état exact qu'un import de résultat solveur pose.
        // La purge l.853 a déjà vidé les créneaux du club/saison : réinsertion franche, idempotente.
        foreach ($transcription as [$teamName, $venueVar, $day, $startTime, $duration]) {
            $team = $teams[$teamName];
            $venueId = $venues[$venueVar]->getId();
            $isReserved = isset($reservationPlacements[$placementKey($team->getId(), $venueId, $day, $startTime)]);

            $slot = new ScheduleSlotTemplate;
            $slot->setClubId($clubId);
            $slot->setSeasonId($season->getId());
            $slot->setScheduleId($schedule->getId());
            $slot->setTeamId($team->getId());
            $slot->setVenueId($venueId);
            $slot->setCoachId(null);
            $slot->setDayOfWeek($day);
            $slot->setStartTime(new DateTimeImmutable($startTime));
            $slot->setDurationMinutes($duration);
            $slot->setLockLevel($isReserved ? LockLevel::HARD : LockLevel::NONE);
            $slot->setLockOrigin($isReserved ? LockOrigin::RESERVATION : null);
            $manager->persist($slot);
        }
        $manager->flush();

        // Miroir de ScheduleResultImporter (remise à zéro des 3 marqueurs) : un résultat
        // fraîchement transcrit n'est pas périmé — sinon un 2e seed afficherait
        // « planning périmé » sans raison.
        $schedule->setManuallyEditedSinceGeneration(false);
        $schedule->setConstraintsChangedSinceGeneration(false);
        $schedule->setResourcesChangedSinceGeneration(false);
        $manager->flush();
    }

    /**
     * P5-13 — gestionnaires ADDITIONNELS d'un profil (Nicolas en dev) : chacun un couple
     * User (email vérifié, mot de passe hashé au seed) + ClubUser admin actif. Find-or-create
     * par email — un compte déjà présent n'est JAMAIS écrasé (mot de passe, nom conservés),
     * exactement comme le gestionnaire principal. `[]` (démo/charge) = no-op.
     */
    private function seedAdditionalManagers(EntityManagerInterface $manager, Club $club, BcclSeedProfile $profile): void
    {
        foreach ($profile->additionalManagers as $spec) {
            $existingUser = $manager->getRepository(User::class)->findOneBy(['email' => $spec['email']]);
            if ($existingUser instanceof User) {
                $user = $existingUser;
            } else {
                $user = new User;
                $user->setEmail($spec['email']);
                $user->setFirstName($spec['firstName']);
                $user->setLastName($spec['lastName']);
                // Pré-vérifié (la porte de login rejette emailVerifiedAt null), mot de passe
                // en clair hashé ici — patron exact du gestionnaire principal.
                $user->setEmailVerifiedAt(new DateTimeImmutable);
                $user->setPasswordHash($this->passwordHasher->hashPassword($user, $spec['password']));
                $manager->persist($user);
                $manager->flush();
            }

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
                $manager->flush();
            }
        }
    }

    /**
     * P5-13 — les deux plans de REPRISE (dev) : une mère « Vacances d'été » (entrée racine
     * PERIOD/holiday SANS plan, fenêtre du référentiel clampée à la saison) + deux
     * entrées-semaines sœurs, chacune née avec SON plan. Tout est find-or-create / reconstruit
     * à chaque run (idempotent). Décisions fondateur 2026-08-19.
     *
     * @param array<string, Team>  $teams
     * @param array<string, Venue> $venues
     * @param array<string, Coach> $coaches index « prénom nom » → coach (cible d'une genèse COACH)
     */
    private function seedReprisePeriods(EntityManagerInterface $manager, Club $club, Season $season, string $clubId, array $teams, array $venues, array $coaches): void
    {
        // Fenêtre de la mère : les vacances d'été, clampées à la saison (jamais hors de ses
        // bornes). La mère est une entrée RACINE sans plan — la garde d'unicité de fenêtre ne
        // la voit pas (elle ne joint que les entrées PORTANT un plan), et ses deux semaines
        // partagent sa racine, donc ne se heurtent ni à elle ni entre elles.
        $summerStart = new DateTimeImmutable('2026-07-15');
        $summerEnd = new DateTimeImmutable('2026-08-31');
        $motherStart = $season->getStartDate() > $summerStart ? $season->getStartDate() : $summerStart;
        $motherEnd = $season->getEndDate() < $summerEnd ? $season->getEndDate() : $summerEnd;

        // La mère « Vacances d'été » est un ANCRAGE des vacances scolaires d'été (isHolidayAnchor) :
        // le radar apparie ses cartes sur `schoolHolidayId`. Sans le lien, le cockpit affichait DEUX
        // « Vacances d'été » (le feed scolaire + cette entrée). On lit la vacance été de la ZONE du
        // club qui chevauche la fenêtre, et on prend SA fenêtre clampée à la saison (plutôt que des
        // dates en dur) — deux vacances été peuvent tomber dans une saison (juil. N et juil. N+1),
        // seule celle qui recouvre la fenêtre de reprise nous concerne.
        $summerHoliday = null;
        $zone = $club->getSchoolZone();
        if (null !== $zone && '' !== $zone) {
            foreach ($this->schoolHolidayRepository->findByZoneAndWindow($zone, $motherStart, $motherEnd) as $candidate) {
                if ('ete' === $candidate->getHolidayType()) {
                    $summerHoliday = $candidate;
                    break;
                }
            }
        }
        if (null !== $summerHoliday) {
            $motherStart = $summerHoliday->getStartDate() > $season->getStartDate() ? $summerHoliday->getStartDate() : $season->getStartDate();
            $motherEnd = $summerHoliday->getEndDate() < $season->getEndDate() ? $summerHoliday->getEndDate() : $season->getEndDate();
        }

        $mother = $manager->getRepository(CalendarEntry::class)->findOneBy([
            'clubId' => $clubId,
            'seasonId' => $season->getId(),
            'title' => 'Vacances d\'été',
            'parentEntryId' => null,
        ]);
        if (!$mother instanceof CalendarEntry) {
            $mother = new CalendarEntry;
            $mother->setClubId($clubId);
            $mother->setSeasonId($season->getId());
            $mother->setKind(CalendarEntryKind::PERIOD);
            $mother->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
            $mother->setTitle('Vacances d\'été');
            $mother->setStartDate($motherStart);
            $mother->setEndDate($motherEnd);
            $mother->setStatus(CalendarEntryStatus::ACTIVE);
            if (null !== $summerHoliday) {
                $mother->setSchoolHolidayId($summerHoliday->getId());
            }
            $manager->persist($mother);
            $manager->flush();
        } elseif (null !== $summerHoliday && null === $mother->getSchoolHolidayId()) {
            // Mère d'un seed antérieur, sans lien : on la raccroche (idempotent — au 2ᵉ run elle
            // est déjà liée, donc no-op).
            $mother->setSchoolHolidayId($summerHoliday->getId());
            $manager->flush();
        }

        foreach ($this->repriseWeeks() as $week) {
            $this->seedRepriseWeek($manager, $season, $clubId, $teams, $venues, $coaches, $mother->getId(), $week);
        }
    }

    /**
     * Une semaine de reprise : entrée-enfant + plan (renommé), grille du plan reconstruite,
     * overrides gymnase/équipe/contrainte, groupes de mutualisation, réservations, puis la
     * version transcrite pointée (validée). Tout idempotent.
     *
     * @param array<string, Team>                                                                                                                                                                                                                                                                                                                                                                                                                                                $teams
     * @param array<string, Venue>                                                                                                                                                                                                                                                                                                                                                                                                                                               $venues
     * @param array<string, Coach>                                                                                                                                                                                                                                                                                                                                                                                                                                               $coaches
     * @param array{title: string, monday: string, sunday: string, activeVenues: list<string>, sessions: list<array{string, string, int, string, int}>, reservations?: list<array{string, string, int, string, int}>, groups: list<array{teams: list<string>, k: int}>, deactivatedConstraints: list<string>, constraints?: list<array{name: string, scope: ConstraintScope, target: string, family: ConstraintFamily, rule: ConstraintRuleType, config: array<string, mixed>}>} $week
     */
    private function seedRepriseWeek(EntityManagerInterface $manager, Season $season, string $clubId, array $teams, array $venues, array $coaches, string $motherId, array $week): void
    {
        $monday = new DateTimeImmutable($week['monday']);
        $sunday = new DateTimeImmutable($week['sunday']);

        // --- Entrée-enfant (semaine), née de la mère ---
        $child = $manager->getRepository(CalendarEntry::class)->findOneBy([
            'clubId' => $clubId,
            'parentEntryId' => $motherId,
            'startDate' => $monday,
        ]);
        if (!$child instanceof CalendarEntry) {
            $child = new CalendarEntry;
            $child->setClubId($clubId);
            $child->setSeasonId($season->getId());
            $child->setKind(CalendarEntryKind::PERIOD);
            $child->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
            $child->setTitle($week['title']);
            $child->setStartDate($monday);
            $child->setEndDate($sunday);
            $child->setParentEntryId($motherId);
            $child->setStatus(CalendarEntryStatus::ACTIVE);
            $manager->persist($child);
            $manager->flush();
        }

        // --- Plan de la semaine (naissance seule : copie la grille de saison + les 4 règles
        // bien-être, ancre les réglages). Le plan naît NOMMÉ du titre de son entrée
        // (décision fondateur 2026-08-23) : `$week['title']` est déjà posé sur l'entrée
        // ci-dessus, donc plus de renommage post-naissance à simuler ici. ---
        $planId = $this->schedulePlanProvisioner->provisionPeriodPlan($child->getId());
        if (null === $planId) {
            throw new RuntimeException(\sprintf('La semaine de reprise « %s » (holiday) n\'a pas reçu de plan.', $week['title']));
        }

        // --- Grille du plan RECONSTRUITE À CHAQUE RUN. La purge des VenueTrainingSlot par
        // club/saison (section « VENUE TRAINING SLOTS ») emporte les copies de plan, et
        // provisionPeriodPlan ne re-copie qu'à la NAISSANCE : sans reconstruction, la grille
        // serait vide (2e run) ou aux horaires de saison (1er run). On purge la grille du plan
        // et on insère celle de la semaine — capacity = nombre d'OCCUPANTS du créneau : les
        // équipes d'un même groupe mutualisé (`$week['groups']`) comptent pour UN (le bloc est
        // un occupant unique depuis le modèle bloc) ; capacité 2 ne subsisterait que pour deux
        // occupants réellement distincts, cas absent des semaines transcrites. Les groupes des
        // reprises sont des paires disjointes — pas d'imbrication à arbitrer ici. ---
        foreach ($manager->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $planId]) as $planSlot) {
            $manager->remove($planSlot);
        }
        $manager->flush();
        $occupantLabel = [];
        foreach ($week['groups'] as $groupIndex => $group) {
            foreach ($group['teams'] as $memberName) {
                $occupantLabel[$memberName] = 'group#' . $groupIndex;
            }
        }
        /** @var array<string, array{venue: string, day: int, start: string, duration: int, occupants: array<string, true>}> $gridSlots */
        $gridSlots = [];
        foreach ($week['sessions'] as [$teamName, $venueVar, $day, $start, $duration]) {
            $key = $venueVar . '|' . $day . '|' . $start;
            if (!isset($gridSlots[$key])) {
                $gridSlots[$key] = ['venue' => $venueVar, 'day' => $day, 'start' => $start, 'duration' => $duration, 'occupants' => []];
            }
            $gridSlots[$key]['occupants'][$occupantLabel[$teamName] ?? $teamName] = true;
        }
        foreach ($gridSlots as $gridSlot) {
            $slot = new VenueTrainingSlot;
            $slot->setClubId($clubId);
            $slot->setSeasonId($season->getId());
            $slot->setVenueId($venues[$gridSlot['venue']]->getId());
            $slot->setDayOfWeek($gridSlot['day']);
            $slot->setStartTime(new DateTimeImmutable($gridSlot['start']));
            $slot->setDurationMinutes($gridSlot['duration']);
            $slot->setCapacity(\count($gridSlot['occupants']));
            $slot->setGroupLabel(null);
            $slot->setSchedulePlanId($planId);
            $manager->persist($slot);
        }
        $manager->flush();

        // --- Gymnases hors semaine : VenuePeriodOverride mode=DISABLED (find-or-create). ---
        foreach ($venues as $venueVar => $venue) {
            if (\in_array($venueVar, $week['activeVenues'], true)) {
                continue;
            }
            $override = $manager->getRepository(VenuePeriodOverride::class)->findOneBy([
                'schedulePlanId' => $planId,
                'venueId' => $venue->getId(),
            ]);
            if (!$override instanceof VenuePeriodOverride) {
                $override = new VenuePeriodOverride;
                $override->setClubId($clubId);
                $override->setSeasonId($season->getId());
                $override->setSchedulePlanId($planId);
                $override->setVenueId($venue->getId());
                $manager->persist($override);
            }
            $override->setMode(VenuePeriodMode::DISABLED);
        }
        $manager->flush();

        // --- Équipes : hors semaine → isActive=false ; en semaine → isActive=true +
        // sessionsPerWeek recalé (find-or-create). Puis le plan est marqué « sélection
        // d'équipes initialisée » pour que le wizard ne re-seede pas son défaut. ---
        $sessionsPerTeam = [];
        foreach ($week['sessions'] as [$teamName]) {
            $sessionsPerTeam[$teamName] = ($sessionsPerTeam[$teamName] ?? 0) + 1;
        }
        foreach ($teams as $teamName => $team) {
            $isActive = isset($sessionsPerTeam[$teamName]);
            $teamOverride = $manager->getRepository(TeamPeriodOverride::class)->findOneBy([
                'schedulePlanId' => $planId,
                'teamId' => $team->getId(),
            ]);
            if (!$teamOverride instanceof TeamPeriodOverride) {
                $teamOverride = new TeamPeriodOverride;
                $teamOverride->setClubId($clubId);
                $teamOverride->setSeasonId($season->getId());
                $teamOverride->setSchedulePlanId($planId);
                $teamOverride->setTeamId($team->getId());
                $manager->persist($teamOverride);
            }
            $teamOverride->setIsActive($isActive);
            $teamOverride->setSessionsPerWeek($isActive ? $sessionsPerTeam[$teamName] : null);
        }
        $manager->flush();
        $this->schedulePlanProvisioner->markPlanTeamSelectionInitialized($planId);

        // --- Mutualisation : blocs {équipes, commonSessions} ANCRÉS au plan. Purge + recréation
        // (les membres n'ont pas de clé naturelle par-composition, purger est le plus sûr). La
        // garde Σ (commonSessions ≤ séances/semaine effectives de chaque membre) est vérifiée
        // à la transcription : chaque bloc reste à la borne (SM1/SM2 = 3 séances pour K=3, SF1/SF2
        // = 2 pour K=2) — le processor la re-jugerait, le seeder l'écrit en direct. ---
        foreach ($manager->getRepository(SharedTrainingBlock::class)->findBy(['schedulePlanId' => $planId]) as $existingBlock) {
            foreach ($manager->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $existingBlock->getId()]) as $existingMember) {
                $manager->remove($existingMember);
            }
            $manager->remove($existingBlock);
        }
        $manager->flush();
        foreach ($week['groups'] as $group) {
            $sharedBlock = new SharedTrainingBlock;
            $sharedBlock->setClubId($clubId);
            $sharedBlock->setSeasonId($season->getId());
            $sharedBlock->setSchedulePlanId($planId);
            $sharedBlock->setCommonSessions($group['k']);
            $manager->persist($sharedBlock);
            foreach ($group['teams'] as $teamName) {
                $member = new SharedTrainingBlockTeam;
                $member->setClubId($clubId);
                $member->setSeasonId($season->getId());
                $member->setSchedulePlanId($planId);
                $member->setBlockId($sharedBlock->getId());
                $member->setTeamId($teams[$teamName]->getId());
                $manager->persist($member);
            }
        }
        $manager->flush();

        // --- Contraintes de saison contredites par le réel de la semaine : décochées PAR PLAN
        // (ConstraintPeriodOverride isActive=false ; la saison reste intacte). Le nom fait la
        // clé — une contrainte introuvable LÈVE (un décochage qui ne vise rien serait muet). ---
        foreach ($week['deactivatedConstraints'] as $constraintName) {
            $constraint = $manager->getRepository(Constraint::class)->findOneBy(['clubId' => $clubId, 'name' => $constraintName]);
            if (!$constraint instanceof Constraint) {
                throw new RuntimeException(\sprintf('Reprise « %s » : contrainte de saison « %s » introuvable (renommée ?) — le décochage ne vise rien.', $week['title'], $constraintName));
            }
            $constraintOverride = $manager->getRepository(ConstraintPeriodOverride::class)->findOneBy([
                'schedulePlanId' => $planId,
                'constraintId' => $constraint->getId(),
            ]);
            if (!$constraintOverride instanceof ConstraintPeriodOverride) {
                $constraintOverride = new ConstraintPeriodOverride;
                $constraintOverride->setClubId($clubId);
                $constraintOverride->setSeasonId($season->getId());
                $constraintOverride->setSchedulePlanId($planId);
                $constraintOverride->setConstraintId($constraint->getId());
                $manager->persist($constraintOverride);
            }
            $constraintOverride->setIsActive(false);
        }
        $manager->flush();

        // --- Contraintes de GENÈSE (P2-59) : pendues à l'entrée-ENFANT (calendarEntryId =
        // $child->getId()), elles ne valent que pour CE plan (le sélecteur d'un plan lit ses
        // genèses ∪ les faits de sa mère). Find-or-create idempotent — clé clubId +
        // calendarEntryId + name + scopeTargetId (deux genèses homonymes se distinguent par leur
        // cible, ex. le bloc SM mutualisé posé sur SM1 ET SM2). La cible dépend du scope : TEAM →
        // équipe nommée, COACH → coach indexé « prénom nom ». Un membre introuvable LÈVE (une
        // genèse qui ne vise rien serait muette). Libellé au format wizard « <cible> · <prédicat> ». ---
        foreach ($week['constraints'] ?? [] as $genesis) {
            $targetId = match ($genesis['scope']) {
                ConstraintScope::TEAM => ($teams[$genesis['target']] ?? throw new RuntimeException(\sprintf('Genèse « %s » : équipe « %s » introuvable.', $genesis['name'], $genesis['target'])))->getId(),
                ConstraintScope::COACH => ($coaches[$genesis['target']] ?? throw new RuntimeException(\sprintf('Genèse « %s » : coach « %s » introuvable.', $genesis['name'], $genesis['target'])))->getId(),
                default => throw new RuntimeException(\sprintf('Genèse « %s » : scope %s non géré par le seed.', $genesis['name'], $genesis['scope']->value)),
            };
            $genesisConstraint = $manager->getRepository(Constraint::class)->findOneBy([
                'clubId' => $clubId,
                'calendarEntryId' => $child->getId(),
                'name' => $genesis['name'],
                'scopeTargetId' => $targetId,
            ]);
            if (!$genesisConstraint instanceof Constraint) {
                $genesisConstraint = new Constraint;
                $genesisConstraint->setClubId($clubId);
                $genesisConstraint->setSeasonId($season->getId());
                $genesisConstraint->setCalendarEntryId($child->getId());
                $genesisConstraint->setScope($genesis['scope']);
                $genesisConstraint->setScopeTargetId($targetId);
                $genesisConstraint->setFamily($genesis['family']);
                $genesisConstraint->setRuleType($genesis['rule']);
                $genesisConstraint->setName($genesis['name']);
                $manager->persist($genesisConstraint);
            }
            // Genèse FACILITY : la config statique porte un `preferredVenueVar` (variable de
            // gymnase — repriseWeeks() n'a pas les ids), résolu ici depuis $venues en
            // `preferredVenueId`, comme le fait le wizard. Les autres familles passent la config
            // telle quelle.
            $config = $genesis['config'];
            $preferredVenueVar = $config['preferredVenueVar'] ?? null;
            if (\is_string($preferredVenueVar)) {
                $config['preferredVenueId'] = ($venues[$preferredVenueVar] ?? throw new RuntimeException(\sprintf('Genèse « %s » : gymnase « %s » introuvable.', $genesis['name'], $preferredVenueVar)))->getId();
                unset($config['preferredVenueVar']);
            }
            $genesisConstraint->setConfig($config);
            $genesisConstraint->setIsActive(true);
        }
        $manager->flush();

        // --- Réservations : clé PORTANT le schedulePlanId (une réservation de base, plan NULL,
        // ne doit pas être confondue avec celle d'une reprise). Par défaut une par séance-équipe
        // (transcription intégrale) ; une semaine peut déclarer `reservations` pour ne figer que
        // les créneaux CHOISIS (arbitrage fondateur 2026-09-01 sur la reprise du 17 : seuls les
        // blocs fanion sont réservés, le reste appartient au solveur + contraintes). PURGE avant
        // ré-insertion : une réservation retirée de la liste doit disparaître au re-run. ---
        $reservedRows = $week['reservations'] ?? $week['sessions'];
        foreach ($manager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $planId]) as $staleReservation) {
            $manager->remove($staleReservation);
        }
        $manager->flush();
        foreach ($reservedRows as [$teamName, $venueVar, $day, $start, $duration]) {
            $reservation = new Reservation;
            $reservation->setClubId($clubId);
            $reservation->setSeasonId($season->getId());
            $reservation->setSchedulePlanId($planId);
            $reservation->setTeamId($teams[$teamName]->getId());
            $reservation->setVenueId($venues[$venueVar]->getId());
            $reservation->setDayOfWeek($day);
            $reservation->setStartTime(new DateTimeImmutable($start));
            $reservation->setDurationMinutes($duration);
            $manager->persist($reservation);
        }
        $manager->flush();

        // --- Version transcrite + validation (pointeur). ---
        $this->pointPeriodPlanAtReprise($manager, $season, $clubId, $planId, $child, $week['sessions'], $teams, $venues);
    }

    /**
     * Le plan de PÉRIODE pointe une version COMPLETED transcrivant la semaine de reprise,
     * sans solveur — miroir période de {@see pointSeasonPlanAtRealSchedule}. Snapshot par
     * {@see ScheduleConstraintBuilder::buildForPeriodPlan} (overrides du plan compris), verrous
     * dérivés des réservations (toutes coïncident ⇒ HARD/RESERVATION), 3 marqueurs de péremption
     * remis à zéro. Idempotent (find-or-create de la version par plan+provenance ; les créneaux
     * de la version sont réinsérés après la purge club/saison de la section slot-templates).
     *
     * @param list<array{string, string, int, string, int}> $sessions
     * @param array<string, Team>                           $teams
     * @param array<string, Venue>                          $venues
     */
    private function pointPeriodPlanAtReprise(EntityManagerInterface $manager, Season $season, string $clubId, string $planId, CalendarEntry $child, array $sessions, array $teams, array $venues): void
    {
        // Réservations DE CE PLAN → clés de placement (dérivation des verrous, comme l'import).
        $reservationPlacements = [];
        foreach ($manager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $planId]) as $reservation) {
            $reservationPlacements[$this->placementKey($reservation->getTeamId(), $reservation->getVenueId(), $reservation->getDayOfWeek(), $reservation->getStartTime()->format('H:i'))] = true;
        }

        // Find-or-create de la version transcrite : clé (plan, provenance).
        $schedule = $manager->getRepository(Schedule::class)->findOneBy([
            'schedulePlanId' => $planId,
            'solverVersion' => self::SEED_TRANSCRIPTION_MARKER,
        ]);
        if (!$schedule instanceof Schedule) {
            $schedule = new Schedule;
            $schedule->setClubId($clubId);
            $schedule->setSeasonId($season->getId());
            $schedule->setSchedulePlanId($planId);
            $schedule->setName($this->schedulePlanProvisioner->versionNameFor($planId));
            $schedule->setStatus(ScheduleStatus::COMPLETED);
            $schedule->setSolverVersion(self::SEED_TRANSCRIPTION_MARKER);
            $manager->persist($schedule);
            $this->schedulePlanProvisioner->linkSchedule($schedule);
        }

        // Snapshot = la structure COURANTE du plan de période (scheduleId null = pré-génération,
        // les Reservation portées par le plan tiennent lieu de verrous durables) : « Régénérer »
        // honnêtement grisé, tant que rien n'a bougé.
        $payload = $this->constraintBuilder->buildForPeriodPlan($clubId, $season->getId(), $planId, $child);
        $schedule->setSnapshotData($payload);
        $schedule->setSnapshotHash(hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR)));
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $manager->flush();

        // VALIDER = POINTER : le plan de période nomme cette version sa choisie.
        $this->schedulePlanProvisioner->choose($schedule);

        // Créneaux de la version : coachId null ; verrou dérivé des réservations (toutes les
        // séances de reprise en portent une ⇒ HARD/RESERVATION). La purge club/saison de la
        // section slot-templates a déjà vidé les créneaux : réinsertion franche, idempotente.
        foreach ($sessions as [$teamName, $venueVar, $day, $start, $duration]) {
            $team = $teams[$teamName];
            $venueId = $venues[$venueVar]->getId();
            $isReserved = isset($reservationPlacements[$this->placementKey($team->getId(), $venueId, $day, $start)]);

            $slot = new ScheduleSlotTemplate;
            $slot->setClubId($clubId);
            $slot->setSeasonId($season->getId());
            $slot->setScheduleId($schedule->getId());
            $slot->setTeamId($team->getId());
            $slot->setVenueId($venueId);
            $slot->setCoachId(null);
            $slot->setDayOfWeek($day);
            $slot->setStartTime(new DateTimeImmutable($start));
            $slot->setDurationMinutes($duration);
            $slot->setLockLevel($isReserved ? LockLevel::HARD : LockLevel::NONE);
            $slot->setLockOrigin($isReserved ? LockOrigin::RESERVATION : null);
            $manager->persist($slot);
        }
        $manager->flush();

        // Miroir de ScheduleResultImporter : une version fraîchement transcrite n'est pas périmée.
        $schedule->setManuallyEditedSinceGeneration(false);
        $schedule->setConstraintsChangedSinceGeneration(false);
        $schedule->setResourcesChangedSinceGeneration(false);
        $manager->flush();
    }

    /**
     * P5-13 « incident Matéo » (arbitrage fondateur 2026-09-02) — le seed dev transcrit le PLANNING
     * d'overlay réel que le gestionnaire a construit face à un nouvel incident : Matéo indisponible
     * du 31/08 au 16/10. Le plan de FERMETURE naît DIRECTEMENT SUR LA RACINE (plus de segment
     * intermédiaire) et POINTE une version COMPLETED qui transcrit ce planning (8 gymnases, Matéo
     * ABSENT, samedi-dimanche compris) : 76 cases · 90 séances-équipe.
     *
     * Remplace l'ANCIEN incident « (travaux) » (racine sans plan + segment 07→27/09 né avec un plan
     * NON validé) : sur une base VIVANTE les deux ne doivent pas coexister — d'où la PURGE nominale
     * de l'ancien en tête, DE BOUT EN BOUT (le teardown canonique {@see OverlayManager::
     * deletePeriodPlanForEntry} : versions + grille + overrides + blocs hérités + réglages bien-être,
     * que la seule cascade FK laisserait derrière). Le reste est find-or-create / purge-recréation
     * (idempotent). La fermeture de Matéo agit par l'ÉTAT EFFECTIF (la datée `venue_closed`), jamais
     * par un VenuePeriodOverride.
     *
     * @param array<string, Team>  $teams
     * @param array<string, Venue> $venues
     */
    private function seedMateoIncident(EntityManagerInterface $manager, Club $club, Season $season, string $clubId, array $teams, array $venues): void
    {
        $mateo = $venues['vMateo'];

        // --- 1 · PURGE NOMINALE de l'ANCIEN incident « (travaux) » (vital sur base VIVANTE : pas de
        // coexistence — le club réel n'en porte qu'un). Sa racine portait sa datée `venue_closed` et
        // un segment-enfant (07→27/09) né avec son plan. On détruit le plan du segment DE BOUT EN
        // BOUT via le teardown canonique (versions + grille + overrides + blocs hérités + réglages
        // bien-être — la seule cascade FK laisserait blocs socle et copie bien-être derrière), puis
        // l'entrée-segment, la datée et la racine. Absent (2e run, ou club neuf) ⇒ no-op. ---
        $staleRoot = $manager->getRepository(CalendarEntry::class)->findOneBy([
            'clubId' => $clubId,
            'seasonId' => $season->getId(),
            'title' => 'Matéo indisponible (travaux)',
            'parentEntryId' => null,
        ]);
        if ($staleRoot instanceof CalendarEntry) {
            foreach ($manager->getRepository(CalendarEntry::class)->findBy(['clubId' => $clubId, 'parentEntryId' => $staleRoot->getId()]) as $staleSegment) {
                $this->overlayManager->deletePeriodPlanForEntry($staleSegment, force: true);
                $manager->remove($staleSegment);
            }
            foreach ($manager->getRepository(Constraint::class)->findBy(['clubId' => $clubId, 'calendarEntryId' => $staleRoot->getId()]) as $staleClosure) {
                $manager->remove($staleClosure);
            }
            $manager->remove($staleRoot);
            $manager->flush();
        }

        // --- 2 · La NOUVELLE racine `closure` « Matéo indisponible (incident) » 31/08→16/10. Le
        // titre PORTE sa fenêtre (convention `types-de-planning.md` — un titre de période nomme sa
        // fenêtre « — du … au … » ; le plan naît nommé du titre). Find-or-create. ---
        $incidentTitle = 'Matéo indisponible (incident) — du 31 août 2026 au 16 oct. 2026';
        $incident = $manager->getRepository(CalendarEntry::class)->findOneBy([
            'clubId' => $clubId,
            'seasonId' => $season->getId(),
            'title' => $incidentTitle,
            'parentEntryId' => null,
        ]);
        if (!$incident instanceof CalendarEntry) {
            $incident = new CalendarEntry;
            $incident->setClubId($clubId);
            $incident->setSeasonId($season->getId());
            $incident->setKind(CalendarEntryKind::PERIOD);
            $incident->setPeriodType(CalendarEntryPeriodType::CLOSURE);
            $incident->setTitle($incidentTitle);
            $incident->setStartDate(new DateTimeImmutable('2026-08-31'));
            $incident->setEndDate(new DateTimeImmutable('2026-10-16'));
            $incident->setStatus(CalendarEntryStatus::ACTIVE);
            $manager->persist($incident);
            $manager->flush();
        }

        // --- Sa datée `venue_closed` : FACILITY/HARD sur Matéo, rattachée à la racine et NOMMÉE du
        // titre de son entrée (convention `useCreateVenueClosure`). Find-or-create par (club, entrée). ---
        $closure = $manager->getRepository(Constraint::class)->findOneBy([
            'clubId' => $clubId,
            'calendarEntryId' => $incident->getId(),
        ]);
        if (!$closure instanceof Constraint) {
            $closure = new Constraint;
            $closure->setClubId($clubId);
            $closure->setSeasonId($season->getId());
            $closure->setScope(ConstraintScope::FACILITY);
            $closure->setScopeTargetId($mateo->getId());
            $closure->setFamily(ConstraintFamily::FACILITY);
            $closure->setRuleType(ConstraintRuleType::HARD);
            $closure->setName($incidentTitle);
            $closure->setConfig(['type' => 'venue_closed', 'startDate' => '2026-08-31', 'endDate' => '2026-10-16']);
            $closure->setCalendarEntryId($incident->getId());
            $closure->setIsActive(true);
            $manager->persist($closure);
            $manager->flush();
        }

        // --- 3 · Le plan naît SUR LA RACINE (geste « Adapter » — PeriodPlanBirthTest prouve le
        // chemin). Type CLOSURE ⇒ provisionPeriodPlan copie la grille de saison + les 8 blocs SOCLE
        // (D10bis) + les 4 règles bien-être. ---
        $planId = $this->schedulePlanProvisioner->provisionPeriodPlan($incident->getId());
        if (null === $planId) {
            throw new RuntimeException('L\'incident Matéo (racine) n\'a pas reçu de plan.');
        }

        $planning = $this->mateoIncidentPlanning();

        // --- 4 · Grille du plan RECONSTRUITE : purge + 76 cases dérivées du fichier. Capacité =
        // occupant-unique PAR ENSEMBLE EXACT (une case dont les équipes sont EXACTEMENT les membres
        // d'un bloc déclaré, ou une case mono-équipe, compte pour UN ; recouvrement PARTIEL avec un
        // bloc ⇒ on lève). Aucun créneau Matéo (le fichier n'en porte pas). ---
        foreach ($manager->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $planId]) as $planSlot) {
            $manager->remove($planSlot);
        }
        $manager->flush();
        $blocSets = array_map(fn (array $blocTeams): array => $this->normalizedTeamSet($blocTeams), $planning['blocs']);
        /** @var array<string, array{venue: string, day: int, start: string, duration: int, teams: array<string, true>}> $gridSlots */
        $gridSlots = [];
        foreach ($planning['sessions'] as [$teamName, $venueVar, $day, $start, $duration]) {
            $key = $venueVar . '|' . $day . '|' . $start;
            if (!isset($gridSlots[$key])) {
                $gridSlots[$key] = ['venue' => $venueVar, 'day' => $day, 'start' => $start, 'duration' => $duration, 'teams' => []];
            }
            $gridSlots[$key]['teams'][$teamName] = true;
        }
        foreach ($gridSlots as $gridSlot) {
            $slot = new VenueTrainingSlot;
            $slot->setClubId($clubId);
            $slot->setSeasonId($season->getId());
            $slot->setVenueId($venues[$gridSlot['venue']]->getId());
            $slot->setDayOfWeek($gridSlot['day']);
            $slot->setStartTime(new DateTimeImmutable($gridSlot['start']));
            $slot->setDurationMinutes($gridSlot['duration']);
            $slot->setCapacity($this->incidentCaseCapacity(array_keys($gridSlot['teams']), $blocSets));
            $slot->setGroupLabel(null);
            $slot->setSchedulePlanId($planId);
            $manager->persist($slot);
        }
        $manager->flush();

        // --- 5 · Réglages d'équipes (find-or-create par (plan, équipe)) : chaque équipe qui figure
        // au planning est active à son nombre de séances DÉRIVÉ ; les autres (ici « Training
        // Individuel » seule) sont décochées. 50 lignes = 49 actives + 1. Plan marqué « sélection
        // initialisée » (le wizard ne re-seede pas son défaut par-dessus). ---
        $sessionsPerTeam = [];
        foreach ($planning['sessions'] as [$teamName]) {
            $sessionsPerTeam[$teamName] = ($sessionsPerTeam[$teamName] ?? 0) + 1;
        }
        foreach ($teams as $teamName => $team) {
            $isActive = isset($sessionsPerTeam[$teamName]);
            $teamOverride = $manager->getRepository(TeamPeriodOverride::class)->findOneBy([
                'schedulePlanId' => $planId,
                'teamId' => $team->getId(),
            ]);
            if (!$teamOverride instanceof TeamPeriodOverride) {
                $teamOverride = new TeamPeriodOverride;
                $teamOverride->setClubId($clubId);
                $teamOverride->setSeasonId($season->getId());
                $teamOverride->setSchedulePlanId($planId);
                $teamOverride->setTeamId($team->getId());
                $manager->persist($teamOverride);
            }
            $teamOverride->setIsActive($isActive);
            $teamOverride->setSessionsPerWeek($isActive ? $sessionsPerTeam[$teamName] : null);
        }
        $manager->flush();
        $this->schedulePlanProvisioner->markPlanTeamSelectionInitialized($planId);

        // --- 6 · Mutualisation : le plan a hérité les 8 blocs SOCLE (D10bis) à sa naissance ; le
        // gestionnaire les garde TOUS À L'IDENTIQUE et en AJOUTE 5, soit les 13 ensembles réels.
        // Purge-puis-déclare est la mécanique idempotente (les membres n'ont pas de clé naturelle
        // par composition). Chaque bloc à commonSessions=1. La multi-appartenance est permise
        // (l'unicité DB porte sur (block_id, team_id), pas sur l'équipe seule). ---
        foreach ($manager->getRepository(SharedTrainingBlock::class)->findBy(['schedulePlanId' => $planId]) as $existingBlock) {
            foreach ($manager->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $existingBlock->getId()]) as $existingMember) {
                $manager->remove($existingMember);
            }
            $manager->remove($existingBlock);
        }
        $manager->flush();
        foreach ($planning['blocs'] as $blocTeams) {
            $sharedBlock = new SharedTrainingBlock;
            $sharedBlock->setClubId($clubId);
            $sharedBlock->setSeasonId($season->getId());
            $sharedBlock->setSchedulePlanId($planId);
            $sharedBlock->setCommonSessions(1);
            $manager->persist($sharedBlock);
            foreach ($blocTeams as $teamName) {
                $member = new SharedTrainingBlockTeam;
                $member->setClubId($clubId);
                $member->setSeasonId($season->getId());
                $member->setSchedulePlanId($planId);
                $member->setBlockId($sharedBlock->getId());
                $member->setTeamId($teams[$teamName]->getId());
                $manager->persist($member);
            }
        }
        $manager->flush();

        // --- 7 · Décochage : « SM2 · au moins 1 à Matéo » SEULE (Matéo est fermé). Le nom fait la
        // clé — introuvable ⇒ on LÈVE (un décochage qui ne vise rien serait muet). AUCUN autre
        // override de contrainte. ---
        $sm2AtMateo = 'SM2 · au moins 1 à ' . $mateo->getName();
        $constraint = $manager->getRepository(Constraint::class)->findOneBy(['clubId' => $clubId, 'name' => $sm2AtMateo]);
        if (!$constraint instanceof Constraint) {
            throw new RuntimeException(\sprintf('Incident Matéo : contrainte de saison « %s » introuvable (renommée ?) — le décochage ne vise rien.', $sm2AtMateo));
        }
        $constraintOverride = $manager->getRepository(ConstraintPeriodOverride::class)->findOneBy([
            'schedulePlanId' => $planId,
            'constraintId' => $constraint->getId(),
        ]);
        if (!$constraintOverride instanceof ConstraintPeriodOverride) {
            $constraintOverride = new ConstraintPeriodOverride;
            $constraintOverride->setClubId($clubId);
            $constraintOverride->setSeasonId($season->getId());
            $constraintOverride->setSchedulePlanId($planId);
            $constraintOverride->setConstraintId($constraint->getId());
            $manager->persist($constraintOverride);
        }
        $constraintOverride->setIsActive(false);
        $manager->flush();

        // --- 8 · ZÉRO réservation : purge de toute réservation résiduelle du plan, aucune insertion.
        // Les fanions viendront d'un exercice solveur ultérieur (arbitrage fondateur) : l'annotation
        // « (créneau réserver) » du fichier s'IGNORE ici. ---
        foreach ($manager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $planId]) as $staleReservation) {
            $manager->remove($staleReservation);
        }
        $manager->flush();

        // --- 9 · Version transcrite POINTÉE (COMPLETED, 90 créneaux LockLevel::NONE — aucune
        // réservation ⇒ aucun verrou). Réutilise le pointeur des reprises TEL QUEL : l'entrée qui
        // porte le plan est ici la RACINE. ---
        $this->pointPeriodPlanAtReprise($manager, $season, $clubId, $planId, $incident, $planning['sessions'], $teams, $venues);

        // VenuePeriodOverride : AUCUN, délibérément. Le défaut vivant dérive la fermeture de Matéo
        // depuis la datée `venue_closed` de l'incident (VenueClosureDays) — poser un override
        // serait doubler le mécanisme.
    }

    /**
     * Un ensemble d'équipes normalisé (dédupliqué, trié) — pour comparer une case du plan à un bloc
     * déclaré indépendamment de l'ordre de saisie.
     *
     * @param list<string> $teamNames
     *
     * @return list<string>
     */
    private function normalizedTeamSet(array $teamNames): array
    {
        $set = array_values(array_unique($teamNames));
        sort($set, \SORT_STRING);

        return $set;
    }

    /**
     * Capacité d'une case du plan d'incident : occupant-unique par ENSEMBLE EXACT. Une case
     * mono-équipe, ou dont les équipes sont EXACTEMENT les membres d'un bloc déclaré, compte pour UN
     * occupant (le bloc EST un occupant unique). Sinon la capacité serait le nombre d'équipes — mais
     * une intersection PARTIELLE avec un bloc (recouvrement sans égalité) trahit une transcription
     * fautive et LÈVE (défensif : sur les données réelles chaque case multi-équipes égale un bloc,
     * donc toutes les cases tombent à 1).
     *
     * @param list<string>       $caseTeams
     * @param list<list<string>> $blocSets  ensembles de blocs, déjà normalisés
     */
    private function incidentCaseCapacity(array $caseTeams, array $blocSets): int
    {
        $case = $this->normalizedTeamSet($caseTeams);
        if (\count($case) <= 1) {
            return 1;
        }
        foreach ($blocSets as $bloc) {
            if ($bloc === $case) {
                return 1;
            }
        }
        foreach ($blocSets as $bloc) {
            if ([] !== array_intersect($case, $bloc)) {
                throw new RuntimeException(\sprintf('Incident Matéo : la case {%s} recouvre partiellement un bloc déclaré sans l\'égaler — transcription à revoir.', implode(', ', $case)));
            }
        }

        return \count($case);
    }

    /**
     * Le PLANNING d'overlay de l'incident Matéo, EN DONNÉE (transcription VERBATIM de
     * business/5-donnees/plannings-bccl/planning-overlay-mateoindisponible.txt, 31/08→16/10).
     * Miroir de {@see repriseWeeks()} : la méthode dédiée DÉRIVE tout des séances (grille + capacité
     * occupant-unique, séances/semaine) et de la liste des blocs. Séances : [équipe, gymnase, jour
     * ISO, 'HH:MM', durée]. Noms normalisés vers le seed (« Section Jean Macé » → « Section J.Macé »,
     * « Micro basket » → « Micro Basket », « Basket santé » → « Basket Santé », « Véterans » →
     * « Veterans », « Loisir F » → « Loisir Feminine », « Mercredi Basket » → « Mercredi Shark
     * U9-U11 »). 76 cases · 90 séances-équipe · 8 gymnases (Matéo ABSENT) · samedi-dimanche compris.
     *
     * @return array{sessions: list<array{string, string, int, string, int}>, blocs: list<list<string>>}
     */
    private function mateoIncidentPlanning(): array
    {
        return [
            'sessions' => [
                // LUNDI (jour ISO 1)
                ['Section J.Macé', 'vArmand', 1, '16:00', 90],
                ['U13M1', 'vArmand', 1, '17:30', 90],
                ['U13M2', 'vArmand', 1, '17:30', 90],
                ['U18M1', 'vArmand', 1, '19:00', 90],
                ['U18F1', 'vArmand', 1, '19:00', 90],
                ['SM2', 'vArmand', 1, '20:30', 120],
                ['U13F2', 'vTonkin', 1, '19:00', 90],
                ['U9F1', 'vJdr', 1, '17:30', 90],
                ['U9F2', 'vJdr', 1, '17:30', 90],
                ['U15M1', 'vJdr', 1, '19:00', 90],
                ['U15F1', 'vJdr', 1, '19:00', 90],
                ['U21M1', 'vJdr', 1, '20:30', 120],
                ['U13F1', 'vDebarros', 1, '17:30', 90],
                ['U18F3', 'vDebarros', 1, '19:00', 90],
                ['SF3', 'vDebarrosAnnexe', 1, '20:30', 120],
                // MARDI (jour ISO 2)
                ['U15M2', 'vJeanVilar', 2, '18:45', 105],
                ['SM4', 'vJeanVilar', 2, '20:30', 120],
                ['U13F2', 'vArmand', 2, '17:30', 90],
                ['U13F3', 'vArmand', 2, '17:30', 90],
                ['U18M2', 'vArmand', 2, '19:00', 90],
                ['Loisir 1', 'vCamus', 2, '20:00', 150],
                ['U11M2', 'vJdr', 2, '17:30', 90],
                ['U11F2', 'vJdr', 2, '17:30', 90],
                ['U18F2', 'vJdr', 2, '19:00', 90],
                ['SM1', 'vJdr', 2, '20:30', 120],
                ['U13F1', 'vDebarros', 2, '17:30', 90],
                ['U18F1', 'vDebarros', 2, '19:00', 90],
                ['SF1', 'vDebarros', 2, '20:30', 120],
                // Écart assumé avec le fichier source (19:30/60) : erreur de saisie constatée par le
                // fondateur le 2026-09-02 — le créneau réel de l'Annexe est 19:00→20:30, comme au
                // socle de saison, et la règle « U15 · pas après 19:00 » reste donc honorée.
                ['U15F2', 'vDebarrosAnnexe', 2, '19:00', 90],
                ['U15F3', 'vDebarrosAnnexe', 2, '19:00', 90],
                // MERCREDI (jour ISO 3)
                ['U13F3', 'vTonkin', 3, '16:00', 90],
                ['U13M2', 'vTonkin', 3, '17:30', 90],
                ['U18F2', 'vTonkin', 3, '19:00', 90],
                ['SF2', 'vTonkin', 3, '20:30', 120],
                ['U13F1', 'vArmand', 3, '14:00', 105],
                ['U13F2', 'vArmand', 3, '14:00', 105],
                ['U13M1', 'vArmand', 3, '15:45', 90],
                ['U15M1', 'vArmand', 3, '17:15', 90],
                ['U18M1', 'vArmand', 3, '18:45', 90],
                ['SM3', 'vArmand', 3, '20:15', 135],
                ['U9M1', 'vJdr', 3, '16:00', 90],
                ['U11F2', 'vJdr', 3, '16:00', 90],
                ['U11F1', 'vJdr', 3, '17:30', 90],
                ['U11M2', 'vJdr', 3, '17:30', 90],
                ['U15F1', 'vJdr', 3, '19:00', 90],
                ['SF1', 'vJdr', 3, '20:30', 120],
                ['Mercredi Shark U9-U11', 'vDebarrosAnnexe', 3, '09:30', 75],
                ['Basket Santé', 'vDebarrosAnnexe', 3, '10:45', 75],
                ['U11M1', 'vDebarrosAnnexe', 3, '17:30', 90],
                ['U15F3', 'vDebarrosAnnexe', 3, '19:00', 90],
                ['SF3', 'vDebarrosAnnexe', 3, '20:30', 120],
                ['U9M2', 'vAdn', 3, '17:30', 90],
                ['U9F1', 'vAdn', 3, '17:30', 90],
                ['U9F2', 'vAdn', 3, '17:30', 90],
                ['U15F2', 'vAdn', 3, '19:00', 90],
                ['3x3', 'vAdn', 3, '20:30', 120],
                // JEUDI (jour ISO 4)
                ['U18M2', 'vJeanVilar', 4, '19:00', 90],
                ['U21M2', 'vJeanVilar', 4, '20:30', 120],
                ['Section J.Macé', 'vArmand', 4, '16:00', 90],
                ['U11M1', 'vArmand', 4, '17:30', 90],
                ['U9M1', 'vJdr', 4, '17:30', 90],
                ['U9M2', 'vJdr', 4, '17:30', 90],
                ['SM2', 'vJdr', 4, '19:00', 90],
                ['SM1', 'vJdr', 4, '20:30', 120],
                ['U18F Fays', 'vDebarros', 4, '16:00', 90],
                ['U15M1', 'vDebarros', 4, '17:30', 90],
                ['U21M1', 'vDebarros', 4, '19:00', 90],
                ['SF2', 'vDebarros', 4, '20:30', 120],
                ['Loisir 3', 'vCamus', 4, '20:00', 150],
                // VENDREDI (jour ISO 5)
                ['Section J.Macé', 'vArmand', 5, '16:00', 90],
                ['U11F1', 'vArmand', 5, '17:30', 90],
                ['U15M2', 'vArmand', 5, '19:00', 90],
                ['U18F3', 'vArmand', 5, '20:30', 120],
                ['U13M1', 'vJdr', 5, '17:30', 90],
                ['U18M1', 'vJdr', 5, '19:00', 90],
                ['Loisir Feminine', 'vJdr', 5, '20:30', 120],
                ['Veterans', 'vJdr', 5, '20:30', 120],
                ['U18M Fays', 'vDebarros', 5, '16:00', 90],
                ['U13M2', 'vDebarros', 5, '17:30', 90],
                ['U15F1', 'vDebarros', 5, '19:00', 90],
                ['U21M2', 'vDebarros', 5, '20:30', 120],
                ['U18F1', 'vDebarrosAnnexe', 5, '19:00', 90],
                ['Loisir 2', 'vCamus', 5, '20:00', 150],
                // SAMEDI (jour ISO 6)
                ['Basket Santé', 'vJdr', 6, '09:00', 75],
                ['Micro Basket', 'vArmand', 6, '09:00', 45],
                ['Baby 1', 'vArmand', 6, '09:45', 60],
                ['Baby 2', 'vArmand', 6, '10:45', 60],
                // DIMANCHE (jour ISO 7)
                ['Academie U9-U11', 'vAdn', 7, '09:00', 75],
                ['Academie U13-U15', 'vAdn', 7, '10:15', 75],
                ['Academie U18', 'vAdn', 7, '11:30', 75],
            ],
            // Les 13 ensembles mutualisés réels : les 8 SOCLE hérités (D10bis) + 5 AJOUTÉS par le
            // gestionnaire. Chaque case multi-équipes du planning ci-dessus égale EXACTEMENT l'un
            // d'eux (d'où les 76 cases toutes à capacité 1).
            'blocs' => [
                ['U13M1', 'U13M2'],
                ['U18M1', 'U18F1'],
                ['U9F1', 'U9F2'],
                ['U15M1', 'U15F1'],
                ['U13F2', 'U13F3'],
                ['U11M2', 'U11F2'],
                ['U15F2', 'U15F3'],
                ['U13F1', 'U13F2'],
                ['U9M1', 'U11F2'],
                ['U11F1', 'U11M2'],
                ['U9M2', 'U9F1', 'U9F2'],
                ['U9M1', 'U9M2'],
                ['Loisir Feminine', 'Veterans'],
            ],
        ];
    }

    /**
     * Les deux semaines de reprise, EN DONNÉE (transcription de
     * business/5-donnees/plannings-bccl/planning-reprise-{17,24}-aout.txt). Une 3ᵉ semaine
     * future = une entrée de plus ici, pas du code. Séances : [équipe, gymnase, jour ISO,
     * 'HH:MM', durée]. capacity de grille et sessionsPerWeek se DÉRIVENT des séances.
     *
     * @return list<array{title: string, monday: string, sunday: string, activeVenues: list<string>, sessions: list<array{string, string, int, string, int}>, reservations?: list<array{string, string, int, string, int}>, groups: list<array{teams: list<string>, k: int}>, deactivatedConstraints: list<string>, constraints?: list<array{name: string, scope: ConstraintScope, target: string, family: ConstraintFamily, rule: ConstraintRuleType, config: array<string, mixed>}>}>
     */
    private function repriseWeeks(): array
    {
        return [
            // ===================== SEMAINE DU 17 AOÛT — Armand seul =====================
            // 20 créneaux de 75 min, 25 séances-équipe, 11 équipes.
            [
                'title' => 'Reprise du 17 août',
                'monday' => '2026-08-17',
                'sunday' => '2026-08-23',
                'activeVenues' => ['vArmand'],
                'sessions' => [
                    // LUNDI
                    ['U13F1', 'vArmand', 1, '17:00', 75],
                    ['U18F1', 'vArmand', 1, '18:15', 75],
                    ['SM3', 'vArmand', 1, '19:30', 75],
                    ['SM1', 'vArmand', 1, '20:45', 75],
                    ['SM2', 'vArmand', 1, '20:45', 75],
                    // MARDI
                    ['U18M1', 'vArmand', 2, '17:00', 75],
                    ['U15M1', 'vArmand', 2, '18:15', 75],
                    ['U21M1', 'vArmand', 2, '19:30', 75],
                    ['SM1', 'vArmand', 2, '20:45', 75],
                    ['SM2', 'vArmand', 2, '20:45', 75],
                    // MERCREDI
                    ['U15F1', 'vArmand', 3, '17:00', 75],
                    ['U18F1', 'vArmand', 3, '18:15', 75],
                    ['SF1', 'vArmand', 3, '19:30', 75],
                    ['SF2', 'vArmand', 3, '19:30', 75],
                    ['SM3', 'vArmand', 3, '20:45', 75],
                    // JEUDI
                    ['U13F1', 'vArmand', 4, '17:00', 75],
                    ['U18M1', 'vArmand', 4, '18:15', 75],
                    ['U21M1', 'vArmand', 4, '19:30', 75],
                    ['SM1', 'vArmand', 4, '20:45', 75],
                    ['SM2', 'vArmand', 4, '20:45', 75],
                    // VENDREDI
                    ['U15M1', 'vArmand', 5, '17:00', 75],
                    ['U15F1', 'vArmand', 5, '18:15', 75],
                    ['SF1', 'vArmand', 5, '19:30', 75],
                    ['SF2', 'vArmand', 5, '19:30', 75],
                    ['SM3', 'vArmand', 5, '20:45', 75],
                ],
                'groups' => [
                    ['teams' => ['SM1', 'SM2'], 'k' => 3],
                    ['teams' => ['SF1', 'SF2'], 'k' => 2],
                ],
                // Contraintes de GENÈSE de CETTE semaine (P2-59, construites pendant l'exercice
                // solveur du 2026-09-01, validées fondateur) : elles pendent à l'entrée-enfant du
                // 17, invisibles de la semaine du 24. Le bloc SM mutualisé ne démarre pas avant
                // 20:30 (la transcription le pose à 20:45 ≥ 20:30, honorée), Nico Barilleau est
                // indispo lundi + vendredi (U18M1, son équipe, ne s'entraîne que mardi/jeudi).
                'constraints' => [
                    ['name' => 'Séniors masculins mutualisés · pas avant 20:30', 'scope' => ConstraintScope::TEAM, 'target' => 'SM1', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['minStartTime' => '20:30']],
                    ['name' => 'Séniors masculins mutualisés · pas avant 20:30', 'scope' => ConstraintScope::TEAM, 'target' => 'SM2', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['minStartTime' => '20:30']],
                    ['name' => 'Nicolas Barilleau · indispo lundi, vendredi', 'scope' => ConstraintScope::COACH, 'target' => 'Nicolas Barilleau', 'family' => ConstraintFamily::COACH_AVAILABILITY, 'rule' => ConstraintRuleType::HARD, 'config' => ['unavailableDays' => [1, 5]]],
                ],
                // Seuls les créneaux CHOISIS sont figés (équipes fanion — « on les impose au
                // modèle car ça nous arrange », exercice solveur du 2026-09-01) : les deux blocs
                // mutualisés. Le reste du planning appartient au solveur + contraintes — le
                // fondateur a explicitement refusé le tout-en-réservation.
                'reservations' => [
                    ['SM1', 'vArmand', 1, '20:45', 75],
                    ['SM2', 'vArmand', 1, '20:45', 75],
                    ['SM1', 'vArmand', 2, '20:45', 75],
                    ['SM2', 'vArmand', 2, '20:45', 75],
                    ['SM1', 'vArmand', 4, '20:45', 75],
                    ['SM2', 'vArmand', 4, '20:45', 75],
                    ['SF1', 'vArmand', 3, '19:30', 75],
                    ['SF2', 'vArmand', 3, '19:30', 75],
                    ['SF1', 'vArmand', 5, '19:30', 75],
                    ['SF2', 'vArmand', 5, '19:30', 75],
                ],
                // Règles de saison que le réel de la semaine contredit (vérifiées séance par séance) :
                //  - SM1 · uniquement mardi, jeudi : SM1 s'entraîne aussi le LUNDI.
                //  - Senior +22 Compétition ≥ 20:00 : SM3/SF1/SF2 à 19:30.
                //  - SF2 · pas vendredi : SF2 s'entraîne le vendredi.
                //  - SM2 · au moins 1 à Matéo : Matéo est fermé (0 séance possible à Matéo).
                //  - Nicolas Barilleau · indispo jeudi : U18M1 (son équipe) s'entraîne le JEUDI 18:15.
                //  - Thomas Francon · indispo vendredi : U15M1 (son équipe) s'entraîne le VENDREDI 17:00.
                //    (Les deux indispos sont des faits de SAISON hérités, pas de la semaine de reprise —
                //    arbitrage fondateur 2026-09-01, révélé par l'exercice solveur : sans décochage la
                //    génération ne peut pas atteindre le planning réel.)
                'deactivatedConstraints' => [
                    'SM1 · uniquement mardi, jeudi',
                    'Groupe Senior (+ de 22) + Compétition (hors loisir) · pas avant 20:00',
                    'SF2 · pas vendredi',
                    'SM2 · au moins 1 à Matéo',
                    'Nicolas Barilleau · indispo jeudi',
                    'Thomas Francon · indispo vendredi',
                    // Le bloc SM1+SM2 est figé lun/mar/jeu par réservation — la règle héritée
                    // « pas vendredi » n'a plus d'objet et gênerait le solveur pour rien.
                    'SM2 · pas vendredi',
                ],
            ],
            // ===================== SEMAINE DU 24 AOÛT — Armand + JDR =====================
            // Transcription de business/5-donnees/plannings-bccl/planning-reprise-24-aout.txt
            // (exercice solveur CLOS le 2026-09-01, gestes validés un à un en sandbox, chemin
            // manuel prouvé). 35 cases, 40 séances-équipe, 16 équipes.
            [
                'title' => 'Reprise du 24 août',
                'monday' => '2026-08-24',
                'sunday' => '2026-08-30',
                'activeVenues' => ['vArmand', 'vJdr'],
                'sessions' => [
                    // LUNDI
                    ['U13F1', 'vArmand', 1, '17:30', 90],
                    ['U21M1', 'vArmand', 1, '19:00', 90],
                    ['SM1', 'vArmand', 1, '20:30', 90],
                    ['SM2', 'vArmand', 1, '20:30', 90],
                    ['U15M1', 'vJdr', 1, '17:15', 60],
                    ['U15M2', 'vJdr', 1, '18:15', 75],
                    ['U18F1', 'vJdr', 1, '19:30', 75],
                    ['U18F2', 'vJdr', 1, '19:30', 75],
                    ['SF2', 'vJdr', 1, '20:45', 75],
                    // MARDI
                    ['U13M1', 'vArmand', 2, '17:30', 90],
                    ['U18M1', 'vArmand', 2, '19:00', 90],
                    ['SM1', 'vArmand', 2, '20:30', 90],
                    ['SM2', 'vArmand', 2, '20:30', 90],
                    ['U15M2', 'vJdr', 2, '17:15', 60],
                    ['U15F1', 'vJdr', 2, '18:15', 75],
                    ['SF1', 'vJdr', 2, '19:30', 75],
                    ['SF2', 'vJdr', 2, '20:45', 75],
                    // MERCREDI
                    ['U15M1', 'vArmand', 3, '17:30', 90],
                    ['U21M1', 'vArmand', 3, '19:00', 90],
                    ['SM3', 'vArmand', 3, '20:30', 90],
                    ['U15F1', 'vJdr', 3, '17:15', 60],
                    ['U18M1', 'vJdr', 3, '18:15', 75],
                    ['U18F2', 'vJdr', 3, '19:30', 75],
                    ['SF1', 'vJdr', 3, '20:45', 75],
                    // JEUDI — Armand 20:30 = SM2 SOLO, en plus du bloc SM à JDR 20:45 (même équipe
                    // deux fois le même soir, chevauchement ASSUMÉ par le fondateur ; les verrous
                    // et la transcription le portent).
                    ['U13F2', 'vArmand', 4, '17:30', 90],
                    ['U18M1', 'vArmand', 4, '19:00', 90],
                    ['SM2', 'vArmand', 4, '20:30', 90],
                    ['U13M1', 'vJdr', 4, '17:15', 60],
                    ['U13F1', 'vJdr', 4, '18:15', 75],
                    ['U18F1', 'vJdr', 4, '19:30', 75],
                    ['U18F2', 'vJdr', 4, '19:30', 75],
                    ['SM1', 'vJdr', 4, '20:45', 105],
                    ['SM2', 'vJdr', 4, '20:45', 105],
                    // VENDREDI
                    ['U15F2', 'vArmand', 5, '17:30', 90],
                    ['U15F1', 'vArmand', 5, '19:00', 90],
                    ['SM3', 'vArmand', 5, '20:30', 90],
                    ['U13F1', 'vJdr', 5, '17:15', 60],
                    ['U15M1', 'vJdr', 5, '18:15', 75],
                    ['U18F1', 'vJdr', 5, '19:30', 75],
                    ['U21M1', 'vJdr', 5, '20:45', 75],
                ],
                'groups' => [
                    // {SM1,SM2} reste mutualisé (Armand lun/mar 20:30, JDR jeu 20:45). {U18F1,U18F2}
                    // l'est aussi cette semaine (JDR lun/jeu 19:30). SF1/SF2 sont SÉPARÉES le 24.
                    ['teams' => ['SM1', 'SM2'], 'k' => 3],
                    ['teams' => ['U18F1', 'U18F2'], 'k' => 2],
                ],
                // Contraintes de GENÈSE de CETTE semaine (P2-59, construites pendant l'exercice
                // solveur du 2026-09-01), pendues à l'entrée-enfant du 24. Vérifiées à la main face
                // à la transcription : tous les mineurs commencent ≤ 19:30 (≤ 19:50 OK), U15M ≤
                // 18:15, SF1 ne s'entraîne pas vendredi. Les gymnases se résolvent depuis $venues
                // (preferredVenueVar → preferredVenueId dans seedRepriseWeek).
                'constraints' => [
                    // Mineurs (U13/U15/U18 actifs de la semaine) · pas après 19:50 — une par équipe.
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U13F1', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U13F2', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U13M1', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U15F1', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U15F2', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U15M1', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U15M2', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U18F1', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U18F2', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    ['name' => 'Mineurs · pas après 19:50', 'scope' => ConstraintScope::TEAM, 'target' => 'U18M1', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '19:50']],
                    // U15M · pas après 18:15 (les deux U15 masculins).
                    ['name' => 'U15M · pas après 18:15', 'scope' => ConstraintScope::TEAM, 'target' => 'U15M1', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '18:15']],
                    ['name' => 'U15M · pas après 18:15', 'scope' => ConstraintScope::TEAM, 'target' => 'U15M2', 'family' => ConstraintFamily::TIME, 'rule' => ConstraintRuleType::HARD, 'config' => ['maxStartTime' => '18:15']],
                    // U18F · gymnase préféré JDR (PREFERRED, une par équipe — libellé par équipe).
                    ['name' => 'U18F1 · préfère JDR', 'scope' => ConstraintScope::TEAM, 'target' => 'U18F1', 'family' => ConstraintFamily::FACILITY, 'rule' => ConstraintRuleType::PREFERRED, 'config' => ['preferredVenueVar' => 'vJdr']],
                    ['name' => 'U18F2 · préfère JDR', 'scope' => ConstraintScope::TEAM, 'target' => 'U18F2', 'family' => ConstraintFamily::FACILITY, 'rule' => ConstraintRuleType::PREFERRED, 'config' => ['preferredVenueVar' => 'vJdr']],
                    // SF1 · pas vendredi (SF1 ne s'entraîne pas le vendredi cette semaine).
                    ['name' => 'SF1 · pas vendredi', 'scope' => ConstraintScope::TEAM, 'target' => 'SF1', 'family' => ConstraintFamily::DAY, 'rule' => ConstraintRuleType::HARD, 'config' => ['forbiddenDays' => [5]]],
                    // SM3 · gymnase préféré Armand (PREFERRED).
                    ['name' => 'SM3 · préfère Armand', 'scope' => ConstraintScope::TEAM, 'target' => 'SM3', 'family' => ConstraintFamily::FACILITY, 'rule' => ConstraintRuleType::PREFERRED, 'config' => ['preferredVenueVar' => 'vArmand']],
                ],
                // Seuls les créneaux CHOISIS sont figés (blocs fanion — « on les impose au modèle »,
                // exercice solveur du 2026-09-01) : le bloc SM (lun/mar Armand 20:30 + jeu JDR
                // 20:45), la séance SOLO de SM2 jeu Armand 20:30, et les deux SF2 (lun/mar JDR
                // 20:45). Le reste appartient au solveur + contraintes — 9 lignes exactement.
                'reservations' => [
                    ['SM1', 'vArmand', 1, '20:30', 90],
                    ['SM2', 'vArmand', 1, '20:30', 90],
                    ['SM1', 'vArmand', 2, '20:30', 90],
                    ['SM2', 'vArmand', 2, '20:30', 90],
                    ['SM1', 'vJdr', 4, '20:45', 105],
                    ['SM2', 'vJdr', 4, '20:45', 105],
                    ['SM2', 'vArmand', 4, '20:30', 90],
                    ['SF2', 'vJdr', 1, '20:45', 75],
                    ['SF2', 'vJdr', 2, '20:45', 75],
                ],
                // Règles de saison relâchées pour la semaine (le socle reste intact, seul CE plan
                // les décoche) :
                //  - SM1 · uniquement mardi, jeudi : SM1 s'entraîne aussi le LUNDI.
                //  - Senior +22 Compétition ≥ 20:00 : SF1 à 19:30 (mardi/mercredi).
                //  - Groupe U15 · pas après 19:00 : relâchée (la genèse « U15M · pas après 18:15 »
                //    reprend la main sur les U15 masculins ; les U15 féminines sont laissées libres).
                //  - Groupe Jeune (U13-U18) · pas après 19:50 : remplacée par la genèse « Mineurs ».
                //  - SM2 · au moins 1 à Matéo : Matéo est fermé.
                //  - Indisponibilités coach de SAISON que le réel contredit (arbitrage fondateur
                //    2026-09-01) : Nicolas Barilleau indispo jeudi (U18M1 s'entraîne jeudi), Enzo
                //    Camerino indispo vendredi (U13F1/U18F1 vendredi), Thomas Francon indispo
                //    vendredi (U15M1/U21M1 vendredi).
                'deactivatedConstraints' => [
                    'SM1 · uniquement mardi, jeudi',
                    'Groupe Senior (+ de 22) + Compétition (hors loisir) · pas avant 20:00',
                    'Groupe U15 · pas après 19:00',
                    'Groupe Jeune (U13-U18) · pas après 19:50',
                    'SM2 · au moins 1 à Matéo',
                    'Nicolas Barilleau · indispo jeudi',
                    'Enzo Camerino · indispo vendredi',
                    'Thomas Francon · indispo vendredi',
                ],
            ],
        ];
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
