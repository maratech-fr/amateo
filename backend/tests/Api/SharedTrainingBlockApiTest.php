<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SchedulePlanType;
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
 * P2-51 PR-1 — CRUD du bloc de mutualisation : le modèle N-aire naît, scopé club+saison, filtrable
 * par portée (socle NULL vs plan de période). La lecture est ouverte au Membre, l'écriture par
 * remplacement, l'isolation tenant tenue, et chaque refus de saisie est NOMMÉ (422/403).
 */
#[Group('integration')]
final class SharedTrainingBlockApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCreatesBlockAtSeasonBaseAndReadsItBack(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 2);
        $t2 = $this->createTeam($club, $season, 'U9F2', 2);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/shared_training_blocks', [], [], $headers, json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId(), $t2->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = $this->responseData();
        self::assertNull($created['schedulePlanId']);
        self::assertSame($this->sorted([$t1->getId(), $t2->getId()]), $created['teamIds']);
        self::assertSame(1, $created['commonSessions']);

        // Stored: parent stamped + two member rows.
        $block = $this->em->getRepository(SharedTrainingBlock::class)->find($created['id']);
        self::assertNotNull($block);
        self::assertSame($club->getId(), $block->getClubId());
        self::assertSame($season->getId(), $block->getSeasonId());
        self::assertNull($block->getSchedulePlanId());
        self::assertCount(2, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $created['id']]));
    }

    public function testScopeFilterSeparatesBaseFromPeriod(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 2);
        $t2 = $this->createTeam($club, $season, 'U9F2', 2);
        $plan = $this->periodPlan($club, $season);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        // Un bloc SOCLE et un bloc de PÉRIODE, mêmes équipes : deux mondes, tous deux acceptés.
        $this->client->request('POST', '/api/shared_training_blocks', [], [], $headers, json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId(), $t2->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $this->client->request('POST', '/api/shared_training_blocks', [], [], $headers, json_encode([
            'schedulePlanId' => $plan->getId(), 'teamIds' => [$t1->getId(), $t2->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // ?schedulePlanId= ne rend QUE la période.
        $this->client->request('GET', '/api/shared_training_blocks?schedulePlanId=' . $plan->getId(), [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $rows = $this->responseData()['member'] ?? [];
        self::assertCount(1, $rows);
        self::assertSame($plan->getId(), $rows[0]['schedulePlanId']);
    }

    public function testReadIsOpenToANonManagementMember(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 2);
        $t2 = $this->createTeam($club, $season, 'U9F2', 2);
        $this->client->request('POST', '/api/shared_training_blocks', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId(), $t2->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $member = $this->createMember($club, 'editor');
        $this->client->request('GET', '/api/shared_training_blocks', [], [], $this->authHeaders($member));
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->responseData()['member'] ?? []);
    }

    public function testReplacingMembersRewritesTheRoster(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 2);
        $t2 = $this->createTeam($club, $season, 'U9F2', 2);
        $t3 = $this->createTeam($club, $season, 'U9M1', 2);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/shared_training_blocks', [], [], $headers, json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId(), $t2->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->responseData()['id'];

        $this->client->request('PUT', '/api/shared_training_blocks/' . $id, [], [], $headers, json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t3->getId(), $t2->getId()], 'commonSessions' => 2,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->sorted([$t3->getId(), $t2->getId()]), $this->responseData()['teamIds']);
        self::assertSame(2, $this->responseData()['commonSessions']);

        $this->em->clear();
        $members = $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $id], ['teamId' => 'ASC']);
        self::assertSame($this->sorted([$t3->getId(), $t2->getId()]), array_map(static fn (SharedTrainingBlockTeam $m): string => $m->getTeamId(), $members));
    }

    public function testAtLeastTwoMembersRequired(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 2);

        $this->client->request('POST', '/api/shared_training_blocks', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $club->getId()]));
    }

    public function testDuplicateMemberRejected(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 2);

        $this->client->request('POST', '/api/shared_training_blocks', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId(), $t1->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testCommonSessionsSumOverCapRejected(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $shared = $this->createTeam($club, $season, 'U9F1', 2); // 2 séances
        $a = $this->createTeam($club, $season, 'U9F2', 2);
        $b = $this->createTeam($club, $season, 'U9M1', 2);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/shared_training_blocks', [], [], $headers, json_encode([
            'schedulePlanId' => null, 'teamIds' => [$shared->getId(), $a->getId()], 'commonSessions' => 2,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        // Σ(shared) = 2 + 1 = 3 > 2 → 422.
        $this->client->request('POST', '/api/shared_training_blocks', [], [], $headers, json_encode([
            'schedulePlanId' => null, 'teamIds' => [$shared->getId(), $b->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $club->getId()]));
    }

    public function testSameTeamSetInSameScopeRejected(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 3);
        $t2 = $this->createTeam($club, $season, 'U9F2', 3);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/shared_training_blocks', [], [], $headers, json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId(), $t2->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $this->client->request('POST', '/api/shared_training_blocks', [], [], $headers, json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t2->getId(), $t1->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $club->getId()]));
    }

    public function testForeignTeamRejectedWithoutCrossClubWrite(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 2);
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $foreign = $this->createTeam($clubB, $seasonB, 'Étrangère', 2);

        $this->client->request('POST', '/api/shared_training_blocks', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId(), $foreign->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $club->getId()]), 'aucune écriture sur un membre étranger');
    }

    public function testDeleteRemovesBlockAndItsMembers(): void
    {
        [$club, $user, $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 2);
        $t2 = $this->createTeam($club, $season, 'U9F2', 2);
        $headers = $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'];
        $this->client->request('POST', '/api/shared_training_blocks', [], [], $headers, json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId(), $t2->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->responseData()['id'];

        $this->client->request('DELETE', '/api/shared_training_blocks/' . $id, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);
        $this->em->clear();
        self::assertNull($this->em->getRepository(SharedTrainingBlock::class)->find($id));
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $id]));
    }

    public function testNonManagementWriteRefused(): void
    {
        [$club, , $season] = $this->createClubUser('a');
        $t1 = $this->createTeam($club, $season, 'U9F1', 2);
        $t2 = $this->createTeam($club, $season, 'U9F2', 2);
        $member = $this->createMember($club, 'editor');

        $this->client->request('POST', '/api/shared_training_blocks', [], [], $this->authHeaders($member) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'schedulePlanId' => null, 'teamIds' => [$t1->getId(), $t2->getId()], 'commonSessions' => 1,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function periodPlan(Club $club, Season $season): SchedulePlan
    {
        $this->scopeGucToClub($club->getId());
        $plan = (new SchedulePlan)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setType(SchedulePlanType::HOLIDAY)
            ->setName('Vacances')
            ->setStartDate(new DateTimeImmutable('2025-10-20'))
            ->setEndDate(new DateTimeImmutable('2025-10-26'));
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    private function createTeam(Club $club, Season $season, string $name, int $sessionsPerWeek): Team
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
        $category->setName('U9-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName($name);
        $team->setSessionsPerWeek($sessionsPerWeek);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
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
        $club->setName('Club bloc ' . $suffix);
        $club->setSlug('club-bloc-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('bloc' . $uid . '@test.com');
        $user->setFirstName('Bloc');
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

        $season = new Season;
        $season->setClubId($club->getId());
        $startYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $user, $season];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
