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

    public function testEngagementListSuggestsTheFbiMappedTeamBySignature(): void
    {
        // P4-200 (C1) — the xlsx import stored a Competition « DM2 »; the FBI
        // bridge must SUGGEST its team on the matching FFBB engagement.
        [$tokenA, , $clubA] = $this->register('FFPJ');
        $this->useStubClubCode($clubA);
        $team = $this->createTeam($clubA);
        $competition = $this->createCompetition($clubA, $this->seasonOf($clubA)->getId(), $team->getId(), 'DM2');

        $row = $this->engagementRow($tokenA, FfbbHttpClientStub::COMPETITION_ID);
        self::assertSame('fbi', $row['suggestionSource'], 'the signature bridge names its source');
        self::assertSame($team->getId(), $row['suggestedTeamId']);
        self::assertSame($competition->getId(), $row['suggestedCompetitionId'], 'the xlsx competition to write on');
    }

    public function testEngagementListSuggestsNothingWhenTwoTeamsMatchTheSameSignature(): void
    {
        [$tokenA, , $clubA] = $this->register('FFPK');
        $this->useStubClubCode($clubA);
        $seasonId = $this->seasonOf($clubA)->getId();
        $teamA = $this->createTeam($clubA);
        $this->createCompetition($clubA, $seasonId, $teamA->getId(), 'DM2');
        $teamB = $this->createTeam($clubA);
        $this->createCompetition($clubA, $seasonId, $teamB->getId(), 'DM3');

        $row = $this->engagementRow($tokenA, FfbbHttpClientStub::COMPETITION_ID);
        self::assertNull($row['suggestionSource'], 'two distinct teams → ambiguous → no suggestion');
        self::assertNull($row['suggestedTeamId']);
    }

    public function testEngagementListBridgesACupToACupCompetition(): void
    {
        [$tokenA, , $clubA] = $this->register('FFPL');
        $this->useStubClubCode($clubA);
        $team = $this->createTeam($clubA);
        $this->createCompetition($clubA, $this->seasonOf($clubA)->getId(), $team->getId(), 'CRMLU18M');

        $row = $this->engagementRow($tokenA, FfbbHttpClientStub::COMPETITION_ID_CUP);
        self::assertSame('fbi', $row['suggestionSource'], 'a cup bridges a cup');
        self::assertSame($team->getId(), $row['suggestedTeamId']);
    }

    public function testAnAmicalCompetitionIsNeverBridged(): void
    {
        // « Amical DM2 » would match the championship signature if it were not an
        // amical — the FRIENDLY type must exclude it from the bridge.
        [$tokenA, , $clubA] = $this->register('FFPM');
        $this->useStubClubCode($clubA);
        $team = $this->createTeam($clubA);
        $this->createCompetition($clubA, $this->seasonOf($clubA)->getId(), $team->getId(), 'Amical DM2');

        $row = $this->engagementRow($tokenA, FfbbHttpClientStub::COMPETITION_ID);
        self::assertNull($row['suggestionSource'], 'an amical is never a bridge candidate');
    }

    public function testAConfirmedPairingWinsOverTheFbiBridge(): void
    {
        [$tokenA, , $clubA] = $this->register('FFPN');
        $this->useStubClubCode($clubA);
        $seasonId = $this->seasonOf($clubA)->getId();
        $teamPaired = $this->createTeam($clubA);
        $this->createCompetition($clubA, $seasonId, $teamPaired->getId(), 'PNM', FfbbHttpClientStub::COMPETITION_ID);
        $teamBridged = $this->createTeam($clubA);
        $this->createCompetition($clubA, $seasonId, $teamBridged->getId(), 'DM2');

        $row = $this->engagementRow($tokenA, FfbbHttpClientStub::COMPETITION_ID);
        self::assertSame('pairing', $row['suggestionSource'], 'an existing pairing wins over the bridge');
        self::assertSame($teamPaired->getId(), $row['suggestedTeamId']);
    }

    public function testAForeignClubsCompetitionNeverFeedsTheSuggestion(): void
    {
        // §7.1 tenant — a matching xlsx competition owned by ANOTHER club must
        // stay invisible to the bridge.
        [$tokenA, , $clubA] = $this->register('FFPO');
        $this->useStubClubCode($clubA);
        $this->createTeam($clubA);
        [, , $clubB] = $this->register('FFPP');
        $teamB = $this->createTeam($clubB);
        $this->createCompetition($clubB, $this->seasonOf($clubB)->getId(), $teamB->getId(), 'DM2');

        $row = $this->engagementRow($tokenA, FfbbHttpClientStub::COMPETITION_ID);
        self::assertNull($row['suggestionSource'], 'another club\'s competition never feeds the suggestion');
        self::assertNull($row['suggestedTeamId']);
    }

    public function testConfirmWithCompetitionIdWritesTheRefsOnTheXlsxCompetition(): void
    {
        [$tokenA, , $clubA] = $this->register('FFPQ');
        $this->useStubClubCode($clubA);
        $team = $this->createTeam($clubA);
        $xlsx = $this->createCompetition($clubA, $this->seasonOf($clubA)->getId(), $team->getId(), 'PNM');
        $xlsxId = $xlsx->getId();

        $this->client->request('POST', '/api/ffbb/engagements/confirm', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA, 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['pairings' => [[
            'ffbbCompetitionId' => FfbbHttpClientStub::COMPETITION_ID,
            'teamId' => $team->getId(),
            'competitionId' => $xlsxId,
        ]]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubA);
        $this->em->clear();
        $reloaded = $this->em->getRepository(Competition::class)->find($xlsxId);
        self::assertInstanceOf(Competition::class, $reloaded);
        self::assertSame(FfbbHttpClientStub::COMPETITION_ID, $reloaded->getFfbbCompetitionId(), 'the refs land on the xlsx competition');
        self::assertSame('Pré test masculine', $reloaded->getFfbbCompetitionName(), 'canonical FFBB name written');
        self::assertSame('PNM', $reloaded->getName(), 'the FBI code (the resolver key) is kept');
        self::assertCount(1, $this->em->getRepository(Competition::class)->findBy(['teamId' => $team->getId()]), 'no twin competition created');

        // Périmètre engagé (§7.1): still no fixture → the team stays deletable.
        $this->client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);
        self::assertResponseStatusCodeSame(204, 'writing refs on an xlsx competition never engages the team');
    }

    public function testConfirmIgnoresACompetitionIdOfAnotherTeam(): void
    {
        [$tokenA, , $clubA] = $this->register('FFPR');
        $this->useStubClubCode($clubA);
        $seasonId = $this->seasonOf($clubA)->getId();
        $teamA = $this->createTeam($clubA);
        $teamB = $this->createTeam($clubA);
        $foreign = $this->createCompetition($clubA, $seasonId, $teamB->getId(), 'PNM');
        $foreignId = $foreign->getId();

        $this->client->request('POST', '/api/ffbb/engagements/confirm', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA, 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['pairings' => [[
            'ffbbCompetitionId' => FfbbHttpClientStub::COMPETITION_ID,
            'teamId' => $teamA->getId(),
            'competitionId' => $foreignId,
        ]]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubA);
        $this->em->clear();
        // Team A got a fresh competition (the id was ignored — wrong team).
        $created = $this->em->getRepository(Competition::class)->findOneBy(['teamId' => $teamA->getId()]);
        self::assertInstanceOf(Competition::class, $created);
        self::assertSame(FfbbHttpClientStub::COMPETITION_ID, $created->getFfbbCompetitionId());
        self::assertSame('Pré test masculine', $created->getName(), 'a new competition is named by the canonical name');
        // Team B's competition is untouched.
        $reloadedForeign = $this->em->getRepository(Competition::class)->find($foreignId);
        self::assertInstanceOf(Competition::class, $reloadedForeign);
        self::assertNull($reloadedForeign->getFfbbCompetitionId(), 'the other team\'s competition never received the refs');
        self::assertSame('PNM', $reloadedForeign->getName());
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

    /**
     * The engagement row of the given ffbb competition id, from GET /api/ffbb/engagements.
     *
     * @return array<string, mixed>
     */
    private function engagementRow(string $token, string $ffbbCompetitionId): array
    {
        $this->client->request('GET', '/api/ffbb/engagements', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseIsSuccessful();
        /** @var array{engagements: list<array<string, mixed>>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($data['engagements'] as $row) {
            if (($row['ffbbCompetitionId'] ?? null) === $ffbbCompetitionId) {
                return $row;
            }
        }
        self::fail(\sprintf('No engagement row for %s', $ffbbCompetitionId));
    }

    private function seasonOf(string $clubId): Season
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season);

        return $season;
    }

    private function createCompetition(string $clubId, string $seasonId, string $teamId, string $name, ?string $ffbbCompetitionId = null): Competition
    {
        $this->scopeGucToClub($clubId);
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        if (null !== $ffbbCompetitionId) {
            $competition->setFfbbCompetitionId($ffbbCompetitionId);
        }
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
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
