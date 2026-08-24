<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\CompetitionType;
use App\Enum\FbiIngestionSource;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamLinkType;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tenant/season isolation NR for the new match entities (spec gestion-matchs,
 * §7.1 tenant axis): Competition/Fixture of club/season A never leak to club B,
 * writes stamp the resolved club+season, and archived-season writes are
 * refused (409, inherited SeasonAccessGuard).
 */
#[Group('phase1')]
#[Group('integration')]
final class MatchTenantIsolationTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testFixturesAreScopedToTheCallersClub(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        $this->createFixture($clubA, 'Adversaire A');
        [$clubB] = $this->createClubUser('b');
        $this->createFixture($clubB, 'Adversaire B');

        $this->client->request('GET', '/api/fixtures', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);
        $labels = array_map(
            static fn (array $m): string => $m['opponentLabel'],
            $this->responseData()['member'] ?? [],
        );
        self::assertSame(['Adversaire A'], $labels);
    }

    public function testItemOfAnotherClubIs404(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        [$clubB] = $this->createClubUser('b');
        $foreign = $this->createFixture($clubB, 'Adversaire B');
        $this->em->clear();

        $this->client->request('GET', '/api/fixtures/' . $foreign->getId(), [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPostStampsTheResolvedClubAndSeason(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $teamId = '11111111-1111-4111-8111-111111111111';

        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => $teamId,
            'matchDate' => '2026-10-04',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Nouvel adversaire',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['opponentLabel' => 'Nouvel adversaire']);
        self::assertNotNull($fixture);
        self::assertSame($clubA->getId(), $fixture->getClubId());
        self::assertSame($seasonA->getId(), $fixture->getSeasonId());
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus());
    }

    public function testCompetitionCollectionIsScoped(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $this->createCompetition($clubA, $seasonA, 'Championnat A');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $this->createCompetition($clubB, $seasonB, 'Championnat B');

        $this->client->request('GET', '/api/competitions', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);
        $names = array_map(static fn (array $m): string => $m['name'], $this->responseData()['member'] ?? []);
        self::assertSame(['Championnat A'], $names);
    }

    public function testWriteOnArchivedSeasonIsRefused(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        // Add a PAST season → it becomes archived (read-only).
        $this->scopeGucToClub($clubA->getId());
        $past = $this->season($clubA, SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1);
        $this->em->flush();

        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders($userA) + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'teamId' => '11111111-1111-4111-8111-111111111111',
            'matchDate' => '2025-10-04',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Archive',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    // ── Capacité matchs (P1-4 PR B) : mêmes frontières pour les deux nouvelles entités ──

    public function testVenueMatchWindowsAreScopedAndStampTheResolvedClub(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Gymnase A');
        [$clubB, $userB, $seasonB] = $this->createClubUser('b');
        $this->createVenue($clubB, $seasonB, 'Gymnase B');

        $this->client->request('POST', '/api/venue_match_windows', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueA->getId(),
            'dayOfWeek' => 6,
            'startTime' => '14:00',
            'endTime' => '22:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $window = $this->em->getRepository(VenueMatchWindow::class)->findOneBy(['venueId' => $venueA->getId()]);
        self::assertNotNull($window);
        self::assertSame($clubA->getId(), $window->getClubId());
        self::assertSame($seasonA->getId(), $window->getSeasonId());

        // Club B sees nothing of it.
        $this->client->request('GET', '/api/venue_match_windows', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $this->responseData()['member'] ?? ['sentinel']);
    }

    public function testCapacityWritesCannotTargetAForeignVenue(): void
    {
        // The venue of the OTHER club is invisible through the tenant filters →
        // 422, no dangling cross-club reference (both new entities).
        [, $userA] = $this->createClubUser('a');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $venueB = $this->createVenue($clubB, $seasonB, 'Gymnase B');

        $this->client->request('POST', '/api/venue_match_windows', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueB->getId(), 'dayOfWeek' => 6, 'startTime' => '14:00', 'endTime' => '22:00',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/venue_unavailabilities', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueB->getId(), 'startDate' => '2027-02-04', 'endDate' => '2027-02-28',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(VenueMatchWindow::class)->findBy(['venueId' => $venueB->getId()]));
        self::assertCount(0, $this->em->getRepository(VenueUnavailability::class)->findBy(['venueId' => $venueB->getId()]));
    }

    public function testVenueUnavailabilityIsManagementGatedAndSeasonGuarded(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Gymnase A');

        // Cockpit-surface write (SEC-07): a non-management member is refused.
        $editor = $this->createMember($clubA, 'editor');
        $this->client->request('POST', '/api/venue_unavailabilities', [], [], $this->authHeaders($editor) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueA->getId(), 'startDate' => '2027-02-04', 'endDate' => '2027-02-28',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        // Archived-season writes refused (inherited SeasonAccessGuard).
        $this->scopeGucToClub($clubA->getId());
        $past = $this->season($clubA, SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1);
        $this->em->flush();
        $this->client->request('POST', '/api/venue_unavailabilities', [], [], $this->authHeaders($userA) + [
            'HTTP_X-Season-Id' => $past->getId(), 'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'venueId' => $venueA->getId(), 'startDate' => '2026-02-04', 'endDate' => '2026-02-28',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    // ── Préférences matchs (P1-4 PR C) : habitudes + passerelles ──────────────

    public function testHabitsAreScopedStampedAndUniquePerDay(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $teamA = $this->createTeam($clubA, $seasonA, 'SF3');
        [, $userB] = $this->createClubUser('b');
        $headers = $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/team_match_habits', [], [], $headers, json_encode([
            'teamId' => $teamA->getId(), 'dayOfWeek' => 7, 'kickoffTime' => '17:30',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $habit = $this->em->getRepository(TeamMatchHabit::class)->findOneBy(['teamId' => $teamA->getId()]);
        self::assertSame($clubA->getId(), $habit?->getClubId());
        self::assertSame($seasonA->getId(), $habit?->getSeasonId());

        // One habit per weekday: readable 422, not a DB 500.
        $this->client->request('POST', '/api/team_match_habits', [], [], $headers, json_encode([
            'teamId' => $teamA->getId(), 'dayOfWeek' => 7, 'kickoffTime' => '10:30',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // Club B sees nothing.
        $this->client->request('GET', '/api/team_match_habits', [], [], $this->authHeaders($userB));
        self::assertCount(0, $this->responseData()['member'] ?? ['sentinel']);
    }

    public function testTeamLinkIsSymmetricUniqueAndTenantScoped(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $sm1 = $this->createTeam($clubA, $seasonA, 'SM1');
        $sm2 = $this->createTeam($clubA, $seasonA, 'SM2');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $foreign = $this->createTeam($clubB, $seasonB, 'Étrangère');
        $headers = $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $sm1->getId(), 'teamBId' => $sm2->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // SM2–SM1 is the SAME couple (normalized) → readable 422 duplicate.
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $sm2->getId(), 'teamBId' => $sm1->getId(), 'linkType' => 'BACK_TO_BACK',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // A foreign team is invisible → 422, no cross-club write.
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $sm1->getId(), 'teamBId' => $foreign->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->em->getRepository(TeamLink::class)->findBy(['clubId' => $clubA->getId()]));

        // A team linked to itself is refused.
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $sm1->getId(), 'teamBId' => $sm1->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Lot PASSERELLES PR-1 — le plafond se refuse à la SAISIE, jamais à la génération.
     * Sans lui, la 51ᵉ passerelle passerait ici et ferait 422-FAILED le solve (le bord
     * Pydantic `MAX_TEAM_LINKS` la refuse) : une panne loin de sa cause. Le miroir des
     * deux littéraux est gardé par TeamLinkPayloadParityTest::testWriteCapMirrorsTheEngineEdgeCap.
     */
    public function testTheFiftyFirstTeamLinkIsRefusedAtWriteTime(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('cap');
        $headers = $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'];

        // 11 équipes offrent 55 couples : on en persiste 50 directement (le rail API
        // n'est pas le sujet ici), la 51ᵉ passe par l'API et doit être refusée.
        $teams = [];
        for ($i = 0; $i < 11; ++$i) {
            $teams[] = $this->createTeam($clubA, $seasonA, 'Cap' . $i);
        }
        $seeded = 0;
        for ($a = 0; $a < 11 && $seeded < 50; ++$a) {
            for ($b = $a + 1; $b < 11 && $seeded < 50; ++$b) {
                [$low, $high] = strcasecmp($teams[$a]->getId(), $teams[$b]->getId()) <= 0
                    ? [$teams[$a], $teams[$b]] : [$teams[$b], $teams[$a]];
                $link = new TeamLink;
                $link->setClubId($clubA->getId());
                $link->setSeasonId($seasonA->getId());
                $link->setTeamAId($low->getId());
                $link->setTeamBId($high->getId());
                $link->setLinkType(TeamLinkType::NOT_SIMULTANEOUS);
                $this->em->persist($link);
                ++$seeded;
            }
        }
        $this->em->flush();

        // La 51ᵉ (le couple encore libre) est refusée avec un message NOMMÉ.
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $teams[9]->getId(), 'teamBId' => $teams[10]->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('nombre maximal de passerelles', (string) $this->client->getResponse()->getContent());

        // Supprimer une passerelle rouvre la porte : le cap borne, il ne fige pas.
        $one = $this->em->getRepository(TeamLink::class)->findOneBy(['clubId' => $clubA->getId()]);
        self::assertInstanceOf(TeamLink::class, $one);
        $this->em->remove($one);
        $this->em->flush();
        $this->client->request('POST', '/api/team_links', [], [], $headers, json_encode([
            'teamAId' => $teams[9]->getId(), 'teamBId' => $teams[10]->getId(), 'linkType' => 'NOT_SIMULTANEOUS',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    // ── Rotation A/B (RMM-5) : le créneau de match partagé et ses membres, scopés+stampés ──

    public function testMatchSlotRotationsAreScopedStampedAndUnique(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Coubertin');
        $sm1 = $this->createTeam($clubA, $seasonA, 'SM1');
        $sm2 = $this->createTeam($clubA, $seasonA, 'SM2');
        [, $userB] = $this->createClubUser('b');
        $headers = $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, json_encode([
            'venueId' => $venueA->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30',
            'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Parent AND member rows carry the resolved club+season (denormalized on the member).
        $rotation = $this->em->getRepository(MatchSlotRotation::class)->findOneBy(['venueId' => $venueA->getId()]);
        self::assertSame($clubA->getId(), $rotation?->getClubId());
        self::assertSame($seasonA->getId(), $rotation?->getSeasonId());
        $members = $this->em->getRepository(MatchSlotRotationTeam::class)->findBy(['rotationId' => $rotation?->getId()]);
        self::assertCount(2, $members);
        foreach ($members as $member) {
            self::assertSame($clubA->getId(), $member->getClubId());
            self::assertSame($seasonA->getId(), $member->getSeasonId());
        }

        // Same physical slot → readable 422 (the DB unique is the backstop).
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, json_encode([
            'venueId' => $venueA->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30',
            'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        // Club B sees nothing of it.
        $this->client->request('GET', '/api/match_slot_rotations', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $this->responseData()['member'] ?? ['sentinel']);
    }

    public function testMatchSlotRotationCannotTargetAForeignTeam(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $venueA = $this->createVenue($clubA, $seasonA, 'Coubertin');
        $sm1 = $this->createTeam($clubA, $seasonA, 'SM1');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $foreign = $this->createTeam($clubB, $seasonB, 'Étrangère');

        // A foreign team is invisible through the tenant filters → 422, no cross-club write.
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueA->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30',
            'teamIds' => [$sm1->getId(), $foreign->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(MatchSlotRotation::class)->findBy(['clubId' => $clubA->getId()]));
    }

    public function testFbiIngestionsAreScopedToTheClub(): void
    {
        [$clubA, , $seasonA] = $this->createClubUser('a');
        $this->scopeGucToClub($clubA->getId());
        $this->em->persist(new FbiIngestion($clubA->getId(), $seasonA->getId(), FbiIngestionSource::FBI_XLSX, new DateTimeImmutable, 3, 1, 0, 0, []));
        $this->em->flush();

        [$clubB, $userB] = $this->createClubUser('b');

        // Club B's freshness read (open to any member) sees nothing of club A's deposit.
        $this->client->request('GET', '/api/fbi-ingestions/latest', [], [], $this->authHeaders($userB));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertArrayHasKey('latest', $data);
        self::assertNull($data['latest']);

        // And the RLS-scoped repository confirms the boundary directly.
        $this->scopeGucToClub($clubB->getId());
        self::assertCount(0, $this->em->getRepository(FbiIngestion::class)->findBy(['clubId' => $clubA->getId()]));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createTeam(Club $club, Season $season, string $name): Team
    {
        $this->scopeGucToClub($club->getId());
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createVenue(Club $club, Season $season, string $name): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createMember(Club $club, string $role): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User;
        $user->setEmail($role . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club match ' . $suffix);
        $club->setSlug('club-match-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('match' . $uid . '@test.com');
        $user->setFirstName('Match');
        $user->setLastName('User');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = $this->season($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $this->em->flush();

        return [$club, $user, $season];
    }

    private function season(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        // Matches require a settled season plan (cockpit state 3) — point the plan
        // at a version so these tests exercise the tenant boundary, not the guard.
        $this->settleSeasonPlan($season);

        return $season;
    }

    private function createFixture(Club $club, string $opponent): Fixture
    {
        $this->scopeGucToClub($club->getId());
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]);
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel($opponent);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function createCompetition(Club $club, Season $season, string $name): Competition
    {
        $this->scopeGucToClub($club->getId());
        $competition = new Competition;
        $competition->setClubId($club->getId());
        $competition->setSeasonId($season->getId());
        $competition->setTeamId('11111111-1111-4111-8111-111111111111');
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
