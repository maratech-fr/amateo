<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\VenueDayState;
use App\Enum\VenuePeriodMode;
use App\Service\PeriodPlanTranscriber;
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
 * NR BLOQUANT — « l'adaptation naît comme une COPIE du socle » (axes §7.1 : generation pipeline +
 * planning lifecycle).
 *
 * PROUVE que la V1 d'un plan de PÉRIODE, transcrite depuis la version POINTÉE du socle, est
 * EXACTEMENT ce socle filtré des réglages/fermetures de la période — falsifié dans les DEUX sens :
 *   (i)   une séance du socle sur gymnase sain / équipe active EST dans la copie (place ET verrou :
 *         HARD/MANUAL, ou HARD/RESERVATION si une réservation du plan coïncide) ;
 *   (ii)  une séance sur un jour EFFECTIVEMENT fermé du gymnase N'Y EST PAS et figure dans « à
 *         replacer » avec sa raison (venue_closed) ; idem gymnase désactivé (venue_disabled) ;
 *   (iii) équipe réduite → LES DERNIÈRES de la semaine sont retirées (déterminisme ÉPINGLÉ :
 *         jour puis heure décroissants), et figurent « à replacer » (team_reduced) ;
 *   (iv)  un plan DÉJÀ versionné → refus nommé (409) ;
 *   (v)   la route est SOUS LES GARDES : rôle (non-management → 403) et tenant (autre club → 403).
 *
 * Une équipe désactivée ne joue pas : ses séances DISPARAISSENT, elles ne sont PAS « à replacer ».
 * Le pointeur N'EST PAS posé (décision fondateur : pas de choose() automatique).
 */
#[Group('phase1')]
#[Group('security')]
final class PeriodCopyBirthTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const COACH_ID = '99999999-9999-4999-8999-999999999999';

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    private SportCategory $category;

    /**
     * (i)+(ii)+(iii) réunis : la copie == le socle POINTÉ filtré, place ET verrou, avec la liste
     * « à replacer » et son déterminisme.
     */
    public function testCopyMirrorsPointedSocleFilteredByPeriodSettings(): void
    {
        [$user, $club, $season] = $this->seedClub('COPY');

        $healthy = $this->venue($club, $season, 'Sain');
        $closedVenue = $this->venue($club, $season, 'Fermé mercredi');
        $disabledVenue = $this->venue($club, $season, 'Désactivé');

        $teamKeep = $this->team($club, $season, 'Keep');
        $teamReserved = $this->team($club, $season, 'Reserved');
        $teamClosed = $this->team($club, $season, 'Closed');
        $teamDisabled = $this->team($club, $season, 'DisabledVenue');
        $teamReduced = $this->team($club, $season, 'Reduced');
        $teamPaused = $this->team($club, $season, 'Paused');

        // La version POINTÉE du socle (plan SEASON) et ses placements.
        $socle = $this->socleVersion($club, $season);
        $this->socleSlot($socle, $teamKeep, $healthy, 1, '18:00', self::COACH_ID);       // → copié HARD/MANUAL
        $this->socleSlot($socle, $teamReserved, $healthy, 2, '19:00');                  // → copié HARD/RESERVATION
        $this->socleSlot($socle, $teamClosed, $closedVenue, 3, '17:30');               // → à replacer venue_closed
        $this->socleSlot($socle, $teamDisabled, $disabledVenue, 4, '18:00');           // → à replacer venue_disabled
        // Équipe réduite : 3 séances du socle ; la DERNIÈRE de la semaine (Ven 20:00) part.
        $this->socleSlot($socle, $teamReduced, $healthy, 1, '18:00');                  // Lun → copié
        $this->socleSlot($socle, $teamReduced, $healthy, 3, '19:00');                  // Mer → copié
        $this->socleSlot($socle, $teamReduced, $healthy, 5, '20:00');                  // Ven → à replacer team_reduced
        $this->socleSlot($socle, $teamPaused, $healthy, 1, '16:00');                   // équipe en pause → disparaît
        $this->em->flush();
        $this->choosePlanVersion($socle); // VALIDER = POINTER : le socle est en vigueur.

        // La période et son plan (la grille est copiée du socle à la naissance).
        $entry = $this->period($club, $season);
        $planId = $this->planIdOf($entry);

        // Une réservation DU PLAN qui coïncide avec la séance de teamReserved → verrou HARD/RESERVATION.
        $this->reservation($club, $season, $planId, $teamReserved, $healthy, 2, '19:00');
        // Gymnase FERMÉ le mercredi (jour 3) par un masque manuel du plan.
        $this->venueDayClosed($club, $season, $planId, $closedVenue, 3);
        // Gymnase DÉSACTIVÉ pour la période.
        $this->venueDisabled($club, $season, $planId, $disabledVenue);
        // Équipe réduite à 2 séances, équipe en pause désactivée.
        $this->teamOverride($club, $season, $planId, $teamReduced, true, 2);
        $this->teamOverride($club, $season, $planId, $teamPaused, false, null);
        $this->em->flush();

        $body = $this->transcribe($user, $club, $season, $planId);

        // ── La copie en base ────────────────────────────────────────────────────────────────────
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $scheduleId = (string) $body['id'];
        $copies = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId], ['id' => 'ASC']);

        $copiedKeys = [];
        foreach ($copies as $slot) {
            $copiedKeys[$this->key($slot->getTeamId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'))] = $slot;
            self::assertSame(LockLevel::HARD, $slot->getLockLevel(), 'une séance transcrite est VERROUILLÉE (HARD)');
        }

        // (i) présentes : teamKeep, teamReserved, et les deux séances CONSERVÉES de teamReduced.
        self::assertArrayHasKey($this->key($teamKeep->getId(), 1, '18:00'), $copiedKeys, 'séance sur gymnase sain / équipe active copiée');
        self::assertArrayHasKey($this->key($teamReserved->getId(), 2, '19:00'), $copiedKeys);
        self::assertArrayHasKey($this->key($teamReduced->getId(), 1, '18:00'), $copiedKeys);
        self::assertArrayHasKey($this->key($teamReduced->getId(), 3, '19:00'), $copiedKeys);

        // Verrou VRAI, jamais deviné : MANUAL par défaut, RESERVATION si une réservation coïncide.
        self::assertSame(LockOrigin::MANUAL, $copiedKeys[$this->key($teamKeep->getId(), 1, '18:00')]->getLockOrigin());
        self::assertSame(LockOrigin::RESERVATION, $copiedKeys[$this->key($teamReserved->getId(), 2, '19:00')]->getLockOrigin(), 'coïncide avec une réservation du plan → RESERVATION');
        // Copie À L'IDENTIQUE : le coach du socle suit.
        self::assertSame(self::COACH_ID, $copiedKeys[$this->key($teamKeep->getId(), 1, '18:00')]->getCoachId());

        // (ii)+(iii) ABSENTES de la copie (falsification n°1 : les y trouver rougirait).
        self::assertArrayNotHasKey($this->key($teamClosed->getId(), 3, '17:30'), $copiedKeys, 'jour fermé : PAS copié');
        self::assertArrayNotHasKey($this->key($teamDisabled->getId(), 4, '18:00'), $copiedKeys, 'gymnase désactivé : PAS copié');
        self::assertArrayNotHasKey($this->key($teamReduced->getId(), 5, '20:00'), $copiedKeys, 'réduction : la DERNIÈRE de la semaine (Ven 20:00) PAS copiée');
        self::assertArrayNotHasKey($this->key($teamPaused->getId(), 1, '16:00'), $copiedKeys, 'équipe en pause : PAS copiée');
        self::assertSame(4, $body['copiedCount'], 'exactement 4 séances transcrites');

        // ── La liste « à replacer » (sous-produit) ──────────────────────────────────────────────
        $replace = [];
        foreach ($body['toReplace'] as $row) {
            $replace[$this->key((string) $row['teamId'], (int) $row['dayOfWeek'], (string) $row['startTime'])] = (string) $row['reason'];
        }

        self::assertSame(PeriodPlanTranscriber::SKIP_VENUE_CLOSED, $replace[$this->key($teamClosed->getId(), 3, '17:30')] ?? null);
        self::assertSame(PeriodPlanTranscriber::SKIP_VENUE_DISABLED, $replace[$this->key($teamDisabled->getId(), 4, '18:00')] ?? null);
        // (iii) DÉTERMINISME : c'est bien la Ven 20:00 (dernière de la semaine) qui est retirée, pas
        // la Lun 18:00 ni la Mer 19:00.
        self::assertSame(PeriodPlanTranscriber::SKIP_TEAM_REDUCED, $replace[$this->key($teamReduced->getId(), 5, '20:00')] ?? null);
        self::assertArrayNotHasKey($this->key($teamReduced->getId(), 1, '18:00'), $replace, 'la réduction NE retire PAS la première de la semaine');

        // Une équipe désactivée n'est JAMAIS « à replacer » (elle ne joue pas).
        self::assertArrayNotHasKey($this->key($teamPaused->getId(), 1, '16:00'), $replace);
        self::assertCount(3, $body['toReplace'], 'exactement 3 séances à replacer');

        // Décision fondateur : PAS de choose() automatique — le plan reste NON validé.
        self::assertNull(
            self::getContainer()->get(SchedulePlanProvisioner::class)->chosenOfPeriodPlan($entry->getId()),
            'la transcription NE valide PAS : le gestionnaire choisit ensuite',
        );
    }

    /** (iv) Un plan qui porte déjà une version ne se re-copie pas : 409 nommé. */
    public function testAlreadyVersionedPlanIsRefused(): void
    {
        [$user, $club, $season] = $this->seedClub('DUP');
        $healthy = $this->venue($club, $season, 'Sain');
        $team = $this->team($club, $season, 'A');
        $socle = $this->socleVersion($club, $season);
        $this->socleSlot($socle, $team, $healthy, 1, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $entry = $this->period($club, $season);
        $planId = $this->planIdOf($entry);

        // 1re transcription : acceptée.
        $this->transcribe($user, $club, $season, $planId);

        // 2de : le plan a désormais une version → refus 409 nommé.
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/transcribe-from-socle', [], [], $this->headers($user, $club, $season));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà une version', (string) $this->client->getResponse()->getContent());
    }

    /** (v) La route est sous les gardes : rôle (non-management) et tenant (autre club). */
    public function testRouteIsUnderRoleAndTenantGuards(): void
    {
        [$owner, $club, $season] = $this->seedClub('GUARD');
        $healthy = $this->venue($club, $season, 'Sain');
        $team = $this->team($club, $season, 'A');
        $socle = $this->socleVersion($club, $season);
        $this->socleSlot($socle, $team, $healthy, 1, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);
        $entry = $this->period($club, $season);
        $planId = $this->planIdOf($entry);

        // Rôle : un membre NON-management est refusé (403) avant tout effet.
        $member = $this->member($club->getId(), 'editor');
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/transcribe-from-socle', [], [], $this->headers($member, $club, $season));
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'non-management → 403');

        // Tenant : un gestionnaire d'un AUTRE club ne peut pas transcrire ce plan (403/404).
        [$stranger, $otherClub, $otherSeason] = $this->seedClub('OTHER');
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/transcribe-from-socle', [], [], $this->headers($stranger, $otherClub, $otherSeason));
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404], 'plan d\'un autre club → refusé');

        // Le plan n'a toujours AUCUNE version (aucun effet des appels refusés).
        $this->scopeGucToClub($club->getId());
        self::assertSame(0, \count($this->em->getRepository(Schedule::class)->findBy(['schedulePlanId' => $planId])));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    private function key(string $teamId, int $day, string $start): string
    {
        return \sprintf('%s:%d:%s', $teamId, $day, substr($start, 0, 5));
    }

    /**
     * @return array{id: string, versionNumber: int, copiedCount: int, toReplace: list<array<string, mixed>>}
     */
    private function transcribe(User $user, Club $club, Season $season, string $planId): array
    {
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/transcribe-from-socle', [], [], $this->headers($user, $club, $season));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        /* @var array{id: string, versionNumber: int, copiedCount: int, toReplace: list<array<string, mixed>>} */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function headers(User $user, Club $club, Season $season): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Season-Id' => $season->getId(),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function socleVersion(Club $club, Season $season): Schedule
    {
        $planId = $this->seasonPlanIdOf($season);
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setName('Socle')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);

        return $schedule;
    }

    private function socleSlot(Schedule $socle, Team $team, Venue $venue, int $day, string $start, ?string $coachId = null): void
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($socle->getClubId())
            ->setSeasonId($socle->getSeasonId())
            ->setScheduleId($socle->getId())
            ->setTeamId($team->getId())
            ->setVenueId($venue->getId())
            ->setCoachId($coachId)
            ->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes(90)
            ->setLockLevel(LockLevel::NONE);
        $this->em->persist($slot);
    }

    private function period(Club $club, Season $season): CalendarEntry
    {
        // Semaine PLEINE (lundi → dimanche) : tous les jours de semaine sont dans la fenêtre.
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

    private function reservation(Club $club, Season $season, string $planId, Team $team, Venue $venue, int $day, string $start): void
    {
        $reservation = new Reservation;
        $reservation->setClubId($club->getId());
        $reservation->setSeasonId($season->getId());
        $reservation->setSchedulePlanId($planId);
        $reservation->setTeamId($team->getId());
        $reservation->setVenueId($venue->getId());
        $reservation->setDayOfWeek($day);
        $reservation->setStartTime(new DateTimeImmutable($start));
        $reservation->setDurationMinutes(90);
        $this->em->persist($reservation);
    }

    private function venueDayClosed(Club $club, Season $season, string $planId, Venue $venue, int $day): void
    {
        $override = new VenuePeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setVenueId($venue->getId());
        $override->setMode(null); // hérite : seule la journée masquée ferme
        $override->setDayOverrides([$day => VenueDayState::CLOSED->value]);
        $this->em->persist($override);
    }

    private function venueDisabled(Club $club, Season $season, string $planId, Venue $venue): void
    {
        $override = new VenuePeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setVenueId($venue->getId());
        $override->setMode(VenuePeriodMode::DISABLED);
        $this->em->persist($override);
    }

    private function teamOverride(Club $club, Season $season, string $planId, Team $team, bool $active, ?int $sessionsPerWeek): void
    {
        $override = new TeamPeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setTeamId($team->getId());
        $override->setIsActive($active);
        $override->setSessionsPerWeek($sessionsPerWeek);
        $this->em->persist($override);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name . '-' . uniqid());
        $team->setSessionsPerWeek(3);
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
        $venue->setName($name);
        $venue->setSource('manual');
        $venue->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function member(string $clubId, string $role): User
    {
        $uid = uniqid('', true);
        $user = new User;
        $user->setEmail('m-' . $uid . '@test.com');
        $user->setFirstName('Me');
        $user->setLastName('Mber');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seedClub(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('copy-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('copy-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('Co');
        $user->setLastName('Py');
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
        $sport->setSlug('copy-' . $uid);
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

        // Le plan SEASON qu'une vraie saison possède dès sa naissance.
        $this->provisionSeasonPlan($season);

        return [$user, $club, $season];
    }
}
