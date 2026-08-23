<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\SeasonStatus;
use App\Service\TenantConnectionContext;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('phase1')]
final class VenueTrainingSlotApiTest extends WebTestCase
{
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $passwordHasher;

    private Club $club;

    private User $user;

    private Season $season;

    private string $token;

    /** @return array<string, array{0: int, 1: bool, 2: int}> */
    public static function capacityCases(): array
    {
        return [
            'capacity 2 rejected when venue cannot split' => [2, false, 422],
            'capacity 2 accepted when venue can split' => [2, true, 201],
            'capacity 1 accepted when venue cannot split' => [1, false, 201],
            'capacity 3 rejected by DTO range' => [3, false, 422],
            'capacity 0 rejected by DTO range' => [0, false, 422],
        ];
    }

    #[DataProvider('capacityCases')]
    public function testCapacityValidation(int $capacity, bool $canSplit, int $expectedStatusCode): void
    {
        $venue = $this->createVenue($canSplit);

        $this->client->request('POST', '/api/venue_training_slots', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'venueId' => $venue->getId(),
            'dayOfWeek' => 1,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'capacity' => $capacity,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame($expectedStatusCode);
    }

    public function testOverlappingSlotIsRejected(): void
    {
        $venue = $this->createVenue(false);
        $this->postSlot($venue->getId(), 1, '17:00', 90); // 17:00–18:30

        // 16:30–18:00 overlaps the 17:00–18:30 window on the same day/venue.
        $this->postSlot($venue->getId(), 1, '16:30', 90);
        self::assertResponseStatusCodeSame(422);
        // P4-126 — le motif voyage jusque dans le corps (un 422 muet rendrait `violations: []`).
        self::assertStringContainsString('Ce créneau en chevauche un autre du même gymnase ce jour-là.', (string) $this->client->getResponse()->getContent());
    }

    public function testAdjacentSlotsDoNotOverlap(): void
    {
        $venue = $this->createVenue(false);
        $this->postSlot($venue->getId(), 1, '17:00', 90); // 17:00–18:30

        // 18:30 start is back-to-back, not overlapping → accepted.
        $this->postSlot($venue->getId(), 1, '18:30', 60);
        self::assertResponseStatusCodeSame(201);

        // A different weekday never conflicts.
        $this->postSlot($venue->getId(), 2, '17:00', 90);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * NR — P4-61. L'API acceptait un créneau franchissant minuit : `22:45 + 150 min` était
     * persisté et ressortait « 25:15 » partout où une fin d'horaire s'affiche. Le geste était
     * fermé CÔTÉ FRONT depuis P4-37, mais un import ou un appel direct passait toujours.
     *
     * Axe *contraintes* effleuré : ce créneau part au solveur.
     */
    public function testSlotCrossingMidnightIsRejected(): void
    {
        $venue = $this->createVenue(false);

        // 22:45 + 2h30 = 25:15. Le cas exact relevé à la revue de P4-37.
        $this->postSlot($venue->getId(), 1, '22:45', 150);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEveningSlotEndingBeforeMidnightIsAccepted(): void
    {
        // La borne est MINUIT, pas la fin de la grille de saisie. Sans ce test, resserrer la
        // règle sur 23:00 (la dernière ligne affichée) passerait inaperçu et rendrait l'API
        // plus stricte que le métier — un 22:00–23:30 est légitime.
        $venue = $this->createVenue(false);

        $this->postSlot($venue->getId(), 1, '22:00', 90); // 22:00–23:30
        self::assertResponseStatusCodeSame(201);

        // Et la limite exacte : finir PILE à minuit reste dans la journée.
        $this->postSlot($venue->getId(), 2, '23:00', 60); // 23:00–24:00
        self::assertResponseStatusCodeSame(201);
    }

    public function testMidnightGuardAlsoAppliesToAnUpdate(): void
    {
        // La création et l'édition sont deux chemins distincts dans le processor : garder
        // l'un sans l'autre laisserait le trou grand ouvert à un PUT.
        $venue = $this->createVenue(false);
        $this->postSlot($venue->getId(), 3, '18:00', 90);
        $id = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->client->request('PUT', '/api/venue_training_slots/' . $id, [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'venueId' => $venue->getId(),
            'dayOfWeek' => 3,
            'startTime' => '23:30',
            'durationMinutes' => 90, // 23:30 + 1h30 = 25:00
            'capacity' => 1,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCanSplitPersistsOnVenueUpdate(): void
    {
        // Regression: the venue update processor silently dropped canSplit, so
        // toggling "terrain divisible" never persisted (no per-slot capacity, no
        // court splitting at solve time).
        $venue = $this->createVenue(false);

        $this->client->request('PUT', '/api/venues/' . $venue->getId(), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['name' => $venue->getName(), 'source' => 'manual', 'canSplit' => true], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['canSplit'], 'canSplit=true must persist through a venue update');

        // And un-checking must persist too (false !== null guard).
        $this->client->request('PUT', '/api/venues/' . $venue->getId(), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['name' => $venue->getName(), 'source' => 'manual', 'canSplit' => false], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($body['canSplit'], 'canSplit=false must persist (uncheck)');
    }

    public function testPaginationReturnsEveryRowAcrossPages(): void
    {
        // >30 rows (paginationItemsPerPage=30) force a 2nd page. Without a STABLE
        // total order, Postgres reshuffles rows between page requests and rows
        // straddling the boundary are silently dropped by the client's dedupe-paging
        // (the real "Matéo's Wednesday slots vanish" bug). The provider now orders on
        // the UUID PK, so page1 ∪ page2 must return every created slot.
        $venue = $this->createVenue(false);
        // Seed 35 slots via the EM (bypasses the per-user API rate limit + the
        // anti-overlap guard) under the club GUC. CRITICAL: every slot shares the
        // SAME (dayOfWeek, startTime) so the provider's dayOfWeek+startTime order is
        // fully tied — the UUID PK tiebreaker is then the ONLY thing that makes
        // pagination deterministic. Drop the tiebreaker and the 30/5 page split
        // reshuffles → boundary rows are lost, failing the assertion below.
        $guc = self::getContainer()->get(TenantConnectionContext::class);
        $guc->setClubId($this->club->getId());
        try {
            for ($i = 0; $i < 35; ++$i) {
                $slot = (new VenueTrainingSlot)
                    ->setClubId($this->club->getId())
                    ->setSeasonId($this->season->getId())
                    ->setVenueId($venue->getId())
                    ->setDayOfWeek(1)
                    ->setStartTime(new DateTimeImmutable('18:00'))
                    ->setDurationMinutes(90)
                    ->setCapacity(1);
                $this->em->persist($slot);
            }
            $this->em->flush();
        } finally {
            $guc->clear();
        }

        $ids = [];
        foreach ([1, 2] as $page) {
            $this->client->request('GET', '/api/venue_training_slots?page=' . $page, [], [], [
                'HTTP_X-Club-Id' => $this->club->getId(),
                'HTTP_X-Season-Id' => $this->season->getId(),
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ]);
            $body = json_decode((string) $this->client->getResponse()->getContent(), true);
            /** @var list<array{id: string}> $members */
            $members = \is_array($body) && \is_array($body['member'] ?? null) ? $body['member'] : [];
            foreach ($members as $slot) {
                $ids[] = $slot['id'];
            }
        }

        self::assertCount(35, array_unique($ids), 'every slot must be returned across pages — stable pagination order.');
    }

    public function testPeriodSlotIsScopedAndSeasonalListingExcludesIt(): void
    {
        $period = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $venue = $this->createVenue(false);
        // Seasonal slot at 18:00; a period slot for the SAME gym at a DIFFERENT time.
        $this->postSlot($venue->getId(), 1, '18:00', 90);
        self::assertResponseStatusCodeSame(201);
        $this->postPeriodSlot($venue->getId(), 1, '20:00', 90, $period);
        self::assertResponseStatusCodeSame(201);

        // Default listing = SEASONAL only (the wizard's seasonal editor).
        self::assertSame([null], $this->listPlanIds(''), 'default listing excludes period slots');
        // Period listing = that period's slots only.
        self::assertSame([$period], $this->listPlanIds('?schedulePlanId=' . $period), 'period listing returns only period slots');
    }

    /**
     * #8 — depuis que la période POSSÈDE sa grille (copie du modèle de saison à sa
     * naissance), le socle est bâti sur les créneaux de saison SEULS et une période sur
     * les siens SEULS : plus aucune union. Des couches différentes ne peuvent donc plus
     * se télescoper, et c'est indispensable — la copie d'une période est identique heure
     * pour heure à son original, si on la comptait comme un chevauchement la grille de
     * saison deviendrait inéditable dès qu'une période existe (revue #8).
     * Le chevauchement RÉEL — deux créneaux d'une MÊME couche — reste refusé.
     */
    public function testOverlapIsCheckedWithinALayerNotAcrossLayers(): void
    {
        $venue = $this->createVenue(false);
        $this->postSlot($venue->getId(), 1, '18:00', 90); // saison 18:00–19:30
        self::assertResponseStatusCodeSame(201);

        // Couche différente (une période) au même horaire : jamais servi ensemble → OK.
        $period = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $this->postPeriodSlot($venue->getId(), 1, '18:00', 90, $period);
        self::assertResponseStatusCodeSame(201);

        // Deux périodes DIFFÉRENTES au même horaire : jamais servies ensemble non plus.
        $otherPeriod = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $this->postPeriodSlot($venue->getId(), 3, '20:00', 90, $period);
        self::assertResponseStatusCodeSame(201);
        $this->postPeriodSlot($venue->getId(), 3, '20:00', 90, $otherPeriod);
        self::assertResponseStatusCodeSame(201);

        // MÊME couche, en revanche : le double-booking reste refusé, des deux côtés.
        $this->postSlot($venue->getId(), 1, '18:30', 90); // saison vs saison
        self::assertResponseStatusCodeSame(422);
        $this->postPeriodSlot($venue->getId(), 1, '18:30', 90, $period); // période vs même période
        self::assertResponseStatusCodeSame(422);
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

        // Stateless JWT firewall → every request carries a Bearer (loginUser only
        // authenticates the first request; the overlap tests fire several).
        $this->token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    private function postPeriodSlot(string $venueId, int $day, string $start, int $duration, string $schedulePlanId): void
    {
        $this->client->request('POST', '/api/venue_training_slots', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['venueId' => $venueId, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => $duration, 'capacity' => 1, 'schedulePlanId' => $schedulePlanId], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string|null> the schedulePlanId of every listed slot (null = créneau saisonnier)
     */
    private function listPlanIds(string $query): array
    {
        $this->client->request('GET', '/api/venue_training_slots' . $query, [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
        ]);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        /** @var list<array{schedulePlanId?: string|null}> $members */
        $members = \is_array($body) && \is_array($body['member'] ?? null) ? $body['member'] : [];

        return array_values(array_unique(array_map(static fn (array $s): ?string => $s['schedulePlanId'] ?? null, $members)));
    }

    private function postSlot(string $venueId, int $day, string $start, int $duration): void
    {
        $this->client->request('POST', '/api/venue_training_slots', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'startTime' => $start,
            'durationMinutes' => $duration,
            'capacity' => 1,
        ], \JSON_THROW_ON_ERROR));
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

    private function createVenue(bool $canSplit): Venue
    {
        $venue = new Venue;
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName('Venue ' . uniqid());
        $venue->setSource('manual');
        $venue->setIsActive(true);
        $venue->setCanSplit($canSplit);

        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }
}
