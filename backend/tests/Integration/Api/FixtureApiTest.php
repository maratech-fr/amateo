<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
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
 * Fixture CRUD surface (spec gestion-matchs palier A): friendly (no
 * competition), home placement (venue + kickoff), and DTO validation.
 */
#[Group('phase1')]
#[Group('integration')]
final class FixtureApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const TEAM_ID = '11111111-1111-4111-8111-111111111111';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $user;

    public function testCreatesAFriendlyWithNoCompetition(): void
    {
        $data = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Amical voisin',
        ]);
        self::assertResponseStatusCodeSame(201);
        // Null props are omitted from the serialized output.
        self::assertNull($data['competitionId'] ?? null);
        self::assertSame('UNPLACED', $data['status']);
        self::assertNull($data['kickoffTime'] ?? null);
    }

    public function testAManualCreationIsTreatedAndExposesTheReviewFields(): void
    {
        // PR-3a — a hand-entered rencontre is a manager gesture → REVIEWED, and
        // the resource serves reviewState/reviewedAt/pendingDeviations/ffbbRencontreId.
        $data = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Amical voisin',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('REVIEWED', $data['reviewState']);
        self::assertNotNull($data['reviewedAt'] ?? null);
        self::assertSame([], $data['pendingDeviations']);
        self::assertNull($data['ffbbRencontreId'] ?? null);
    }

    public function testPuttingStatusPlacedTreatsTheFixtureAndCannotWriteReviewState(): void
    {
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
        ]);
        // A forged reviewState in the PUT body is ignored (FixtureInput has no such
        // field); the D6 rule alone drives it — placing treats the fixture.
        $this->putFixture($created['id'], [
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
            'reviewState' => 'NEW',
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('REVIEWED', $this->responseData()['reviewState'], 'placing treats it (D6), the echoed reviewState is ignored');
    }

    public function testPlacesAHomeFixtureWithVenueAndKickoff(): void
    {
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
        ]);

        // PUT = full replace → resend the required identity fields plus the placement.
        $this->client->request('PUT', '/api/fixtures/' . $created['id'], [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-08',
            'homeAway' => 'HOME',
            'opponentLabel' => 'À placer',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame('16:30', $data['kickoffTime']);
        self::assertSame('PLACED', $data['status']);
        self::assertSame('22222222-2222-4222-8222-222222222222', $data['venueId']);
    }

    public function testHandBackToSolverOnUntouchedPlacementEcho(): void
    {
        $placed = $this->createPlaced('2026-11-15');
        $this->putFixture($placed['id'], $placed + ['placementSource' => 'SOLVER']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('SOLVER', $this->responseData()['placementSource']);
    }

    public function testHandBackRejectedWhenPlacementChangesInTheSamePut(): void
    {
        $placed = $this->createPlaced('2026-11-22');
        $this->putFixture($placed['id'], ['kickoffTime' => '18:00', 'placementSource' => 'SOLVER'] + $placed);
        self::assertResponseStatusCodeSame(422);
    }

    public function testHandBackRejectedWhenTheMatchLeavesPlaced(): void
    {
        $placed = $this->createPlaced('2026-11-29');
        $this->putFixture($placed['id'], ['status' => 'UNPLACED', 'placementSource' => 'SOLVER'] + $placed);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEchoPutWithoutSourceStampsManual(): void
    {
        $placed = $this->createPlaced('2026-12-06');
        $this->putFixture($placed['id'], $placed);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('MANUAL', $this->responseData()['placementSource']);
    }

    public function testUnplacingClearsThePlacementSource(): void
    {
        $placed = $this->createPlaced('2026-12-13');
        $this->putFixture($placed['id'], ['status' => 'UNPLACED', 'venueId' => '', 'kickoffTime' => ''] + $placed);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseData()['placementSource'] ?? null);
    }

    public function testCreatingWithSolverSourceIsRejected(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-12-20',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Faux solveur',
            'placementSource' => 'SOLVER',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsMalformedKickoffTime(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Bad time',
            'kickoffTime' => '25h',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsUnknownHomeAway(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'matchDate' => '2026-11-01',
            'homeAway' => 'NEUTRAL',
            'opponentLabel' => 'Bad side',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsNonUuidTeamId(): void
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => 'not-a-uuid',
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Bad id',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsCompetitionOutsideScope(): void
    {
        // A well-formed but out-of-scope competition id (tenant/season filter hides
        // it → the processor cannot resolve it) must be rejected, not silently kept.
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => self::TEAM_ID,
            'competitionId' => '33333333-3333-4333-8333-333333333333',
            'matchDate' => '2026-11-01',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Ghost competition',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->createClubUser();
    }

    /** @return array<string, mixed> the full-replace body of a freshly PLACED fixture */
    private function createPlaced(string $date): array
    {
        $created = $this->post([
            'teamId' => self::TEAM_ID,
            'matchDate' => $date,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Boucle manuelle',
        ]);
        $body = [
            'teamId' => self::TEAM_ID,
            'matchDate' => $date,
            'homeAway' => 'HOME',
            'opponentLabel' => 'Boucle manuelle',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'kickoffTime' => '16:30',
            'status' => 'PLACED',
        ];
        $this->putFixture($created['id'], $body);
        self::assertResponseStatusCodeSame(200);

        return ['id' => $created['id']] + $body;
    }

    /** @param array<string, mixed> $body */
    private function putFixture(string $id, array $body): void
    {
        unset($body['id']);
        $this->client->request('PUT', '/api/fixtures/' . $id, [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders() + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->responseData();
    }

    private function createClubUser(): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club fixture');
        $club->setSlug('club-fixture-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('fixture' . $uid . '@test.com');
        $user->setFirstName('Fix');
        $user->setLastName('Ture');
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
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        // Matches need a settled season plan (cockpit state 3); point the plan at a
        // version so this API test targets fixture behaviour, not the socle guard.
        $this->settleSeasonPlan($season);

        return $user;
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);

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
