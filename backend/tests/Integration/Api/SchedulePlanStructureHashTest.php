<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
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
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
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
 * NR — `GET /api/schedule_plans/{id}/structure-hash` (axe §7.1 : planning lifecycle).
 *
 * LA promesse du signal « structure modifiée » : le hash servi PAR PLAN vaut EXACTEMENT le
 * `snapshotHash` qu'une version fraîchement née pose. Si la recette divergeait du snapshot, le
 * signal mentirait en permanence (bouton « Régénérer » grisé à tort, ou jamais). On le prouve dans
 * les DEUX régimes — SEASON (généré) et PÉRIODE (transcrit, le plus important) — puis on falsifie :
 *   (a) hash servi == snapshotHash de la version née — SEASON ET PÉRIODE ;
 *   (b) il DIVERGE après un changement de ressource (gymnase) ET après un changement de contrainte ;
 *   (c) PORTÉE PAR PLAN (ADR-0002, grille copiée) : toucher la grille SAISON ne bouge pas le hash
 *       d'une période ;
 *   (d) 404 pour le plan d'un AUTRE club (jamais un oracle d'existence) ;
 *   (e) `null` propre — jamais une 500 — quand la structure ne peut pas être bâtie ;
 *   (f) la ressource plan ne sert PLUS de bloc `staleness` (P4-266 : la péremption se dérive du hash).
 *
 * Références de recette (citées, pas devinées) :
 *  - SEASON : `GenerateScheduleHandler` hache l'entrée du solveur AVANT les greffes de convergence
 *    (`buildFrozenSnapshot` = `ScheduleConstraintBuilder::buildForClubSeason`) ;
 *  - PÉRIODE : `PeriodPlanTranscriber::copyFromSocle` pose
 *    `snapshotHash = sha256(json_encode(buildForPeriodPlan(...)))`.
 */
#[Group('phase1')]
#[Group('integration')]
final class SchedulePlanStructureHashTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    private SportCategory $category;

    /** (a) SEASON : le hash servi == le snapshotHash d'une version de socle fraîchement générée. */
    public function testSeasonHashEqualsAFreshlyGeneratedVersionSnapshot(): void
    {
        [$user, $club, $season] = $this->seedClub('SEASON');
        $this->team($club, $season, 'U11');
        $this->venue($club, $season, 'Gym');

        // Le rail de génération pose `snapshotHash = sha256(json_encode(buildForClubSeason(...)))`
        // (hash AVANT les greffes de convergence) — on reproduit CETTE recette sur une version.
        $version = $this->seasonVersionWithRailSnapshot($club, $season);

        $served = $this->hashOf($user, $season, $this->seasonPlanIdOf($season));
        self::assertNotNull($served, 'une saison avec structure doit servir un hash');
        self::assertSame($version->getSnapshotHash(), $served, 'SEASON : le hash servi == le snapshotHash de la version générée');
    }

    /**
     * (a) PÉRIODE — LE test le plus important : le hash servi == le snapshotHash de la V1 TRANSCRITE,
     * via la VRAIE route de transcription (pas de moteur : la transcription est pure).
     */
    public function testPeriodHashEqualsAFreshlyTranscribedVersionSnapshot(): void
    {
        [$user, $club, $season] = $this->seedClub('PERIOD');
        $team = $this->team($club, $season, 'U13');
        $venue = $this->venue($club, $season, 'Gym');

        $socle = $this->socleVersion($club, $season);
        $this->socleSlot($socle, $team, $venue, 1, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle); // VALIDER = POINTER le socle.

        $entry = $this->period($club, $season);
        $planId = $this->planIdOf($entry);

        // Transcription RÉELLE : la V1 naît avec son snapshotHash.
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/transcribe-from-socle', [], [], $this->headers($user, $season));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $scheduleId = (string) json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $transcribed = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $transcribed);
        $snapshotHash = $transcribed->getSnapshotHash();
        self::assertNotNull($snapshotHash, 'la version transcrite porte un snapshotHash');

        $served = $this->hashOf($user, $season, $planId);
        self::assertSame($snapshotHash, $served, 'PÉRIODE : le hash servi == le snapshotHash de la version transcrite');
    }

    /** (b) Le hash SEASON diverge après un changement de ressource (gymnase) ET de contrainte. */
    public function testSeasonHashDivergesOnResourceAndConstraintChange(): void
    {
        [$user, $club, $season] = $this->seedClub('DIVERGE');
        $this->team($club, $season, 'U15');
        $this->venue($club, $season, 'Gym A');
        $planId = $this->seasonPlanIdOf($season);

        $h0 = $this->hashOf($user, $season, $planId);
        self::assertNotNull($h0);

        // Ressource : un gymnase de plus.
        $this->venue($club, $season, 'Gym B');
        $this->invalidateSeasonCache($club, $season);
        $h1 = $this->hashOf($user, $season, $planId);
        self::assertNotSame($h0, $h1, 'un gymnase ajouté doit faire bouger le hash');

        // Contrainte : une contrainte permanente de plus.
        $constraint = (new Constraint)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Pas le lundi')
            ->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::TIME)
            ->setRuleType(ConstraintRuleType::HARD)->setCalendarEntryId(null);
        $this->em->persist($constraint);
        $this->em->flush();
        $this->invalidateSeasonCache($club, $season);
        $h2 = $this->hashOf($user, $season, $planId);
        self::assertNotSame($h1, $h2, 'une contrainte ajoutée doit faire bouger le hash');
    }

    /**
     * (c) PORTÉE PAR PLAN (ADR-0002) : la grille d'une période est une COPIE prise à la naissance.
     * Ajouter un créneau à la grille SAISON (schedule_plan_id NULL) bouge le hash SAISON mais
     * JAMAIS celui d'une période — le payload de période ne lit que SA propre grille.
     */
    public function testEditingTheSeasonGridNeverMovesAPeriodHash(): void
    {
        [$user, $club, $season] = $this->seedClub('SCOPE');
        $team = $this->team($club, $season, 'U17');
        $venue = $this->venue($club, $season, 'Gym');

        $socle = $this->socleVersion($club, $season);
        $this->socleSlot($socle, $team, $venue, 1, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $entry = $this->period($club, $season);
        $planId = $this->planIdOf($entry);
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/transcribe-from-socle', [], [], $this->headers($user, $season));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $seasonPlanId = $this->seasonPlanIdOf($season);
        $seasonBefore = $this->hashOf($user, $season, $seasonPlanId);
        $periodBefore = $this->hashOf($user, $season, $planId);
        self::assertNotNull($periodBefore);

        // Un créneau de GRILLE SAISON (schedulePlanId NULL).
        $this->scopeGucToClub($club->getId());
        $slot = (new VenueTrainingSlot)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek(4)
            ->setStartTime(new DateTimeImmutable('20:00'))->setDurationMinutes(90)
            ->setCapacity(1)->setSchedulePlanId(null);
        $this->em->persist($slot);
        $this->em->flush();
        $this->invalidateSeasonCache($club, $season);

        $seasonAfter = $this->hashOf($user, $season, $seasonPlanId);
        $periodAfter = $this->hashOf($user, $season, $planId);

        self::assertNotSame($seasonBefore, $seasonAfter, 'la grille SAISON a changé → le hash SAISON bouge');
        self::assertSame($periodBefore, $periodAfter, 'la grille d\'une période est copiée : toucher la grille SAISON ne bouge PAS son hash');
    }

    /** (d) Le plan d'un AUTRE club → 404 (jamais un oracle d'existence, jamais un 403). */
    public function testAnotherClubsPlanIs404(): void
    {
        [$user] = $this->seedClub('OWNER');
        [, , $otherSeason] = $this->seedClub('OTHER');
        $otherPlanId = $this->seasonPlanIdOf($otherSeason);

        // Le GUC/la RLS suivent le JETON du requérant (club OWNER) : le plan du club OTHER est
        // introuvable → 404, byte-identique à un plan inconnu.
        $this->client->request('GET', '/api/schedule_plans/' . $otherPlanId . '/structure-hash', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * (e) `null` propre, jamais une 500 : un plan de PÉRIODE dont l'entrée de calendrier a disparu
     * ne peut pas bâtir sa structure — le hash servi est `null`, le statut reste 200.
     */
    public function testHashIsNullWhenTheStructureCannotBeBuilt(): void
    {
        [$user, $club, $season] = $this->seedClub('NULL');
        // Plan de période accroché à une entrée inexistante (pas de FK sur calendar_entry_id).
        $planId = $this->orphanPeriodPlan($club, $season);

        $body = $this->getHash($user, $season, $planId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'une structure imbâtissable ne casse jamais la route');
        self::assertNull($body['currentStructureHash'], 'structure imbâtissable → hash null, jamais une 500');
    }

    /** (f) P4-266 : la ressource plan ne sert plus de bloc `staleness` — plus aucun signal « à régénérer » servi là. */
    public function testSchedulePlanResourceNoLongerServesStaleness(): void
    {
        [$user, , $season] = $this->seedClub('NOSTALE');

        $this->client->request('GET', '/api/schedule_plans', [], [], $this->headers($user, $season));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            '"staleness"',
            (string) $this->client->getResponse()->getContent(),
            'la ressource plan ne doit plus servir de bloc staleness (péremption désormais dérivée de l\'empreinte)',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function getHash(User $user, Season $season, string $planId): array
    {
        $this->client->request('GET', '/api/schedule_plans/' . $planId . '/structure-hash', [], [], $this->headers($user, $season));

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function hashOf(User $user, Season $season, string $planId): ?string
    {
        $body = $this->getHash($user, $season, $planId);
        self::assertResponseIsSuccessful();
        $hash = $body['currentStructureHash'] ?? null;

        return \is_string($hash) ? $hash : null;
    }

    private function invalidateSeasonCache(Club $club, Season $season): void
    {
        self::getContainer()->get('cache.schedule')->deleteItem(
            ScheduleConstraintBuilder::cacheKey($club->getId(), $season->getId()),
        );
    }

    /** Une version de socle portant le snapshotHash que le rail de génération poserait. */
    private function seasonVersionWithRailSnapshot(Club $club, Season $season): Schedule
    {
        $builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $payload = $builder->buildForClubSeason($club->getId(), $season->getId());
        $hash = hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR));

        $schedule = (new Schedule)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSchedulePlanId($this->seasonPlanIdOf($season))
            ->setName('Socle')->setStatus(ScheduleStatus::COMPLETED)
            ->setSnapshotData($payload)->setSnapshotHash($hash);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function socleVersion(Club $club, Season $season): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSchedulePlanId($this->seasonPlanIdOf($season))
            ->setName('Socle')->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);

        return $schedule;
    }

    private function socleSlot(Schedule $socle, Team $team, Venue $venue, int $day, string $start): void
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($socle->getClubId())->setSeasonId($socle->getSeasonId())
            ->setScheduleId($socle->getId())->setTeamId($team->getId())
            ->setVenueId($venue->getId())->setCoachId(null)
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes(90)->setLockLevel(LockLevel::NONE);
        $this->em->persist($slot);
    }

    private function period(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Reprise');
        $entry->setStartDate(new DateTimeImmutable('2025-10-20'));
        $entry->setEndDate(new DateTimeImmutable('2025-10-26'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /** Un plan de période HOLIDAY accroché à une entrée QUI N'EXISTE PAS (structure imbâtissable). */
    private function orphanPeriodPlan(Club $club, Season $season): string
    {
        $plan = new SchedulePlan;
        $plan->setClubId($club->getId());
        $plan->setSeasonId($season->getId());
        $plan->setType(SchedulePlanType::HOLIDAY);
        $plan->setName('Période orpheline');
        $plan->setStartDate(new DateTimeImmutable('2026-10-19'));
        $plan->setEndDate(new DateTimeImmutable('2026-11-02'));
        $plan->setCalendarEntryId($this->uuid()); // aucune CalendarEntry derrière.
        $this->em->persist($plan);
        $this->em->flush();

        return $plan->getId();
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name . '-' . uniqid());
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name . '-' . uniqid());
        $venue->setSource('manual');
        $venue->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /**
     * @return array<string, string>
     */
    private function headers(User $user, Season $season): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Season-Id' => $season->getId(),
        ];
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seedClub(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('hash-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('hash-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('Ha');
        $user->setLastName('Sh');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
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
        $sport->setSlug('hash-' . $uid);
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

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
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
        $this->category = $category;

        $this->provisionSeasonPlan($season);

        return [$user, $club, $season];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
