<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use App\Tests\Double\GitHubDeployHttpClientStub;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * NR (axe *auth & memberships*, §7.1) de la « mise en prod programmée » (PR b), surface
 * admin /api/admin/deployments — un geste cross-tenant de PROD, donc la plus sensible :
 *
 *  - PROGRAMMER (POST) exige une version = tag `^v` RÉEL ET une CI verte ET une heure H ≥
 *    maintenant +10 min : toute version forgée / CI rouge / H hors bornes → 422, et JAMAIS
 *    de dispatch (programmer n'est pas déployer) ;
 *  - un seul programmé/dispatché à la fois (index unique partiel) → 2ᵉ programmation = 409 ;
 *  - ANNULER (DELETE) efface un `scheduled`, mais REFUSE un `dispatched` (409 : le train est parti) ;
 *  - un JWT club et un appel anonyme ne franchissent jamais le firewall admin (401) ;
 *  - aucune réponse ne recopie le jeton GitHub ; jeton absent → fonctionnalité inerte (422).
 *
 * Le client GitHub est DOUBLÉ par son HTTP ({@see GitHubDeployHttpClientStub}, câblé par NEO
 * dans services_test.yaml comme `$httpClient` de `App\Service\GitHubDeployClient`) : aucune
 * requête ne part vers api.github.com. Les env `GITHUB_DEPLOY_TOKEN`/`GITHUB_DEPLOY_REPOSITORY`
 * sont posés AVANT chaque boot (patron RegisterTurnstileTest).
 *
 * Contrat FIXÉ pour NEO : routes `GET /api/admin/deployments`, `GET /api/admin/deployments/tags`,
 * `POST /api/admin/deployments` (corps `{version, scheduledAt, windowMinutes}`),
 * `DELETE /api/admin/deployments/{id}` ; entité `App\Entity\ScheduledDeployment`
 * (table `scheduled_deployment`, colonnes `version, scheduled_at, expected_return_at, status,
 * created_by, dispatched_at, github_run_id, failure_reason`), statuts
 * `scheduled|dispatched|succeeded|failed|cancelled`.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduledDeploymentTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private const string TOKEN = 'github_pat_11DEPLOYSECRET0000';

    private const string REPOSITORY = 'maratech-fr/amateo';

    private KernelBrowser $client;

    private string $requestIp;

    private ?string $adminId = null;

    public function testAValidProgrammingPersistsAScheduledDeploymentWithoutDispatching(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        $csrf = $this->boot();

        $scheduledAt = new DateTimeImmutable('now')->modify('+30 minutes');
        $this->json('POST', '/api/admin/deployments', [
            'version' => 'v1.0.0',
            'scheduledAt' => $scheduledAt->format(DateTimeInterface::ATOM),
            'windowMinutes' => 60,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->responseBody();
        self::assertSame('v1.0.0', $body['version']);
        self::assertSame('scheduled', $body['status']);
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'programmer n\'est pas déployer : aucun dispatch');

        self::assertSame('scheduled', $this->admin()->fetchOne('SELECT status FROM scheduled_deployment WHERE version = :v', ['v' => 'v1.0.0']));
    }

    public function testAForgedVersionIsRejectedWith422AndNeverDispatches(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        $csrf = $this->boot();
        $scheduledAt = new DateTimeImmutable('now')->modify('+30 minutes')->format(DateTimeInterface::ATOM);

        // `main`/`sha-abc` ne sont pas des `^v` ; `v9.9.9` est bien formé mais N'EXISTE PAS en
        // tant que tag ; `v1.0.0;rm -rf` est une injection — toutes refusées, zéro dispatch.
        foreach (['main', 'sha-abc', 'v9.9.9', 'v1.0.0;rm -rf /'] as $version) {
            $this->json('POST', '/api/admin/deployments', [
                'version' => $version,
                'scheduledAt' => $scheduledAt,
                'windowMinutes' => 60,
            ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(422, \sprintf('version forgée « %s » doit être refusée', $version));
        }

        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'aucune version forgée ne déclenche de dispatch');
        self::assertSame(0, (int) $this->admin()->fetchOne('SELECT COUNT(*) FROM scheduled_deployment'), 'rien persisté');
    }

    public function testARedCiIsRejectedWith422(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'failure';
        $csrf = $this->boot();

        $this->json('POST', '/api/admin/deployments', [
            'version' => 'v1.0.0',
            'scheduledAt' => new DateTimeImmutable('now')->modify('+30 minutes')->format(DateTimeInterface::ATOM),
            'windowMinutes' => 60,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);

        self::assertResponseStatusCodeSame(422, 'une CI rouge interdit la programmation');
        self::assertSame(0, (int) $this->admin()->fetchOne('SELECT COUNT(*) FROM scheduled_deployment'));
    }

    public function testAnHourInThePastOrTooCloseIsRejectedWith422(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        $csrf = $this->boot();

        foreach (['-5 minutes', '+5 minutes'] as $offset) {
            $this->json('POST', '/api/admin/deployments', [
                'version' => 'v1.0.0',
                'scheduledAt' => new DateTimeImmutable('now')->modify($offset)->format(DateTimeInterface::ATOM),
                'windowMinutes' => 60,
            ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(422, \sprintf('H à « %s » viole H ≥ maintenant +10 min', $offset));
        }
        self::assertSame(0, (int) $this->admin()->fetchOne('SELECT COUNT(*) FROM scheduled_deployment'));
    }

    public function testASecondProgrammingIsRejectedWith409(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000'], ['name' => 'v2.0.0', 'sha' => 'head2222']];
        $csrf = $this->boot();
        $scheduledAt = new DateTimeImmutable('now')->modify('+30 minutes')->format(DateTimeInterface::ATOM);

        $this->json('POST', '/api/admin/deployments', ['version' => 'v1.0.0', 'scheduledAt' => $scheduledAt, 'windowMinutes' => 60], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);

        // Un déploiement est DÉJÀ programmé → un second (même ou autre version) = 409 net.
        $this->json('POST', '/api/admin/deployments', ['version' => 'v2.0.0', 'scheduledAt' => $scheduledAt, 'windowMinutes' => 60], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(409, 'un seul programmé/dispatché à la fois');
        self::assertSame(1, (int) $this->admin()->fetchOne('SELECT COUNT(*) FROM scheduled_deployment'));
    }

    public function testDeleteCancelsAScheduledDeploymentButRefusesADispatchedOne(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        $csrf = $this->boot();

        $this->json('POST', '/api/admin/deployments', [
            'version' => 'v1.0.0',
            'scheduledAt' => new DateTimeImmutable('now')->modify('+30 minutes')->format(DateTimeInterface::ATOM),
            'windowMinutes' => 60,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->responseBody()['id'];
        self::assertIsString($id);

        $this->client->request('DELETE', '/api/admin/deployments/' . $id, [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('cancelled', $this->admin()->fetchOne('SELECT status FROM scheduled_deployment WHERE id = :id', ['id' => $id]), 'annuler passe le programmé à cancelled');

        // Un déploiement DÉJÀ dispatché (le train est parti) ne s'annule plus : 409.
        $dispatchedId = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO scheduled_deployment (id, version, scheduled_at, expected_return_at, status, created_by, dispatched_at, created_at, updated_at)'
            . ' VALUES (:id, \'v1.0.0\', NOW() - INTERVAL \'2 minutes\', NOW() + INTERVAL \'1 hour\', \'dispatched\', :by, NOW(), NOW(), NOW())',
            ['id' => $dispatchedId, 'by' => $this->adminId],
        );
        $this->client->request('DELETE', '/api/admin/deployments/' . $dispatchedId, [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseStatusCodeSame(409, 'un dispatché ne s\'annule plus');
        self::assertSame('dispatched', $this->admin()->fetchOne('SELECT status FROM scheduled_deployment WHERE id = :id', ['id' => $dispatchedId]));
    }

    public function testAClubJwtAndAnonymousCallersNeverCrossTheAdminFirewall(): void
    {
        $this->client = self::createClient();
        $token = $this->registerVerified('DEPL1');

        foreach ([
            ['GET', '/api/admin/deployments'],
            ['GET', '/api/admin/deployments/tags'],
            ['POST', '/api/admin/deployments'],
            ['DELETE', '/api/admin/deployments/' . Uuid::v4()->toRfc4122()],
        ] as [$method, $path]) {
            $this->startFreshBrowserSession($this->client);
            $this->client->request($method, $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertResponseStatusCodeSame(401, \sprintf('%s %s avec un JWT club doit être rejeté', $method, $path));

            $this->startFreshBrowserSession($this->client);
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(401, \sprintf('%s %s anonyme doit être rejeté', $method, $path));
        }
    }

    public function testNoResponseEverEchoesTheGitHubToken(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        $csrf = $this->boot();

        $this->client->request('GET', '/api/admin/deployments', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::TOKEN, (string) $this->client->getResponse()->getContent(), 'la liste ne recopie jamais le jeton');

        $this->client->request('GET', '/api/admin/deployments/tags', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::TOKEN, (string) $this->client->getResponse()->getContent(), 'la liste des tags ne recopie jamais le jeton');
    }

    public function testTagsAreServedToAnAuthenticatedAdmin(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v2.0.0', 'sha' => 'head2222'], ['name' => 'main', 'sha' => 'headmain']];
        $this->boot();

        $this->client->request('GET', '/api/admin/deployments/tags', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        $tags = $this->responseBody()['items'] ?? $this->responseBody();
        self::assertContains('v2.0.0', $tags, 'les tags déployables ^v sont servis');
        self::assertNotContains('main', $tags, 'le bruit non-^v est écarté');
    }

    public function testAnAbsentTokenMakesTheFeatureInertWith422(): void
    {
        $this->setDeployEnv('', self::REPOSITORY);
        $csrf = $this->boot();

        $this->json('POST', '/api/admin/deployments', [
            'version' => 'v1.0.0',
            'scheduledAt' => new DateTimeImmutable('now')->modify('+30 minutes')->format(DateTimeInterface::ATOM),
            'windowMinutes' => 60,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);

        self::assertResponseStatusCodeSame(422, 'jeton absent → fonctionnalité inerte, 422 explicite');
    }

    protected function setUp(): void
    {
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        GitHubDeployHttpClientStub::reset();
        $this->setDeployEnv(self::TOKEN, self::REPOSITORY);
        // Table GLOBALE (aucun tenant) : on part d'une base propre à chaque test, l'index
        // unique partiel est partagé entre méthodes. Via la connexion admin (hors DAMA).
        $this->admin()->executeStatement('DELETE FROM scheduled_deployment');
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->admin()->executeStatement('DELETE FROM scheduled_deployment');
            if (null !== $this->adminId) {
                $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
                $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
            }
        }
        $this->clearDeployEnv();
        parent::tearDown();
    }

    /** Boot client + superadmin authentifié, renvoie le jeton CSRF. */
    private function boot(): string
    {
        $this->client = self::createClient();
        [$secret] = $this->createSuperAdmin('deploy@example.test', 'VeryStrongPassword!');

        return $this->authenticate('deploy@example.test', 'VeryStrongPassword!', $secret);
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

    private function setDeployEnv(string $token, string $repository): void
    {
        foreach (['GITHUB_DEPLOY_TOKEN' => $token, 'GITHUB_DEPLOY_REPOSITORY' => $repository] as $key => $value) {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    private function clearDeployEnv(): void
    {
        foreach (['GITHUB_DEPLOY_TOKEN', 'GITHUB_DEPLOY_REPOSITORY'] as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        }
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
