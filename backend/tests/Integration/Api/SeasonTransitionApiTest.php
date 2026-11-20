<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * POST /api/seasons/{id}/transition — auth/role/precondition surface.
 * The copy semantics themselves are covered by SeasonTransitionServiceTest.
 */
#[Group('phase1')]
#[Group('integration')]
final class SeasonTransitionApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('POST', '/api/seasons/00000000-0000-4000-8000-000000000000/transition');
        self::assertResponseStatusCodeSame(401);
    }

    public function testNonManagementMemberIsForbidden(): void
    {
        [$user, , $season] = $this->createClubUserSeason('member');

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(403);
    }

    public function testNominalTransitionCreatesTheDraftSeason(): void
    {
        [$user, , $season] = $this->createClubUserSeason('admin');

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        self::assertNotSame($season->getId(), $data['seasonId']);
        self::assertSame($season->getStartDate()->modify('+1 year')->format('Y-m-d'), $data['startDate']);
        self::assertIsArray($data['counts']);
    }

    public function testTransitionIsGatedOnThePaidSeason(): void
    {
        // NR P1-5 (axe planning lifecycle / monétisation, décision fondateur
        // 2026-08-04) — l'abonnement se paie PAR SAISON : la bascule vers N+1
        // exige que N+1 soit réglée. Générer sa saison suivante dès mai puis
        // résilier était la fuite ; le gate est LA BASCULE, pas la génération.
        [$user, $club, $season] = $this->createClubUserSeason('admin');
        $auth = $this->authHeaders($user);

        // Club en règle pour SA saison seulement (l'état d'une inscription) :
        // la suivante n'est pas payée → 409 qui NOMME la saison due.
        $club->setPaidSeasonYear(SeasonResolver::seasonYear($season->getStartDate()));
        $this->em->flush();

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $auth);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('n\'est pas réglée', (string) $this->responseData()['error']);

        // Le geste support (SA4) : « Marquer la saison suivante payée » ouvre le gate.
        $tester = new CommandTester(new Application(self::$kernel)->find('app:clubs:mark-next-season-paid'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--club' => $club->getId()]));

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $auth);
        self::assertResponseStatusCodeSame(201);

        // Idempotent, et le marqueur ne recule jamais.
        self::assertSame(Command::SUCCESS, $tester->execute(['--club' => $club->getId()]));
    }

    public function testRerunConflictsWithTheExistingSuccessorId(): void
    {
        [$user, , $season] = $this->createClubUserSeason('admin');
        $auth = $this->authHeaders($user);

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $auth);
        self::assertResponseStatusCodeSame(201);
        $createdId = $this->responseData()['seasonId'];

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $auth);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($createdId, $this->responseData()['existingSeasonId']);
    }

    public function testPastSeasonSourceIsRefused(): void
    {
        [$user, $club] = $this->createClubUserSeason('admin');
        $this->scopeGucToClub($club->getId());
        $past = $this->season($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1);
        $this->em->flush();

        $this->client->request('POST', '/api/seasons/' . $past->getId() . '/transition', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignSeasonIsNotFound(): void
    {
        [$user] = $this->createClubUserSeason('admin');
        [, , $foreignSeason] = $this->createClubUserSeason('admin');

        $this->client->request('POST', '/api/seasons/' . $foreignSeason->getId() . '/transition', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Contract the P2 re-dating step relies on (100% frontend, no new backend):
     * events of the SOURCE season are readable with an explicit X-Season-Id, a
     * kept event POSTed with the DRAFT's X-Season-Id is stamped into the draft,
     * and neither season sees the other's events.
     */
    public function testRedatingContractCrossSeasonReadAndDraftWrite(): void
    {
        [$user, , $season] = $this->createClubUserSeason('admin');
        $auth = $this->authHeaders($user);

        // An event in N (created via the API on the current season).
        $this->client->request('POST', '/api/calendar_entries', [], [], [...$auth, 'CONTENT_TYPE' => 'application/ld+json'], json_encode([
            'kind' => 'event', 'title' => 'AG du club', 'startDate' => '2026-05-12', 'endDate' => '2026-05-12', 'isDisruptive' => true, 'status' => 'active',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Prepare N+1.
        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $auth);
        self::assertResponseStatusCodeSame(201);
        $draftId = $this->responseData()['seasonId'];

        // Read N's events with an explicit X-Season-Id (the re-dating source list).
        $this->client->request('GET', '/api/calendar_entries?kind=event', [], [], [...$auth, 'HTTP_X-Season-Id' => $season->getId()]);
        self::assertResponseStatusCodeSame(200);
        $titles = array_map(static fn (array $m): string => $m['title'], $this->responseData()['member'] ?? []);
        self::assertSame(['AG du club'], $titles);

        // POST the kept event with the draft's X-Season-Id → stamped into N+1.
        $this->client->request('POST', '/api/calendar_entries', [], [], [...$auth, 'HTTP_X-Season-Id' => $draftId, 'CONTENT_TYPE' => 'application/ld+json'], json_encode([
            'kind' => 'event', 'title' => 'AG du club', 'startDate' => '2027-05-11', 'endDate' => '2027-05-11', 'isDisruptive' => true, 'status' => 'active',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // The draft sees exactly its own event; N still sees only its own.
        $this->client->request('GET', '/api/calendar_entries?kind=event', [], [], [...$auth, 'HTTP_X-Season-Id' => $draftId]);
        $draftMembers = $this->responseData()['member'] ?? [];
        self::assertCount(1, $draftMembers);
        self::assertSame('2027-05-11', substr((string) $draftMembers[0]['startDate'], 0, 10));

        $this->client->request('GET', '/api/calendar_entries?kind=event', [], [], [...$auth, 'HTTP_X-Season-Id' => $season->getId()]);
        self::assertCount(1, $this->responseData()['member'] ?? []);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubUserSeason(string $role): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club api-transition');
        $club->setSlug('club-api-transition-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('TRB' . strtoupper(substr(md5($uid), 0, 10)));
        // P1-5 : en règle très loin par défaut — le gate de paiement a ses
        // propres tests (testTransitionIsGatedOnThePaidSeason), les autres
        // couvrent auth/copie/redatage.
        $club->setPaidSeasonYear(9999);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('transition' . $uid . '@test.com');
        $user->setFirstName('Trans');
        $user->setLastName('Ition');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = $this->season($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $this->em->flush();

        return [$user, $club, $season];
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
