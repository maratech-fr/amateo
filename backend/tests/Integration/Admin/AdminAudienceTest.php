<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use App\Tests\Double\UmamiHttpClientStub;
use App\Tests\Security\SuperAdminAccessTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'onglet « Statistiques » — mesure d'audience (P4-307), route `GET /api/admin/audience`.
 *
 * La PROMESSE : la route répond TOUJOURS 200, quel que soit l'état d'Umami, et ne laisse jamais
 * la console tomber sur un 5xx à cause d'un tiers lent ou absent :
 *
 *  - clé / identifiant de site non configuré → `{configured:false}` ;
 *  - Umami injoignable ou en erreur → `{configured:true, available:false}` ;
 *  - sinon → `{configured:true, available:true, windows:{d7,d30:{visitors,visits,pageviews,trend}},
 *    topPages:[≤5 {value,count}], topSources:[≤5 {value,count}]}` ;
 *  - un 2ᵉ appel DANS la fenêtre de cache (succès comme échec) ne rappelle PAS Umami ;
 *  - la clé API n'apparaît JAMAIS dans la réponse JSON.
 *
 * Umami est DOUBLÉ par son HTTP ({@see UmamiHttpClientStub}, câblé par NEO dans
 * services_test.yaml comme `$httpClient` de `App\Service\UmamiStatsClient`) : aucune requête ne
 * part vers l'instance réelle. Les env `UMAMI_API_KEY`/`UMAMI_WEBSITE_ID` sont posés AVANT chaque
 * boot (patron {@see ScheduledDeploymentTest}, résolution `%env%` au runtime).
 *
 * ⚠ `cache.app` est un `cache.adapter.array` en test (when@test/cache.yaml) : il est vidé à
 * chaque reboot du kernel. Le test de cache DOIT donc `disableReboot()` pour que les deux appels
 * partagent le MÊME kernel (et donc le même cache en mémoire) — sans quoi le 2ᵉ appel repartirait
 * de zéro et masquerait l'absence de cache côté implémentation.
 *
 * La frontière (JWT club / anonyme = 401) est éprouvée par
 * {@see SuperAdminAccessTest::testClubJwtCanNeverCrossTheAdminFirewall}
 * (enumération bloquante du firewall) : pas de doublon ici.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminAudienceTest extends WebTestCase
{
    private const string API_KEY = 'umami_api_SECRETKEY_integration_0000';

    private const string WEBSITE_ID = '11111111-2222-3333-4444-555555555555';

    private KernelBrowser $client;

    private string $requestIp;

    private ?string $adminId = null;

    public function testNonConfiguredReturns200WithConfiguredFalse(): void
    {
        // Clé absente → le client est inerte ; la route le DIT sans jamais échouer.
        $this->setUmamiEnv('', self::WEBSITE_ID);
        $this->boot();

        $this->client->request('GET', '/api/admin/audience', [], [], ['REMOTE_ADDR' => $this->requestIp]);

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseBody();
        self::assertFalse($body['configured'], 'clé/site non configuré → configured:false');
        self::assertSame(0, UmamiHttpClientStub::$requestCount, 'un client inerte n\'interroge jamais Umami');
    }

    public function testConfiguredAndAvailableReturnsBothWindowsAndCappedTopLists(): void
    {
        $this->setUmamiEnv(self::API_KEY, self::WEBSITE_ID);
        $this->boot();

        $this->client->request('GET', '/api/admin/audience', [], [], ['REMOTE_ADDR' => $this->requestIp]);

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseBody();
        self::assertTrue($body['configured']);
        self::assertTrue($body['available'], 'Umami répond → available:true');

        foreach (['d7', 'd30'] as $window) {
            foreach (['visitors', 'visits', 'pageviews', 'trend'] as $key) {
                self::assertArrayHasKey($key, $body['windows'][$window], \sprintf('windows.%s.%s manque', $window, $key));
            }
            self::assertSame(300, $body['windows'][$window]['visitors']);
            self::assertSame(1200, $body['windows'][$window]['pageviews']);
            // La comparaison stub est strictement inférieure au courant → tendance positive.
            self::assertGreaterThan(0, $body['windows'][$window]['trend'], 'courant > période précédente → tendance > 0');
        }

        // Top pages : 6 servies, la route en garde au plus 5, mappées en {value, count}.
        self::assertLessThanOrEqual(5, \count($body['topPages']));
        self::assertCount(5, $body['topPages']);
        self::assertSame(['value' => '/', 'count' => 500], $body['topPages'][0]);

        // Top sources : 3 servies, mappées pareil.
        self::assertSame(['value' => 'google.com', 'count' => 300], $body['topSources'][0]);
    }

    public function testUmamiDownReturns200WithAvailableFalse(): void
    {
        $this->setUmamiEnv(self::API_KEY, self::WEBSITE_ID);
        UmamiHttpClientStub::$fails = true;
        $this->boot();

        $this->client->request('GET', '/api/admin/audience', [], [], ['REMOTE_ADDR' => $this->requestIp]);

        self::assertResponseStatusCodeSame(200, 'un Umami KO ne doit JAMAIS faire tomber la console');
        $body = $this->responseBody();
        self::assertTrue($body['configured']);
        self::assertFalse($body['available'], 'Umami en erreur → available:false');
    }

    public function testASecondCallWithinTheWindowDoesNotHitUmamiAgain(): void
    {
        $this->setUmamiEnv(self::API_KEY, self::WEBSITE_ID);
        $this->boot();
        // Un seul kernel pour les deux appels (cache array vivant entre eux).
        $this->client->disableReboot();
        UmamiHttpClientStub::$requestCount = 0;

        $this->client->request('GET', '/api/admin/audience', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        $afterFirst = UmamiHttpClientStub::$requestCount;
        self::assertGreaterThan(0, $afterFirst, 'le 1er appel interroge bien Umami (cache froid)');

        $this->client->request('GET', '/api/admin/audience', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        self::assertSame($afterFirst, UmamiHttpClientStub::$requestCount, 'le 2ᵉ appel dans la fenêtre lit le cache, pas Umami');
    }

    public function testAFailedLookupIsAlsoCachedWithinItsWindow(): void
    {
        $this->setUmamiEnv(self::API_KEY, self::WEBSITE_ID);
        UmamiHttpClientStub::$fails = true;
        $this->boot();
        $this->client->disableReboot();
        UmamiHttpClientStub::$requestCount = 0;

        $this->client->request('GET', '/api/admin/audience', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->responseBody()['available']);
        $afterFirst = UmamiHttpClientStub::$requestCount;
        self::assertGreaterThan(0, $afterFirst, 'le 1er appel a bien tenté Umami');

        $this->client->request('GET', '/api/admin/audience', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        self::assertSame($afterFirst, UmamiHttpClientStub::$requestCount, 'un échec est caché aussi → Umami n\'est pas martelé');
    }

    public function testTheApiKeyNeverLeaksIntoTheResponse(): void
    {
        $this->setUmamiEnv(self::API_KEY, self::WEBSITE_ID);
        $this->boot();

        $this->client->request('GET', '/api/admin/audience', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            self::API_KEY,
            (string) $this->client->getResponse()->getContent(),
            'la clé API ne doit jamais apparaître dans le JSON rendu',
        );
    }

    protected function setUp(): void
    {
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        UmamiHttpClientStub::reset();
        $this->setUmamiEnv(self::API_KEY, self::WEBSITE_ID);
    }

    protected function tearDown(): void
    {
        if (isset($this->client) && null !== $this->adminId) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        $this->clearUmamiEnv();
        parent::tearDown();
    }

    private function boot(): string
    {
        $this->client = self::createClient();
        [$secret] = $this->createSuperAdmin('audience@example.test', 'VeryStrongPassword!');

        return $this->authenticate('audience@example.test', 'VeryStrongPassword!', $secret);
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

    private function setUmamiEnv(string $apiKey, string $websiteId): void
    {
        foreach (['UMAMI_API_KEY' => $apiKey, 'UMAMI_WEBSITE_ID' => $websiteId] as $key => $value) {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    private function clearUmamiEnv(): void
    {
        foreach (['UMAMI_API_KEY', 'UMAMI_WEBSITE_ID'] as $key) {
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
