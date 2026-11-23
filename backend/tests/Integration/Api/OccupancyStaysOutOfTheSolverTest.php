<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
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
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Plans\PlanComposition;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR — une occupation ne périme jamais le planning : elle n'entre pas dans l'envoi au moteur
 * (axes §7.1 *generation pipeline* et *backend↔engine contract*, bloquant).
 *
 * L'occupation n'informe que le lecteur (décision fondateur : aucun effet sur le moteur,
 * l'empreinte, le radar, le Récap, la génération). Le témoin observable de « ce qui part au
 * moteur » est l'empreinte de structure servie par `GET /api/schedule_plans/{id}/structure-hash`
 * (hachage de l'envoi, `ScheduleConstraintBuilder::structureHash`) : poser une occupation par la
 * VRAIE route (`POST /api/venue_occupancies`) ne la fait pas bouger, cache de l'envoi purgé entre
 * les deux lectures — en saison comme dans un plan de période (sa grille est une copie, ADR-0002).
 *
 * Témoin positif dans chaque cas : poser un CRÉNEAU dans la même couche fait bouger l'empreinte —
 * sans lui, une empreinte figée (ou une route qui ne relit rien) rendrait le test vert à vide.
 *
 * Falsification à jouer : ajouter les occupations à l'envoi (`ScheduleConstraintBuilder`) → rouge.
 * Le scénario Behat ne porte pas cette promesse (plan PR6 §6) : ce test en est la seule preuve.
 */
#[Group('phase1')]
#[Group('integration')]
final class OccupancyStaysOutOfTheSolverTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeopleAndPlacesTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    private SportCategory $category;

    /** Saison : poser une occupation dans la grille de saison ne change pas l'empreinte du plan de saison. */
    public function testAnOccupancyNeverMovesTheSeasonStructureHash(): void
    {
        [$user, $club, $season, $venue] = $this->seedClubWithGrid('SEASON');
        $planId = $this->seasonPlanIdOf($season);

        $before = $this->hashOf($user, $season, $planId);
        self::assertNotNull($before, 'une saison avec structure doit servir une empreinte');

        $this->postOccupancy($user, $season, $venue, null);
        $this->invalidateSendCache($club);
        self::assertSame($before, $this->hashOf($user, $season, $planId), 'une occupation de saison ne doit pas entrer dans l\'envoi au moteur');

        // Témoin : un créneau de saison, lui, change l'envoi.
        $this->postTrainingSlot($user, $season, $venue, null);
        $this->invalidateSendCache($club);
        self::assertNotSame($before, $this->hashOf($user, $season, $planId), 'témoin : un créneau de saison ajouté doit faire bouger l\'empreinte (sinon le test ne prouve rien)');
    }

    /**
     * Période : une occupation posée dans la grille du plan de période — et une autre dans la
     * saison — ne changent pas l'empreinte du plan de période.
     */
    public function testAnOccupancyNeverMovesAPeriodStructureHash(): void
    {
        [$user, $club, $season, $venue, $team] = $this->seedClubWithGrid('PERIOD');

        $socle = $this->socleVersion($club, $season);
        $this->socleSlot($socle, $team, $venue);
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season));
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/transcribe-from-socle', [], [], $this->headers($user, $season));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $before = $this->hashOf($user, $season, $planId);
        self::assertNotNull($before, 'un plan de période transcrit doit servir une empreinte');

        $this->postOccupancy($user, $season, $venue, $planId);
        $this->postOccupancy($user, $season, $venue, null);
        $this->invalidateSendCache($club);
        self::assertSame($before, $this->hashOf($user, $season, $planId), 'une occupation (de la période ou de la saison) ne doit pas entrer dans l\'envoi du plan de période');

        // Témoin : un créneau posé dans la grille de la période, lui, change l'envoi.
        $this->postTrainingSlot($user, $season, $venue, $planId);
        $this->invalidateSendCache($club);
        self::assertNotSame($before, $this->hashOf($user, $season, $planId), 'témoin : un créneau de période ajouté doit faire bouger l\'empreinte (sinon le test ne prouve rien)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /** « École », mardi 14:00-16:15 : hors du créneau du décor (lundi 18:00). */
    private function postOccupancy(User $user, Season $season, Venue $venue, ?string $planId): void
    {
        $body = ['venueId' => $venue->getId(), 'dayOfWeek' => 2, 'startTime' => '14:00', 'durationMinutes' => 135, 'label' => 'École'];
        if (null !== $planId) {
            $body['schedulePlanId'] = $planId;
        }
        $this->client->request('POST', '/api/venue_occupancies', [], [], $this->headers($user, $season) + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), 'la pose de l\'occupation doit réussir : ' . $this->client->getResponse()->getContent());
    }

    /** Un créneau jeudi 20:00, par la route réelle. */
    private function postTrainingSlot(User $user, Season $season, Venue $venue, ?string $planId): void
    {
        $body = ['venueId' => $venue->getId(), 'dayOfWeek' => 4, 'startTime' => '20:00', 'durationMinutes' => 90, 'capacity' => 1];
        if (null !== $planId) {
            $body['schedulePlanId'] = $planId;
        }
        $this->client->request('POST', '/api/venue_training_slots', [], [], $this->headers($user, $season) + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), 'la pose du créneau témoin doit réussir : ' . $this->client->getResponse()->getContent());
    }

    private function hashOf(User $user, Season $season, string $planId): ?string
    {
        $this->client->request('GET', '/api/schedule_plans/' . $planId . '/structure-hash', [], [], $this->headers($user, $season));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $hash = \is_array($body) ? ($body['currentStructureHash'] ?? null) : null;

        return \is_string($hash) ? $hash : null;
    }

    /** L'envoi au moteur est mis en cache par club : on le purge entre deux lectures. */
    private function invalidateSendCache(Club $club): void
    {
        self::getContainer()->get('cache.schedule')->invalidateTags([ScheduleConstraintBuilder::cacheTag($club->getId())]);
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

    private function socleSlot(Schedule $socle, Team $team, Venue $venue): void
    {
        $this->em->persist((new ScheduleSlotTemplate)
            ->setClubId($socle->getClubId())->setSeasonId($socle->getSeasonId())
            ->setScheduleId($socle->getId())->setTeamId($team->getId())
            ->setVenueId($venue->getId())->setCoachId(null)
            ->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)->setLockLevel(LockLevel::NONE));
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
     * Un club, sa saison et son plan de saison, une équipe, un gymnase portant un créneau de saison
     * (lundi 18:00) — la grille que le plan de période copiera à sa naissance.
     *
     * @return array{0: User, 1: Club, 2: Season, 3: Venue, 4: Team}
     */
    private function seedClubWithGrid(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club occupation ' . $tag);
        $club->setSlug('occ-' . strtolower($tag) . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('occ-' . strtolower($tag) . '-' . $uid . '@test.com');
        $user->setFirstName('Oc');
        $user->setLastName('Cupation');
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
        $sport->setSlug('occ-' . $uid);
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

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->category->getId());
        $team->setPriorityTierId(1);
        $team->setName('U13-' . uniqid());
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2]]);

        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gymnase-' . uniqid())->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        $this->em->persist((new VenueTrainingSlot)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)
            ->setCapacity(1)->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $season->getId())));
        $this->em->flush();

        return [$user, $club, $season, $venue, $team];
    }
}
