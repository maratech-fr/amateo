<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * On-the-fly conflict radar (spec gestion-matchs PR-2): the endpoint surfaces a
 * coach's overlapping matches, and stays strictly scoped to the caller's club
 * (§7.1 tenant axis — one club never sees another's conflicts).
 */
#[Group('phase1')]
#[Group('integration')]
final class FixtureConflictsApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testReturnsSameCoachMatchMatchConflict(): void
    {
        [, $userA, $coachAId] = $this->createClubWithOverlappingMatches('a');

        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);

        $data = $this->responseData();
        $conflicts = $data['conflicts'];
        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_MATCH', $conflicts[0]['type']);
        self::assertSame($coachAId, $conflicts[0]['coachId']);
        self::assertArrayHasKey('left', $conflicts[0]);
        self::assertArrayHasKey('right', $conflicts[0]);
        // P1-4 PR E2 — gravity is emitted by the SERVER (the UI only groups):
        // a MAIN coach double-booked = severity 3.
        self::assertSame(3, $conflicts[0]['severity']);
        self::assertSame('MAIN', $conflicts[0]['coachRole']);
    }

    public function testConflictsAreScopedToTheCallersClub(): void
    {
        [, $userA, $coachAId] = $this->createClubWithOverlappingMatches('a');
        [, , $coachBId] = $this->createClubWithOverlappingMatches('b');

        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);

        $coachIds = array_map(static fn (array $c): string => $c['coachId'], $this->responseData()['conflicts']);
        self::assertSame([$coachAId], array_values(array_unique($coachIds)));
        self::assertNotContains($coachBId, $coachIds);
    }

    /**
     * RMM-3 — le contrat HTTP porte désormais un champ ADDITIF `fingerprint` sur
     * chaque item : l'identité stable du conflit, calculée en aval du détecteur. Le
     * gardien (POST /api/matches/module-visit) s'en sert pour dire ce qui est neuf.
     */
    public function testEveryConflictCarriesAStableFingerprint(): void
    {
        [, $userA, $coachAId] = $this->createClubWithOverlappingMatches('fp');

        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);

        $conflicts = $this->responseData()['conflicts'];
        self::assertCount(1, $conflicts);
        self::assertArrayHasKey('fingerprint', $conflicts[0], 'chaque item porte son empreinte');
        $fingerprint = $conflicts[0]['fingerprint'];
        self::assertIsString($fingerprint);
        self::assertStringStartsWith('MATCH_MATCH:' . $coachAId . ':', $fingerprint, 'l\'empreinte porte le type et le coach, jamais la sévérité ni le segment');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A club whose single coach runs two teams playing overlapping matches on the
     * same day → exactly one MATCH_MATCH conflict.
     *
     * @return array{0: Club, 1: User, 2: string} club, user, coachId
     */
    private function createClubWithOverlappingMatches(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club conflict ' . $suffix);
        $club->setSlug('club-conflict-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('conflict' . $uid . '@test.com');
        $user->setFirstName('Con');
        $user->setLastName('Flict');
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

        $coach = new Coach;
        $coach->setClubId($club->getId());
        $coach->setSeasonId($season->getId());
        $coach->setFirstName('Coach');
        $coach->setLastName($suffix);
        $this->em->persist($coach);
        $this->em->flush();

        $team1 = $this->uuid($suffix, 1);
        $team2 = $this->uuid($suffix, 2);
        foreach ([$team1, $team2] as $teamId) {
            $link = new TeamCoach;
            $link->setClubId($club->getId());
            $link->setSeasonId($season->getId());
            $link->setTeamId($teamId);
            $link->setCoachId($coach->getId());
            $link->setRole(TeamCoachRole::MAIN);
            $this->em->persist($link);
        }

        // Two home matches of the coach's two teams, windows 15:30–17:45 and
        // 16:00–18:15 → overlap.
        $this->fixture($club, $season, $team1, '16:00');
        $this->fixture($club, $season, $team2, '16:30');
        $this->em->flush();

        return [$club, $user, $coach->getId()];
    }

    private function fixture(Club $club, Season $season, string $teamId, string $kickoff): void
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null);
        $this->em->persist($fixture);
    }

    private function uuid(string $suffix, int $n): string
    {
        $hex = substr(md5($suffix . $n), 0, 12);

        return \sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), '111', '111', '111111111111');
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
