<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\ConflictResolution;
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
     * Lot « une personne = ses équipes » — a person who COACHES team-1 (MAIN) and
     * PLAYS team-2 is double-booked, and each side carries its own role over the
     * wire: MAIN on the coached side, PLAYER on the played side. Severity stays 3
     * (MAIN×PLAYER is a hard clash), coachRole is the aggregate PLAYER.
     */
    public function testMatchMatchCarriesPerSideRolesForACoachWhoAlsoPlays(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('cp', playsSecondTeam: true);

        $conflicts = $this->conflictsFor($user);
        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_MATCH', $conflicts[0]['type']);
        self::assertSame(3, $conflicts[0]['severity']);
        self::assertSame('PLAYER', $conflicts[0]['coachRole']);
        // team-1 (16:00, window 15:30) is chronologically first → left, coached MAIN;
        // team-2 (16:30) → right, played PLAYER.
        self::assertSame('MAIN', $conflicts[0]['left']['role']);
        self::assertSame('PLAYER', $conflicts[0]['right']['role']);
    }

    /**
     * §7.1 tenant axis — a player membership of ANOTHER club never leaks a conflict
     * into the caller's radar (the CoachPlayerMembership tenant filter applies like
     * every other loaded entity). Club B's coach↔player overlap stays invisible to A.
     */
    public function testMembershipsOfAnotherClubRaiseNoConflictForTheCaller(): void
    {
        [, $userA, $coachAId] = $this->createClubWithOverlappingMatches('ma');
        [, , $coachBId] = $this->createClubWithOverlappingMatches('mb', playsSecondTeam: true);

        $conflicts = $this->conflictsFor($userA);
        $coachIds = array_map(static fn (array $c): string => $c['coachId'], $conflicts);
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

    /**
     * D1 rule 3 — a match already played no longer surfaces on the radar. The
     * clock is pinned to 2026-09-01 (see setUp), so this past pair (2026-08-01)
     * sits behind the club's civil today while the future pairs of the other
     * tests (2026-10-04) stay ahead of it.
     */
    public function testPastMatchesNoLongerSurface(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('past', '2026-08-01');

        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);

        self::assertSame([], $this->responseData()['conflicts'], 'un match passé ne remonte plus (D1 règle 3)');
    }

    // ── P4-207 « Résolution des conflits » (champ additif + PUT/DELETE) ──

    public function testGetCarriesNullResolutionByDefault(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('rn');

        $conflicts = $this->conflictsFor($user);
        self::assertCount(1, $conflicts);
        self::assertArrayHasKey('resolution', $conflicts[0], 'le champ resolution est ADDITIF, toujours présent');
        self::assertNull($conflicts[0]['resolution'], 'aucune ligne = « à traiter » = null');
    }

    public function testPutThenGetCarriesTheResolution(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('put');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'DEROGATION_REQUESTED', 'note' => 'Vu avec la ligue']);
        self::assertResponseStatusCodeSame(200);
        $put = $this->responseData();
        self::assertSame($fingerprint, $put['fingerprint']);
        self::assertSame('DEROGATION_REQUESTED', $put['resolution']['status']);

        $conflict = $this->conflictsFor($user)[0];
        self::assertSame('DEROGATION_REQUESTED', $conflict['resolution']['status']);
        self::assertSame('Vu avec la ligue', $conflict['resolution']['note']);
        self::assertArrayHasKey('updatedAt', $conflict['resolution']);
    }

    public function testPutOnTheSameFingerprintReplacesTheRow(): void
    {
        [$club, $user] = $this->createClubWithOverlappingMatches('rep');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'DEROGATION_REQUESTED']);
        self::assertResponseStatusCodeSame(200);
        $this->putResolution($user, $fingerprint, ['status' => 'RESOLVED_INTERNALLY']);
        self::assertResponseStatusCodeSame(200);

        self::assertSame('RESOLVED_INTERNALLY', $this->conflictsFor($user)[0]['resolution']['status']);
        $this->scopeGucToClub($club->getId());
        self::assertCount(1, $this->em->getRepository(ConflictResolution::class)->findBy([]), 'un upsert, jamais une seconde ligne');
    }

    public function testDeleteResetsToNullAndIsIdempotent(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('del');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];
        $this->putResolution($user, $fingerprint, ['status' => 'NO_SOLUTION_YET']);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('DELETE', $this->resolutionUrl($fingerprint), [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->conflictsFor($user)[0]['resolution']);

        // « À traiter » EST l'absence de ligne : un second DELETE reste 204.
        $this->client->request('DELETE', $this->resolutionUrl($fingerprint), [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);
    }

    public function testUnknownStatusIs422(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('st');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'A_TRAITER']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNoteOver500CharactersIs422(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('nt');
        $fingerprint = $this->conflictsFor($user)[0]['fingerprint'];

        $this->putResolution($user, $fingerprint, ['status' => 'DEROGATION_REQUESTED', 'note' => str_repeat('x', 501)]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testWellFormedButAbsentFingerprintIs422(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('ab');
        // Passes the route requirement (TYPE:uuid) but is nowhere in the current radar.
        $absent = 'AWAY_NO_FOOTPRINT:11111111-1111-4111-8111-111111111111';

        $this->putResolution($user, $absent, ['status' => 'DEROGATION_REQUESTED']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMalformedFingerprintIs404(): void
    {
        [, $user] = $this->createClubWithOverlappingMatches('mf');

        // Lowercase, no TYPE:field shape → the route requirement rejects it (routing 404).
        $this->client->request('PUT', '/api/fixtures/conflicts/pas-une-empreinte/resolution', [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['status' => 'DEROGATION_REQUESTED'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Pin the civil today so the seeded dates keep their past/future relation
        // whatever the wall clock — 2026-10-04 fixtures stay ahead, 2026-08-01
        // behind (D1 rule 3 filters strictly past matches).
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-01 10:00:00'));
    }

    protected function tearDown(): void
    {
        // Redis is shared and not rolled back — never leak the pin into another test.
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    /**
     * A club whose single coach runs two teams playing overlapping matches on the
     * same day → exactly one MATCH_MATCH conflict. When $playsSecondTeam is true the
     * person only COACHES team-1 (MAIN) and PLAYS team-2 (an active
     * CoachPlayerMembership) — the founder case that unions coaches with players.
     *
     * @return array{0: Club, 1: User, 2: string} club, user, coachId
     */
    private function createClubWithOverlappingMatches(string $suffix, string $matchDate = '2026-10-04', bool $playsSecondTeam = false): array
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
        // Coach both teams, OR coach team-1 and PLAY team-2 (the coach↔player case).
        $coachedTeams = $playsSecondTeam ? [$team1] : [$team1, $team2];
        foreach ($coachedTeams as $teamId) {
            $link = new TeamCoach;
            $link->setClubId($club->getId());
            $link->setSeasonId($season->getId());
            $link->setTeamId($teamId);
            $link->setCoachId($coach->getId());
            $link->setRole(TeamCoachRole::MAIN);
            $this->em->persist($link);
        }
        if ($playsSecondTeam) {
            $membership = new CoachPlayerMembership;
            $membership->setClubId($club->getId());
            $membership->setSeasonId($season->getId());
            $membership->setCoachId($coach->getId());
            $membership->setTeamId($team2);
            $membership->setIsActive(true);
            $this->em->persist($membership);
        }

        // Two home matches of the coach's two teams, windows 15:30–17:45 and
        // 16:00–18:15 → overlap.
        $this->fixture($club, $season, $team1, '16:00', $matchDate);
        $this->fixture($club, $season, $team2, '16:30', $matchDate);
        $this->em->flush();

        return [$club, $user, $coach->getId()];
    }

    private function fixture(Club $club, Season $season, string $teamId, string $kickoff, string $matchDate): void
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable($matchDate));
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
     * @return list<array<string, mixed>>
     */
    private function conflictsFor(User $user): array
    {
        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);

        /** @var list<array<string, mixed>> $conflicts */
        $conflicts = $this->responseData()['conflicts'];

        return $conflicts;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function putResolution(User $user, string $fingerprint, array $body): void
    {
        $this->client->request('PUT', $this->resolutionUrl($fingerprint), [], [], $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function resolutionUrl(string $fingerprint): string
    {
        return '/api/fixtures/conflicts/' . $fingerprint . '/resolution';
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
