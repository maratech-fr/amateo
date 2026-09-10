<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CompetitionType;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR of the FFBB pairing endpoints (P1-4 PR F, §7.1 tenant + périmètre engagé
 * axes): GET /api/ffbb/engagements and POST /api/ffbb/engagements/confirm are
 * management-gated, archived seasons refuse the write (409), a pairing can
 * never target another club's team (invisible through the filters → 422, no
 * cross-tenant write), poule size/opponents come from the SERVER re-read (a
 * forged expectedMatchdays is ignored), and pairing creates Competition rows
 * but never a Fixture — the paired team stays deletable (engagement is born
 * from fixtures, not competitions).
 *
 * The FFBB backend is the deterministic test stub (services_test.yaml):
 * integration tests never touch the federation.
 */
#[Group('phase1')]
#[Group('integration')]
final class FfbbPairingAuthorizationTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testListAsNonAdminMemberReturns403(): void
    {
        [, , $clubA] = $this->register('FFPA');
        $editorToken = $this->addActiveMember($clubA, 'editor');

        $this->client->request('GET', '/api/ffbb/engagements', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
        ]);
        self::assertResponseStatusCodeSame(403, 'the engagement list leaks club structure — management only');
    }

    public function testConfirmAsNonAdminMemberReturns403(): void
    {
        [, , $clubA] = $this->register('FFPB');
        $editorToken = $this->addActiveMember($clubA, 'editor');

        $this->client->request('POST', '/api/ffbb/engagements/confirm', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken, 'CONTENT_TYPE' => 'application/json',
        ], '{"pairings":[]}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testConfirmOnArchivedSeasonReturns409(): void
    {
        [$tokenA, , $clubA] = $this->register('FFPC');
        $this->scopeGucToClub($clubA);
        $currentYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->createSeason($clubA, $currentYear);
        $past = $this->createSeason($clubA, $currentYear - 1);

        $this->client->request('POST', '/api/ffbb/engagements/confirm', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], '{"pairings":[{"ffbbCompetitionId":"x","teamId":"y"}]}');
        self::assertResponseStatusCodeSame(409, 'archived-season writes must be refused');
    }

    public function testConfirmCannotTargetAForeignTeam(): void
    {
        [$tokenA, , $clubA] = $this->register('FFPD');
        $this->useStubClubCode($clubA);
        $this->createTeam($clubA); // settles club A's socle — the gate passes, the pairing must fail
        [, , $clubB] = $this->register('FFPE');
        $teamB = $this->createTeam($clubB);

        $this->client->request('POST', '/api/ffbb/engagements/confirm', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA, 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['pairings' => [[
            'ffbbCompetitionId' => FfbbHttpClientStub::COMPETITION_ID,
            'teamId' => $teamB->getId(),
        ]]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422, 'a foreign team is invisible through the filters');

        $this->scopeGucToClub($clubB);
        self::assertCount(
            0,
            $this->em->getRepository(Competition::class)->findBy(['teamId' => $teamB->getId()]),
            'a foreign pairing must never write into the other club',
        );
    }

    public function testConfirmPairsFreezesCompletenessAndNeverEngagesTheTeam(): void
    {
        [$tokenA, , $clubA] = $this->register('FFPF');
        $this->useStubClubCode($clubA);
        $team = $this->createTeam($clubA);

        $this->client->request('POST', '/api/ffbb/engagements/confirm', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA, 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['pairings' => [[
            'ffbbCompetitionId' => FfbbHttpClientStub::COMPETITION_ID,
            'teamId' => $team->getId(),
            // Forged by a hostile client — must be IGNORED (server re-read wins).
            'expectedMatchdays' => 1,
        ]]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubA);
        $this->em->clear();
        $competition = $this->em->getRepository(Competition::class)->findOneBy(['teamId' => $team->getId()]);
        self::assertNotNull($competition);
        self::assertSame(FfbbHttpClientStub::COMPETITION_ID, $competition->getFfbbCompetitionId());
        self::assertSame(FfbbHttpClientStub::POULE_ID, $competition->getFfbbPouleId());
        self::assertSame('Pré test masculine', $competition->getFfbbCompetitionName());
        // Poule of 4 clubs → 2×(4−1) = 6, from the SERVER re-read, not the client.
        self::assertSame(6, $competition->getExpectedMatchdays());
        self::assertSame(FfbbHttpClientStub::POULE_CLUBS, $competition->getFfbbPouleOpponents());

        // Périmètre engagé (§7.1): pairing creates a Competition, NEVER a
        // Fixture — the team carries no match and stays deletable.
        $this->client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);
        self::assertResponseStatusCodeSame(204, 'pairing must not engage the team (no fixture created)');
    }

    public function testConfirmACupNameInfersCupTypeAndClearsMatchdays(): void
    {
        // P4-195 — un engagement dont le nom porte « coupe » naît en type CUP, et
        // sa complétude « 2×(N−1) » est NULL (une coupe ne se compte pas ainsi).
        [$tokenA, , $clubA] = $this->register('FFPG');
        $this->useStubClubCode($clubA);
        $team = $this->createTeam($clubA);

        $this->confirm($tokenA, FfbbHttpClientStub::COMPETITION_ID_CUP, $team->getId());
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubA);
        $this->em->clear();
        $competition = $this->em->getRepository(Competition::class)->findOneBy(['ffbbCompetitionId' => FfbbHttpClientStub::COMPETITION_ID_CUP]);
        self::assertNotNull($competition);
        self::assertSame(CompetitionType::CUP, $competition->getCompetitionType(), 'a « coupe » name infers CUP');
        self::assertNull($competition->getExpectedMatchdays(), 'a cup carries no 2×(N−1) completeness');
    }

    public function testConfirmAChampionshipKeepsTwoTimesNMinusOne(): void
    {
        // P4-195 — un championnat garde 2×(N−1) (le type par défaut est inchangé).
        [$tokenA, , $clubA] = $this->register('FFPH');
        $this->useStubClubCode($clubA);
        $team = $this->createTeam($clubA);

        $this->confirm($tokenA, FfbbHttpClientStub::COMPETITION_ID, $team->getId());
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubA);
        $this->em->clear();
        $competition = $this->em->getRepository(Competition::class)->findOneBy(['ffbbCompetitionId' => FfbbHttpClientStub::COMPETITION_ID]);
        self::assertNotNull($competition);
        self::assertSame(CompetitionType::CHAMPIONSHIP, $competition->getCompetitionType());
        self::assertSame(6, $competition->getExpectedMatchdays(), 'poule of 4 → 2×(4−1) = 6, untouched');
    }

    public function testConfirmRepostsCupOnAnExistingChampionshipWhoseNameInfersCup(): void
    {
        // P4-195 (sous-décision fondateur) — une compétition RÉUTILISÉE dont le nom
        // infère CUP mais stockée en CHAMPIONSHIP (avec une complétude figée) est
        // REPASSÉE en CUP, journées effacées — répare la vraie Coupe du Rhône (1/68).
        [$tokenA, , $clubA] = $this->register('FFPI');
        $this->useStubClubCode($clubA);
        $team = $this->createTeam($clubA);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubA]);
        self::assertInstanceOf(Season::class, $season);

        $this->scopeGucToClub($clubA);
        $existing = new Competition;
        $existing->setClubId($clubA);
        $existing->setSeasonId($season->getId());
        $existing->setTeamId($team->getId());
        $existing->setName(FfbbHttpClientStub::CUP_ENGAGEMENT_NAME);
        $existing->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $existing->setExpectedMatchdays(68);
        $this->em->persist($existing);
        $this->em->flush();
        $existingId = $existing->getId();

        $this->confirm($tokenA, FfbbHttpClientStub::COMPETITION_ID_CUP, $team->getId());
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubA);
        $this->em->clear();
        $reloaded = $this->em->getRepository(Competition::class)->find($existingId);
        self::assertInstanceOf(Competition::class, $reloaded);
        self::assertSame(CompetitionType::CUP, $reloaded->getCompetitionType(), 'a name that infers CUP re-poses CUP on reuse');
        self::assertNull($reloaded->getExpectedMatchdays(), 'the 2×(N−1) is cleared to null');
        self::assertSame(FfbbHttpClientStub::COMPETITION_ID_CUP, $reloaded->getFfbbCompetitionId(), 'the SAME row is re-paired, not duplicated');
        self::assertCount(1, $this->em->getRepository(Competition::class)->findBy(['teamId' => $team->getId()]), 'no duplicate competition');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function confirm(string $token, string $ffbbCompetitionId, string $teamId): void
    {
        $this->client->request('POST', '/api/ffbb/engagements/confirm', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['pairings' => [[
            'ffbbCompetitionId' => $ffbbCompetitionId,
            'teamId' => $teamId,
        ]]], \JSON_THROW_ON_ERROR));
    }

    /** Point the club at the stub's FFBB code (the only one it answers). */
    private function useStubClubCode(string $clubId): void
    {
        $club = $this->em->getRepository(Club::class)->find($clubId);
        \assert($club instanceof Club);
        $club->setFfbbClubCode(FfbbHttpClientStub::CLUB_CODE);
        $this->em->flush();
    }

    private function createSeason(string $clubId, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->settleSeasonPlan($season);

        return $season;
    }

    private function createTeam(string $clubId): Team
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId])
            ?? $this->createSeason($clubId, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        if (null === $this->chosenPlanVersion($season)) {
            $this->settleSeasonPlan($season);
        }

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
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('Seniors-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SM-Test');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @return array{0: string, 1: string, 2: string} [token, userId, clubId] */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Pairing', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
