<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Matches\CompetitionRegistry;
use App\Plans\PlanComposition;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-04 non-regression for the club-wide FBI import endpoints (cadrage P1-4,
 * §7.1 tenant axis): POST /api/fixtures/import[/analyze] write into the
 * CALLER's club only — management role required, archived seasons refuse
 * writes (409), and a mapping can never target another club's team (the
 * tenant filters make it invisible → clean 400, no cross-tenant write).
 */
#[Group('phase1')]
#[Group('integration')]
final class ImportFixturesAuthorizationTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    public function testImportAsActiveAdminReaches400WithoutFile(): void
    {
        [$tokenA, , $clubA] = $this->register('FIXC');
        $this->createTeam($clubA);

        // Guard passed → falls through to "No file uploaded" (400).
        $this->client->request('POST', '/api/fixtures/import', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testImportAsNonAdminMemberReturns403(): void
    {
        [, , $clubA] = $this->register('FIXD');
        $this->createTeam($clubA);
        $editorToken = $this->addActiveMember($clubA, 'member');

        $this->client->request('POST', '/api/fixtures/import', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
        ]);
        self::assertResponseStatusCodeSame(403, 'a non-management member must not import');
    }

    public function testAnalyzeAsNonAdminMemberReturns403(): void
    {
        // Same gate on the dry-run: the mapping table leaks club data
        // (divisions, teams) — management only, byte-identical refusals.
        [, , $clubA] = $this->register('FIXF');
        $this->createTeam($clubA);
        $editorToken = $this->addActiveMember($clubA, 'member');

        $this->client->request('POST', '/api/fixtures/import/analyze', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testImportOnArchivedSeasonReturns409(): void
    {
        [$tokenA, , $clubA] = $this->register('FIXE');
        // A PAST season → archived (read-only) → refuses the write.
        // Register seeds a civil-year season (possibly a future bin before the
        // July-15 pivot) — anchor the TRUE current season so the past one is
        // actually archived rather than falling back to "latest started".
        $this->scopeGucToClub($clubA);
        $currentYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->createSeason($clubA, $currentYear);
        $past = $this->createSeason($clubA, $currentYear - 1);

        $this->client->request('POST', '/api/fixtures/import', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(409, 'archived-season writes must be refused');
    }

    public function testMappingCannotTargetAForeignTeam(): void
    {
        // The cross-tenant seam of the new flow: club A's manager posts a
        // mapping whose teamId belongs to club B. The tenant+season filters
        // make that team invisible → 400, and NO Competition row lands in
        // either club (no cross-tenant write, no existence oracle beyond
        // « équipe introuvable »).
        [$tokenA, , $clubA] = $this->register('FIXA');
        $this->createTeam($clubA); // settles club A's socle: the gate must pass, the MAPPING must fail
        [, , $clubB] = $this->register('FIXB');
        $teamB = $this->createTeam($clubB);

        $file = $this->xlsx([['D2', 'X1', 'CLUB FIXA - 1', 'AS Voisins', '03/10/2026', '', '']]);
        $this->client->request('POST', '/api/fixtures/import', [
            'mappings' => json_encode([['division' => 'D2', 'teamId' => $teamB->getId()]], \JSON_THROW_ON_ERROR),
        ], [
            'file' => new UploadedFile($file, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA]);
        self::assertResponseStatusCodeSame(400);

        $this->scopeGucToClub($clubB);
        self::assertCount(
            0,
            self::getContainer()->get(CompetitionRegistry::class)->competitionsOf($teamB->getId()),
            'a foreign mapping must never write into the other club',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
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
        // Import needs a settled season plan (cockpit state 3) so the auth tests
        // reach their expected outcome (400/403/404), not the socle guard's 409.
        $this->settleSeasonPlan($season);

        return $season;
    }

    private function createTeam(string $clubId, ?string $seasonId = null): Team
    {
        $this->scopeGucToClub($clubId);
        if (null === $seasonId) {
            $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId])
                ?? $this->createSeason($clubId, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
            // The register endpoint seeds the current season with an empty plan
            // (espace de travail); matches need a settled one (state 3).
            if (null === $this->chosenPlanVersion($season)) {
                $this->settleSeasonPlan($season);
            }
            $seasonId = $season->getId();
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
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('U13-1');
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2]]);
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

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'N° de match ', 'Equipe 1', 'Equipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );
        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function register(string $ara): array
    {
        // High-entropy IP: the register rate-limiter lives in Redis and is NOT
        // rolled back between test runs.
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Import', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
