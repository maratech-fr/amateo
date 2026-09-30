<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\SuperAdmin;
use App\Enum\SeasonStatus;
use App\Security\TotpService;
use App\Tests\Double\RecordingDemoResetRunner;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * NR (axes *auth & memberships* + *tenant isolation*) de la console démo (PR B du lot Démos),
 * surface /api/admin/demos — la plus sensible :
 *
 *  - un JWT club ne franchit JAMAIS le firewall admin (401), toute écriture exige le CSRF (403) ;
 *  - `activate` ouvre une fenêtre de 4 h à l'horloge RÉELLE et un re-clic la REDÉMARRE (jamais
 *    une addition), `deactivate` la ferme ;
 *  - l'horloge simulée (`club.demo_today`) ne se pose que sur le club démo BCCL (is_demo), résolu
 *    SERVEUR depuis le compte `demo-bccl@` — jamais depuis la requête ;
 *  - le reset remet demo_today à null SANS toucher la fenêtre du compte NI un autre club démo ;
 *  - le re-seed du reset REFUSE de purger un club NON démo tenant ARA9999999 : un vrai club n'est
 *    jamais détruit (garde de `app:demo:seed`, sur laquelle le reset s'appuie).
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminDemoResetTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use TenantGucTrait;
    use VerifiesRegistration;

    private const string BCCL_EMAIL = 'demo-bccl@amateo.fr';

    private const string PROSPECT_EMAIL = 'demo@amateo.fr';

    private KernelBrowser $client;

    private string $requestIp;

    private ?string $adminId = null;

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $clubIds = [];

    public function testTheDemosSurfaceRejectsAClubJwtAndAnonymousCallers(): void
    {
        // Anonyme : lecture ET écriture refusées par le firewall admin.
        $this->client->request('GET', '/api/admin/demos');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/admin/demos/bccl/activate');
        self::assertResponseStatusCodeSame(401);

        // Un JWT club — identité VALIDE côté app — ne franchit jamais /api/admin/demos.
        $token = $this->registerVerified('DEMO1');
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/admin/demos', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/admin/demos/prospect/activate', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testEveryWriteRequiresACsrfToken(): void
    {
        [$secret] = $this->createSuperAdmin('demoreset@example.test', 'VeryStrongPassword!');
        $this->authenticate('demoreset@example.test', 'VeryStrongPassword!', $secret);

        // Authentifié (session dans le client), mais AUCUN X-CSRF-Token → 403 sur chaque écriture.
        foreach ([
            ['POST', '/api/admin/demos/bccl/activate'],
            ['POST', '/api/admin/demos/prospect/deactivate'],
            ['POST', '/api/admin/demos/bccl/reset'],
            ['POST', '/api/admin/demos/bccl/clock'],
        ] as [$method, $path]) {
            $this->client->request($method, $path, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->requestIp], '{}');
            self::assertResponseStatusCodeSame(403, \sprintf('%s %s doit refuser sans CSRF', $method, $path));
        }
    }

    public function testActivateOpensAndReactivateRestartsAFourHourWindow(): void
    {
        $this->seedDemoUser(self::BCCL_EMAIL, null);
        [$secret] = $this->createSuperAdmin('act@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('act@example.test', 'VeryStrongPassword!', $secret);

        $this->json('POST', '/api/admin/demos/bccl/activate', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $firstUntil = new DateTimeImmutable($this->responseBody()['activeUntil']);
        // ~ now + 4 h (horloge réelle), à quelques secondes près.
        self::assertEqualsWithDelta(time() + 4 * 3600, $firstUntil->getTimestamp(), 120);
        $stored = $this->admin()->fetchOne('SELECT demo_active_until FROM app_user WHERE email = :e', ['e' => self::BCCL_EMAIL]);
        self::assertIsString($stored);
        self::assertEqualsWithDelta(time() + 4 * 3600, new DateTimeImmutable($stored)->getTimestamp(), 120);

        // Une fenêtre DÉJÀ ouverte très loin, puis re-clic : la borne REDÉMARRE à now+4h,
        // elle ne s'ajoute pas (sinon elle serait à now+100h + 4h).
        $this->admin()->executeStatement(
            'UPDATE app_user SET demo_active_until = :far WHERE email = :e',
            ['far' => new DateTimeImmutable('now')->modify('+100 hours')->format(\DATE_ATOM), 'e' => self::BCCL_EMAIL],
        );
        $this->json('POST', '/api/admin/demos/bccl/activate', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $restarted = new DateTimeImmutable($this->responseBody()['activeUntil']);
        self::assertEqualsWithDelta(time() + 4 * 3600, $restarted->getTimestamp(), 120);
        self::assertLessThan(time() + 5 * 3600, $restarted->getTimestamp(), 'un re-clic REDÉMARRE la fenêtre, il ne l\'étend pas');
    }

    public function testDeactivateClosesTheWindow(): void
    {
        $this->seedDemoUser(self::PROSPECT_EMAIL, new DateTimeImmutable('now')->modify('+2 hours'));
        [$secret] = $this->createSuperAdmin('deact@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('deact@example.test', 'VeryStrongPassword!', $secret);

        $this->json('POST', '/api/admin/demos/prospect/deactivate', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->responseBody()['activeUntil']);
        self::assertFalse($this->admin()->fetchOne('SELECT demo_active_until FROM app_user WHERE email = :e', ['e' => self::PROSPECT_EMAIL]) ?: false, 'demo_active_until est remis à NULL');
    }

    public function testUnknownTargetOrMissingAccountReturns404(): void
    {
        [$secret] = $this->createSuperAdmin('nf@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('nf@example.test', 'VeryStrongPassword!', $secret);

        // Cible inconnue → 404 (validée EN controller, après CSRF+session — jamais un requirement
        // de route qui 404-erait au routeur avant le firewall).
        $this->json('POST', '/api/admin/demos/marketing/activate', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);

        // Cible valide mais compte absent → 404, rien posé.
        $this->json('POST', '/api/admin/demos/prospect/activate', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testClockSetsAndClearsTheBcclSimulatedClock(): void
    {
        $userId = $this->seedDemoUser(self::BCCL_EMAIL, null);
        $clubId = $this->seedDemoClub($userId, isDemo: true, demoToday: null);
        [$secret] = $this->createSuperAdmin('clk@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('clk@example.test', 'VeryStrongPassword!', $secret);

        $this->json('POST', '/api/admin/demos/bccl/clock', ['date' => '2026-01-15'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame('2026-01-15', $this->responseBody()['demoToday']);
        self::assertSame('2026-01-15', $this->admin()->fetchOne('SELECT demo_today FROM club WHERE id = :id', ['id' => $clubId]));

        $this->json('POST', '/api/admin/demos/bccl/clock', ['clear' => true], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->responseBody()['demoToday']);
        self::assertNull($this->admin()->fetchOne('SELECT demo_today FROM club WHERE id = :id', ['id' => $clubId]));
    }

    public function testClockRejectsMalformedOrAmbiguousInput(): void
    {
        $userId = $this->seedDemoUser(self::BCCL_EMAIL, null);
        $this->seedDemoClub($userId, isDemo: true, demoToday: null);
        [$secret] = $this->createSuperAdmin('clk2@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('clk2@example.test', 'VeryStrongPassword!', $secret);

        foreach ([
            ['date' => '2026-02-31'],           // parse en 3 mars → refusée
            ['date' => '15/01/2026'],           // mauvais format
            ['date' => '2026-01-15', 'clear' => true], // les deux
            [],                                 // aucun
        ] as $body) {
            $this->json('POST', '/api/admin/demos/bccl/clock', $body, ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(400, 'entrée horloge invalide : ' . json_encode($body));
        }
    }

    public function testResetClearsTheClockKeepsTheWindowAndSparesOtherDemoClubs(): void
    {
        // Compte BCCL avec sa fenêtre ouverte + club démo BCCL avec une horloge simulée.
        $windowUntil = new DateTimeImmutable('now')->modify('+3 hours');
        $bcclUser = $this->seedDemoUser(self::BCCL_EMAIL, $windowUntil);
        $bcclClub = $this->seedDemoClub($bcclUser, isDemo: true, demoToday: '2026-03-01');
        // Un AUTRE club démo (prospect) avec sa propre horloge : le reset ne doit pas y toucher.
        $prospectUser = $this->seedDemoUser(self::PROSPECT_EMAIL, null);
        $prospectClub = $this->seedDemoClub($prospectUser, isDemo: true, demoToday: '2026-05-05');

        [$secret] = $this->createSuperAdmin('rst@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('rst@example.test', 'VeryStrongPassword!', $secret);
        // disableReboot : le contrôleur et le test partagent alors la MÊME instance du double.
        $this->client->disableReboot();
        $runner = $this->resetRunner();
        $runner->reset();

        $this->json('POST', '/api/admin/demos/bccl/reset', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $runner->calls, 'le reset a bien déclenché le re-seed (sous-processus, ici doublé)');

        self::assertNull($this->admin()->fetchOne('SELECT demo_today FROM club WHERE id = :id', ['id' => $bcclClub]), 'la date simulée BCCL revient à aujourd\'hui (NULL)');
        // La fenêtre du compte BCCL n'est PAS touchée par le reset.
        $stored = $this->admin()->fetchOne('SELECT demo_active_until FROM app_user WHERE email = :e', ['e' => self::BCCL_EMAIL]);
        self::assertIsString($stored);
        self::assertEqualsWithDelta($windowUntil->getTimestamp(), new DateTimeImmutable($stored)->getTimestamp(), 5, 'la fenêtre du compte BCCL survit au reset');
        // L'horloge d'un AUTRE club démo est intacte : le reset scope au club BCCL seul.
        self::assertSame('2026-05-05', $this->admin()->fetchOne('SELECT demo_today FROM club WHERE id = :id', ['id' => $prospectClub]));
    }

    public function testResetFailureReturns502AndLeavesTheSimulatedClockUntouched(): void
    {
        $userId = $this->seedDemoUser(self::BCCL_EMAIL, null);
        $clubId = $this->seedDemoClub($userId, isDemo: true, demoToday: '2026-03-01');
        [$secret] = $this->createSuperAdmin('rstf@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('rstf@example.test', 'VeryStrongPassword!', $secret);
        $this->client->disableReboot();
        $runner = $this->resetRunner();
        $runner->reset();
        $runner->shouldFail = true;

        $this->json('POST', '/api/admin/demos/bccl/reset', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(502);
        // Le re-seed a échoué → on ne touche pas l'horloge (le clear vient APRÈS le succès).
        self::assertSame('2026-03-01', $this->admin()->fetchOne('SELECT demo_today FROM club WHERE id = :id', ['id' => $clubId]));
    }

    public function testSeedRefusesToPurgeANonDemoClubHoldingAra9999999(): void
    {
        // Le reset s'appuie sur la garde de `app:demo:seed` : ARA9999999 tenu par un club NON
        // démo ⇒ refus FRANC, rien purgé (le vrai club et sa saison survivent). Testé au niveau
        // commande (le reset lance ce même binaire en sous-processus).
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(4));
        $real = new Club()->setName('Vrai Club ' . $suffix)->setSlug('vrai-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $real->setFfbbClubCode('ARA9999999');
        $real->setIsDemo(false);
        $em->persist($real);
        $em->flush();
        $realId = $real->getId();
        $this->scopeGucToClub($realId);
        $season = new Season()->setClubId($realId)->setName('Saison réelle')
            ->setStartDate(new DateTimeImmutable(date('Y') . '-07-16'))
            ->setEndDate(new DateTimeImmutable((date('Y') + 1) . '-07-14'))
            ->setStatus(SeasonStatus::ACTIVE);
        $em->persist($season);
        $em->flush();
        $seasonId = $season->getId();
        $this->clearGuc();

        $tester = new CommandTester(new Application(self::$kernel)->find('app:demo:seed'));
        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]), $tester->getDisplay());
        self::assertStringContainsString('NOT a demo club', $tester->getDisplay());

        // Rien purgé : le club est INTACT (toujours non-démo) et sa saison survit.
        $em->clear();
        $freshClub = $em->getRepository(Club::class)->find($realId);
        self::assertInstanceOf(Club::class, $freshClub);
        self::assertFalse($freshClub->isDemo(), 'le club reste un vrai club — jamais transformé en démo');
        $this->scopeGucToClub($realId);
        self::assertNotNull($em->getRepository(Season::class)->find($seasonId), 'la saison du vrai club survit — rien purgé');
    }

    public function testStateReportsBothAccounts(): void
    {
        $bcclUser = $this->seedDemoUser(self::BCCL_EMAIL, new DateTimeImmutable('now')->modify('+1 hour'));
        $this->seedDemoClub($bcclUser, isDemo: true, demoToday: '2026-02-02');
        [$secret] = $this->createSuperAdmin('st@example.test', 'VeryStrongPassword!');
        $this->authenticate('st@example.test', 'VeryStrongPassword!', $secret);

        $this->client->request('GET', '/api/admin/demos');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame(self::BCCL_EMAIL, $body['bccl']['email']);
        self::assertNotNull($body['bccl']['activeUntil']);
        self::assertSame('2026-02-02', $body['bccl']['demoToday']);
        self::assertSame(self::PROSPECT_EMAIL, $body['prospect']['email']);
        self::assertNull($body['prospect']['activeUntil']);
        self::assertArrayNotHasKey('demoToday', $body['prospect'], 'l\'horloge simulée n\'existe que pour la démo BCCL');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        // Aucune ligne démo committée résiduelle (les seeds admin ne passent pas par DAMA).
        $this->admin()->executeStatement('DELETE FROM club_user WHERE user_id IN (SELECT id FROM app_user WHERE email IN (:e))', ['e' => [self::BCCL_EMAIL, self::PROSPECT_EMAIL]], ['e' => ArrayParameterType::STRING]);
        $this->admin()->executeStatement('DELETE FROM app_user WHERE email IN (:e)', ['e' => [self::BCCL_EMAIL, self::PROSPECT_EMAIL]], ['e' => ArrayParameterType::STRING]);
    }

    protected function tearDown(): void
    {
        if ([] !== $this->clubIds) {
            $this->admin()->executeStatement('DELETE FROM club_user WHERE club_id IN (:ids)', ['ids' => $this->clubIds], ['ids' => ArrayParameterType::STRING]);
            $this->admin()->executeStatement('DELETE FROM club WHERE id IN (:ids)', ['ids' => $this->clubIds], ['ids' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->userIds) {
            $this->admin()->executeStatement('DELETE FROM app_user WHERE id IN (:ids)', ['ids' => $this->userIds], ['ids' => ArrayParameterType::STRING]);
        }
        if (null !== $this->adminId) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    private function registerVerified(string $ara): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email,
            'password' => 'Password123!',
            'firstName' => 'Club',
            'lastName' => 'Member',
            'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $ara,
            'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        return $token;
    }

    private function seedDemoUser(string $email, ?DateTimeImmutable $activeUntil): string
    {
        $userId = Uuid::v4()->toRfc4122();
        $this->userIds[] = $userId;
        $this->admin()->executeStatement(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name, demo_active_until) VALUES (:id, 1, NOW(), NOW(), :email, :hash, :fn, :ln, :until)',
            ['id' => $userId, 'email' => $email, 'hash' => 'x', 'fn' => 'Démo', 'ln' => 'Amateo', 'until' => $activeUntil?->format(\DATE_ATOM)],
        );

        return $userId;
    }

    private function seedDemoClub(string $ownerUserId, bool $isDemo, ?string $demoToday): string
    {
        $clubId = Uuid::v4()->toRfc4122();
        $this->clubIds[] = $clubId;
        $this->admin()->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed, is_demo, demo_today)'
            . ' VALUES (:id, 1, NOW(), NOW(), :name, :slug, 0, :tz, :locale, FALSE, :demo, :today)',
            ['id' => $clubId, 'name' => 'Démo ' . substr($clubId, 0, 8), 'slug' => 'demo-' . substr($clubId, 0, 8), 'tz' => 'Europe/Paris', 'locale' => 'fr', 'demo' => $isDemo, 'today' => $demoToday],
            ['demo' => ParameterType::BOOLEAN],
        );
        $this->admin()->executeStatement(
            'INSERT INTO club_user (id, version, created_at, updated_at, joined_at, club_id, user_id, role, is_active) VALUES (:id, 1, NOW(), NOW(), NOW(), :club, :user, :role, TRUE)',
            ['id' => Uuid::v4()->toRfc4122(), 'club' => $clubId, 'user' => $ownerUserId, 'role' => 'manager'],
        );

        return $clubId;
    }

    /** @return array{0: string} */
    private function createSuperAdmin(string $email, string $password): array
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, $password));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, :enabled, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret(), 'enabled' => true],
            ['enabled' => ParameterType::BOOLEAN],
        );

        return [$secret];
    }

    private function authenticate(string $email, string $password, string $secret): string
    {
        $this->json('POST', '/api/admin/auth/password', ['email' => $email, 'password' => $password]);
        self::assertResponseIsSuccessful();
        $totp = self::getContainer()->get(TotpService::class);
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();
        $csrfToken = $this->responseBody()['csrfToken'] ?? null;
        self::assertIsString($csrfToken);

        return $csrfToken;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     */
    private function json(string $method, string $uri, array $body, array $server = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
            ...$server,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function resetRunner(): RecordingDemoResetRunner
    {
        $runner = self::getContainer()->get(RecordingDemoResetRunner::class);
        \assert($runner instanceof RecordingDemoResetRunner);

        return $runner;
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
