<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * RMM-5 (P2-49) — CRUD du créneau de match partagé (rotation A/B) : le modèle N-aire naît,
 * ordonné, scopé club+saison. Le N-aire marche (2 et 3 membres), la lecture est ouverte au
 * Membre, l'écriture par remplacement, et les refus de saisie sont NOMMÉS (422/403/409).
 */
#[Group('integration')]
final class MatchSlotRotationApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCreatesRotationWithTwoAndThreeMembersInOrder(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Coubertin');
        $sm1 = $this->createTeam($club, $season, 'SM1');
        $sm2 = $this->createTeam($club, $season, 'SM2');
        $sm3 = $this->createTeam($club, $season, 'SM3');
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        // Duo on the shared 20:30 slot.
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30',
            'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $duo = $this->responseData();
        self::assertSame([$sm1->getId(), $sm2->getId()], $duo['teamIds'], 'l\'ordre saisi EST l\'ordre A/B rendu');

        // Trio on another slot: the N-ary model works past 2.
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 7, 'kickoffTime' => '18:00',
            'teamIds' => [$sm3->getId(), $sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        self::assertSame([$sm3->getId(), $sm1->getId(), $sm2->getId()], $this->responseData()['teamIds']);

        // Stored: parent stamped, two members with positions.
        $rotation = $this->em->getRepository(MatchSlotRotation::class)->findOneBy(['id' => $duo['id']]);
        self::assertNotNull($rotation);
        self::assertSame($club->getId(), $rotation->getClubId());
        self::assertSame($season->getId(), $rotation->getSeasonId());
        self::assertSame($venue->getId(), $rotation->getVenueId());
        self::assertSame('20:30', $rotation->getKickoffTime()->format('H:i'));
        $members = $this->em->getRepository(MatchSlotRotationTeam::class)->findBy(['rotationId' => $duo['id']], ['position' => 'ASC']);
        self::assertSame([$sm1->getId(), $sm2->getId()], array_map(static fn (MatchSlotRotationTeam $m): string => $m->getTeamId(), $members));
        self::assertSame([0, 1], array_map(static fn (MatchSlotRotationTeam $m): int => $m->getPosition(), $members));
    }

    public function testReadIsOpenToANonManagementMember(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Coubertin');
        $sm1 = $this->createTeam($club, $season, 'SM1');
        $sm2 = $this->createTeam($club, $season, 'SM2');
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // A plain member (non-management) reads the module.
        $member = $this->createMember($club, 'editor');
        $this->client->request('GET', '/api/match_slot_rotations', [], [], $this->authHeaders($member));
        self::assertResponseStatusCodeSame(200);
        $rows = $this->responseData()['member'] ?? [];
        self::assertCount(1, $rows);
        self::assertSame([$sm1->getId(), $sm2->getId()], $rows[0]['teamIds']);
    }

    public function testReplacingMembersRewritesTheRosterAndOrder(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Coubertin');
        $sm1 = $this->createTeam($club, $season, 'SM1');
        $sm2 = $this->createTeam($club, $season, 'SM2');
        $sm3 = $this->createTeam($club, $season, 'SM3');
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->responseData()['id'];

        // Replace: drop sm1, add sm3, reorder → the roster is the new list, in the new order.
        $this->client->request('PUT', '/api/match_slot_rotations/' . $id, [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm3->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$sm3->getId(), $sm2->getId()], $this->responseData()['teamIds']);

        $this->em->clear();
        $members = $this->em->getRepository(MatchSlotRotationTeam::class)->findBy(['rotationId' => $id], ['position' => 'ASC']);
        self::assertSame([$sm3->getId(), $sm2->getId()], array_map(static fn (MatchSlotRotationTeam $m): string => $m->getTeamId(), $members));
    }

    public function testAtLeastTwoMembersRequired(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Coubertin');
        $sm1 = $this->createTeam($club, $season, 'SM1');

        $this->client->request('POST', '/api/match_slot_rotations', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm1->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(MatchSlotRotation::class)->findBy(['clubId' => $club->getId()]));
    }

    public function testDuplicateMemberRejected(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Coubertin');
        $sm1 = $this->createTeam($club, $season, 'SM1');

        $this->client->request('POST', '/api/match_slot_rotations', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm1->getId(), $sm1->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testForeignTeamRejectedWithoutCrossClubWrite(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Coubertin');
        $sm1 = $this->createTeam($club, $season, 'SM1');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $foreign = $this->createTeam($clubB, $seasonB, 'Étrangère');

        $this->client->request('POST', '/api/match_slot_rotations', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm1->getId(), $foreign->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(MatchSlotRotation::class)->findBy(['clubId' => $club->getId()]), 'aucune écriture sur un membre étranger');
    }

    public function testForeignVenueRejected(): void
    {
        [$clubA, $user, $seasonA] = $this->createClubUser('a');
        $sm1 = $this->createTeam($clubA, $seasonA, 'SM1');
        $sm2 = $this->createTeam($clubA, $seasonA, 'SM2');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $venueB = $this->createVenue($clubB, $seasonB, 'Étranger');

        // The venue of the other club is invisible through the tenant+season filters → 422.
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venueB->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(MatchSlotRotation::class)->findBy(['clubId' => $clubA->getId()]));
    }

    public function testDuplicateSlotRejected(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Coubertin');
        $sm1 = $this->createTeam($club, $season, 'SM1');
        $sm2 = $this->createTeam($club, $season, 'SM2');
        $sm3 = $this->createTeam($club, $season, 'SM3');
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];
        $venueId = $venue->getId();
        $body = static fn (array $teams): string => json_encode([
            'venueId' => $venueId, 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => $teams,
        ], \JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, $body([$sm1->getId(), $sm2->getId()]));
        self::assertResponseStatusCodeSame(201);
        // Same physical slot (venue + day + kickoff) → readable 422, not a DB 500.
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, $body([$sm1->getId(), $sm3->getId()]));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->em->getRepository(MatchSlotRotation::class)->findBy(['clubId' => $club->getId()]));
    }

    public function testDeleteRemovesRotationAndItsMembers(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Coubertin');
        $sm1 = $this->createTeam($club, $season, 'SM1');
        $sm2 = $this->createTeam($club, $season, 'SM2');
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];
        $this->client->request('POST', '/api/match_slot_rotations', [], [], $headers, json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->responseData()['id'];

        $this->client->request('DELETE', '/api/match_slot_rotations/' . $id, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);
        $this->em->clear();
        self::assertNull($this->em->getRepository(MatchSlotRotation::class)->find($id));
        self::assertCount(0, $this->em->getRepository(MatchSlotRotationTeam::class)->findBy(['rotationId' => $id]));
    }

    public function testNonManagementWriteRefused(): void
    {
        [$club, , $season] = $this->createClubUser('a');
        $venue = $this->createVenue($club, $season, 'Coubertin');
        $sm1 = $this->createTeam($club, $season, 'SM1');
        $sm2 = $this->createTeam($club, $season, 'SM2');
        $member = $this->createMember($club, 'editor');

        $this->client->request('POST', '/api/match_slot_rotations', [], [], $this->authHeaders($member) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

    public function testArchivedSeasonWriteRefused(): void
    {
        [$club, $user] = $this->createClubUser('a');
        $this->scopeGucToClub($club->getId());
        $past = $this->season($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1);
        $venue = $this->createVenue($club, $past, 'Coubertin');
        $sm1 = $this->createTeam($club, $past, 'SM1');
        $sm2 = $this->createTeam($club, $past, 'SM2');

        $this->client->request('POST', '/api/match_slot_rotations', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(), 'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'venueId' => $venue->getId(), 'dayOfWeek' => 6, 'kickoffTime' => '20:30', 'teamIds' => [$sm1->getId(), $sm2->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
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
        $club->setName('Club rotation ' . $suffix);
        $club->setSlug('club-rotation-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('rotation' . $uid . '@test.com');
        $user->setFirstName('Rotation');
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

        return $season;
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
