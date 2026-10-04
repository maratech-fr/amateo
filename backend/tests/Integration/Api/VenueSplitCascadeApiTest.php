<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\State\Processor\VenueStateProcessor;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * v2 cohérence canSplit — rendre un gymnase indivisible alors que des créneaux y accueillent
 * encore 2 équipes ou plus est INCOHÉRENT. La garde inverse (capacité ≥ 2 sur gymnase non
 * divisible) existe déjà côté créneau ({@see VenueTrainingSlotApiTest::testCapacityValidation}) ;
 * ce test ferme le sens retour, porté par {@see VenueStateProcessor} :
 *   - décocher SANS confirmation → 422 qui NOMME les créneaux visés (jour + heure) ;
 *   - décocher AVEC `confirmSplitCascade` → cascade atomique : capacité ramenée à 1, libellé
 *     retiré, réservations vidées, sur TOUTES les couches (saison + période) ; le planning
 *     COMPLETED du club+saison est marqué périmé (le write du gymnase suffit).
 */
#[Group('phase1')]
final class VenueSplitCascadeApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $passwordHasher;

    private Club $club;

    private User $user;

    private Season $season;

    private string $token;

    public function testUncheckingSplitWithoutConfirmationIsRefusedAndNamesTheSlots(): void
    {
        $venue = $this->createVenue(true);
        $this->createSlot($venue->getId(), 1, '17:30', 2, 'CEC3', null);

        $this->putVenueCanSplit($venue->getId(), false, false);

        self::assertResponseStatusCodeSame(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('ne peut pas devenir indivisible', $content);
        // Le créneau est NOMMÉ par son jour + heure (jamais un identifiant interne).
        self::assertStringContainsString('lundi 17:30', $content);

        // Refus = état intact : le gymnase reste divisible, le créneau garde sa capacité.
        $this->em->clear();
        $reloaded = $this->em->find(Venue::class, $venue->getId());
        self::assertInstanceOf(Venue::class, $reloaded);
        self::assertTrue($reloaded->getCanSplit(), 'un refus ne doit rien changer au gymnase');
    }

    public function testConfirmedCascadeClampsSlotsAcrossLayersClearsReservationsAndMarksStale(): void
    {
        $venue = $this->createVenue(true);
        $periodPlanId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());

        // Un créneau de SAISON et un créneau de PÉRIODE, tous deux à capacité 2 avec libellé.
        $seasonSlot = $this->createSlot($venue->getId(), 1, '17:30', 2, 'CEC3', null);
        $periodSlot = $this->createSlot($venue->getId(), 3, '20:00', 2, 'CEC4', $periodPlanId);

        // Des réservations sur CHAQUE couche — la cascade doit toutes les vider.
        $this->createReservation($venue->getId(), 1, '17:30', null);
        $this->createReservation($venue->getId(), 1, '17:30', null);
        $this->createReservation($venue->getId(), 3, '20:00', $periodPlanId);

        // Un planning COMPLETED du club+saison : le write du gymnase doit le marquer périmé.
        $schedule = $this->completedSeasonSchedule();
        $this->resetStaleMarker($schedule);

        $this->putVenueCanSplit($venue->getId(), false, true);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($body['canSplit'], 'la confirmation persiste bien le gymnase non divisible');

        $this->em->clear();

        // Les deux créneaux (saison ET période) sont ramenés à 1 et perdent leur libellé.
        foreach ([$seasonSlot->getId(), $periodSlot->getId()] as $slotId) {
            $slot = $this->em->find(VenueTrainingSlot::class, $slotId);
            self::assertInstanceOf(VenueTrainingSlot::class, $slot);
            self::assertSame(1, $slot->getCapacity(), 'la capacité doit retomber à 1');
            self::assertNull($slot->getGroupLabel(), 'le libellé de groupe doit être retiré');
        }

        // Toutes les réservations du gymnase sont vidées (saison + période).
        $remaining = $this->em->getRepository(Reservation::class)->count(['venueId' => $venue->getId()]);
        self::assertSame(0, $remaining, 'la cascade vide les réservations de toutes les couches');

        $reloaded = $this->em->find(Schedule::class, $schedule->getId());
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertTrue(
            $reloaded->isResourcesChangedSinceGeneration(),
            'le planning COMPLETED du club+saison est marqué périmé par la cascade',
        );
    }

    public function testUncheckingSplitPassesWithoutConfirmationWhenNoSlotHoldsTwoTeams(): void
    {
        // Un gymnase divisible dont aucun créneau n'exploite la division : décocher est cohérent,
        // sans confirmation ni cascade. Garde le cas vert de la garde.
        $venue = $this->createVenue(true);
        $this->createSlot($venue->getId(), 1, '18:00', 1, null, null);

        $this->putVenueCanSplit($venue->getId(), false, false);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($body['canSplit']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get('security.user_password_hasher');

        $this->club = $this->createClub();
        $this->user = $this->createUser();
        $this->createClubUser($this->club, $this->user);
        $this->season = $this->createSeason($this->club);
        $this->provisionSeasonPlan($this->season);

        $this->token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    private function putVenueCanSplit(string $venueId, bool $canSplit, bool $confirm): void
    {
        $this->client->request('PUT', '/api/venues/' . $venueId, [], [], [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Gymnase',
            'source' => 'manual',
            'canSplit' => $canSplit,
            'confirmSplitCascade' => $confirm,
        ], \JSON_THROW_ON_ERROR));
    }

    private function createSlot(string $venueId, int $day, string $start, int $capacity, ?string $groupLabel, ?string $schedulePlanId): VenueTrainingSlot
    {
        $slot = (new VenueTrainingSlot)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setVenueId($venueId)
            ->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes(90)
            ->setCapacity($capacity)
            ->setSchedulePlanId($schedulePlanId);
        $slot->setGroupLabel($groupLabel);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    private function createReservation(string $venueId, int $day, string $start, ?string $schedulePlanId): void
    {
        $reservation = (new Reservation)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setTeamId('00000000-0000-0000-0000-' . str_pad((string) random_int(0, 999999999999), 12, '0', \STR_PAD_LEFT))
            ->setVenueId($venueId)
            ->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes(90)
            ->setSchedulePlanId($schedulePlanId);
        $this->em->persist($reservation);
        $this->em->flush();
    }

    private function completedSeasonSchedule(): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setName('Planning')
            ->setStatus(ScheduleStatus::COMPLETED);
        // linkSeededSchedule résout/pose le plan SEASON (schedule_plan_id NOT NULL) puis numérote.
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function resetStaleMarker(Schedule $schedule): void
    {
        // clear() AVANT relecture : le montage a écrit des ressources qui ont marqué. On repart
        // d'une ardoise propre pour ne mesurer QUE l'effet de la cascade.
        $this->em->clear();
        $managed = $this->em->find(Schedule::class, $schedule->getId());
        self::assertInstanceOf(Schedule::class, $managed);
        $managed->setResourcesChangedSinceGeneration(false);
        $this->em->flush();
        $this->em->clear();
    }

    private function createVenue(bool $canSplit): Venue
    {
        $venue = new Venue;
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName('Gymnase');
        $venue->setSource('manual');
        $venue->setIsActive(true);
        $venue->setCanSplit($canSplit);

        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createClub(): Club
    {
        $club = new Club;
        $club->setName('Test Club ' . uniqid());
        $club->setSlug('test-club-' . uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);

        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        return $club;
    }

    private function createUser(): User
    {
        $user = new User;
        $user->setEmail('test-' . uniqid() . '@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'Password123!'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createClubUser(Club $club, User $user): void
    {
        $clubUser = new ClubUser;
        $clubUser->setClubId($club->getId());
        $clubUser->setUserId($user->getId());
        $clubUser->setRole('admin');
        $clubUser->setIsActive(true);

        $this->em->persist($clubUser);
        $this->em->flush();
    }

    private function createSeason(Club $club): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);

        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }
}
