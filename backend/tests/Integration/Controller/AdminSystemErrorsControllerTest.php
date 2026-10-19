<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

#[Group('phase1')]
#[Group('integration')]
final class AdminSystemErrorsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private string $adminId;

    private string $requestIp;

    /** @var list<string> */
    private array $jobRunIds = [];

    /** @var list<string> */
    private array $auditLogIds = [];

    public function testEmptyTablesReturns200WithEmptyItems(): void
    {
        [$secret] = $this->createSuperAdmin('empty@example.test', 'VeryStrongPassword!');
        $this->authenticate('empty@example.test', 'VeryStrongPassword!', $secret);
        $this->clearSystemErrors();

        $this->client->request('GET', '/api/admin/system-errors');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame([], $body['items']);
        self::assertSame(1, $body['pagination']['page']);
        self::assertSame(50, $body['pagination']['limit']);
        self::assertSame(0, $body['pagination']['total']);
        self::assertSame(0, $body['pagination']['pages']);
    }

    public function testOneFailedJobAndOneAuditErrorWithDifferentMessagesReturnsTwoItems(): void
    {
        [$secret] = $this->createSuperAdmin('two@example.test', 'VeryStrongPassword!');
        $this->authenticate('two@example.test', 'VeryStrongPassword!', $secret);
        $this->clearSystemErrors();

        $this->seedJobRun('job_error_alpha', 'failed');
        $this->seedAuditLog('auth.login_failed');

        $this->client->request('GET', '/api/admin/system-errors');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertCount(2, $body['items']);
        $messages = array_column($body['items'], 'message');
        self::assertContains('job_error_alpha', $messages);
        self::assertContains('auth.login_failed', $messages);
    }

    public function testSameMessageInSameHourFromDifferentSourcesIsDedupedToOneItem(): void
    {
        [$secret] = $this->createSuperAdmin('dedup@example.test', 'VeryStrongPassword!');
        $this->authenticate('dedup@example.test', 'VeryStrongPassword!', $secret);
        $this->clearSystemErrors();

        $hour = '2026-01-15 10:30:00+00';
        $this->seedJobRun('auth.login_failed', 'failed', $hour);
        $this->seedAuditLog('auth.login_failed', $hour);

        $this->client->request('GET', '/api/admin/system-errors');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertCount(1, $body['items']);
        self::assertSame('auth.login_failed', $body['items'][0]['message']);
    }

    public function testPaginationPageTwoWithLimitOne(): void
    {
        [$secret] = $this->createSuperAdmin('page@example.test', 'VeryStrongPassword!');
        $this->authenticate('page@example.test', 'VeryStrongPassword!', $secret);
        $this->clearSystemErrors();

        $this->seedJobRun('job_oldest', 'failed', '2026-01-15 08:00:00+00');
        $this->seedJobRun('job_middle', 'interrupted', '2026-01-15 09:00:00+00');
        $this->seedAuditLog('auth.login_failed', '2026-01-15 10:00:00+00');

        $this->client->request('GET', '/api/admin/system-errors?page=2&limit=1');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertCount(1, $body['items']);
        self::assertSame('job_middle', $body['items'][0]['message']);
        self::assertSame(2, $body['pagination']['page']);
        self::assertSame(1, $body['pagination']['limit']);
        self::assertSame(3, $body['pagination']['total']);
        self::assertSame(3, $body['pagination']['pages']);
    }

    public function testInvalidSinceDateReturns400(): void
    {
        [$secret] = $this->createSuperAdmin('bad-since@example.test', 'VeryStrongPassword!');
        $this->authenticate('bad-since@example.test', 'VeryStrongPassword!', $secret);
        $this->clearSystemErrors();

        $this->client->request('GET', '/api/admin/system-errors?since=2024-13-01');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid since date. Expected ISO date (YYYY-MM-DD).', $this->responseBody()['error']);
    }

    /**
     * Promesse : un `since` valide filtre RÉELLEMENT les DEUX sources par leur date réelle
     * (admin_job_run.started_at et audit_log.occurred_at, pas l'alias created_at) et répond 200 —
     * les occurrences antérieures à `since` sont exclues, une par source est conservée.
     */
    public function testValidSinceFiltersBothSourcesByTheirRealDateAndExcludesOlder(): void
    {
        [$secret] = $this->createSuperAdmin('valid-since@example.test', 'VeryStrongPassword!');
        $this->authenticate('valid-since@example.test', 'VeryStrongPassword!', $secret);
        $this->clearSystemErrors();

        $this->seedJobRun('job_recent', 'failed', '2026-06-15 10:00:00+00');
        $this->seedJobRun('job_old', 'failed', '2026-05-01 10:00:00+00');
        $this->seedAuditLog('auth.login_failed', '2026-06-20 10:00:00+00');
        $this->seedAuditLog('auth.login_failed', '2026-05-10 10:00:00+00');

        $this->client->request('GET', '/api/admin/system-errors?since=2026-06-01');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame(2, $body['pagination']['total']);
        $messages = array_column($body['items'], 'message');
        self::assertContains('job_recent', $messages);
        self::assertContains('auth.login_failed', $messages);
        self::assertNotContains('job_old', $messages);
    }

    /**
     * Promesse : la borne `since` est incluse depuis minuit — un événement à 00:05 le jour de
     * `since` est conservé, celui de la veille à 23:55 est exclu (source audit_log).
     */
    public function testSinceIsInclusiveFromMidnightSoAnEventAtFiveMinutesPastIsKept(): void
    {
        [$secret] = $this->createSuperAdmin('since-boundary@example.test', 'VeryStrongPassword!');
        $this->authenticate('since-boundary@example.test', 'VeryStrongPassword!', $secret);
        $this->clearSystemErrors();

        $this->seedAuditLog('auth.login_failed', '2026-07-01 00:05:00+00');
        $this->seedAuditLog('auth.login_failed', '2026-06-30 23:55:00+00');

        $this->client->request('GET', '/api/admin/system-errors?since=2026-07-01');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame(1, $body['pagination']['total']);
        self::assertSame('auth.login_failed', $body['items'][0]['message']);
    }

    public function testNegativePageReturns400(): void
    {
        [$secret] = $this->createSuperAdmin('bad-page@example.test', 'VeryStrongPassword!');
        $this->authenticate('bad-page@example.test', 'VeryStrongPassword!', $secret);
        $this->clearSystemErrors();

        $this->client->request('GET', '/api/admin/system-errors?page=-1');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid page: must be a positive integer.', $this->responseBody()['error']);
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        $this->client->request('GET', '/api/admin/system-errors');
        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        if ([] !== $this->jobRunIds) {
            $this->admin()->executeStatement(
                'DELETE FROM admin_job_run WHERE id IN (:ids)',
                ['ids' => $this->jobRunIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->auditLogIds) {
            $this->admin()->executeStatement(
                'DELETE FROM audit_log WHERE id IN (:ids)',
                ['ids' => $this->auditLogIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
        if (isset($this->adminId)) {
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
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

    private function clearSystemErrors(): void
    {
        $this->admin()->executeStatement(
            'DELETE FROM admin_job_run WHERE super_admin_id = :id',
            ['id' => $this->adminId],
        );
        $this->admin()->executeStatement(
            'DELETE FROM audit_log WHERE actor_user_id = :id',
            ['id' => $this->adminId],
        );
    }

    private function seedJobRun(string $commandName, string $status, string $startedAt = 'NOW()'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->jobRunIds[] = $id;

        if ('NOW()' === $startedAt) {
            $this->admin()->executeStatement(
                'INSERT INTO admin_job_run (id, job_key, command_name, source, status, started_at, super_admin_id) VALUES (:id, :job_key, :command_name, :source, :status, NOW(), :admin_id)',
                [
                    'id' => $id,
                    'job_key' => 'test.' . $commandName,
                    'command_name' => $commandName,
                    'source' => 'superadmin',
                    'status' => $status,
                    'admin_id' => $this->adminId,
                ],
            );
        } else {
            $this->admin()->executeStatement(
                'INSERT INTO admin_job_run (id, job_key, command_name, source, status, started_at, super_admin_id) VALUES (:id, :job_key, :command_name, :source, :status, :started_at, :admin_id)',
                [
                    'id' => $id,
                    'job_key' => 'test.' . $commandName,
                    'command_name' => $commandName,
                    'source' => 'superadmin',
                    'status' => $status,
                    'started_at' => $startedAt,
                    'admin_id' => $this->adminId,
                ],
            );
        }

        return $id;
    }

    private function seedAuditLog(string $action, string $occurredAt = 'NOW()'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->auditLogIds[] = $id;

        if ('NOW()' === $occurredAt) {
            $this->admin()->executeStatement(
                'INSERT INTO audit_log (id, occurred_at, actor_user_id, club_id, action, entity_type, entity_id, details) VALUES (:id, NOW(), :actor, NULL, :action, NULL, NULL, :details)',
                [
                    'id' => $id,
                    'actor' => $this->adminId,
                    'action' => $action,
                    'details' => '{}',
                ],
            );
        } else {
            $this->admin()->executeStatement(
                'INSERT INTO audit_log (id, occurred_at, actor_user_id, club_id, action, entity_type, entity_id, details) VALUES (:id, :occurred_at, :actor, NULL, :action, NULL, NULL, :details)',
                [
                    'id' => $id,
                    'occurred_at' => $occurredAt,
                    'actor' => $this->adminId,
                    'action' => $action,
                    'details' => '{}',
                ],
            );
        }

        return $id;
    }

    /** @param array<string, mixed> $body */
    private function json(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
