<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use App\Tests\Double\RecordingAdminJobExecutor;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

#[Group('phase1')]
#[Group('integration')]
final class SuperAdminAccessTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private string $adminId;

    private string $requestIp;

    /** @var list<string> */
    private array $metricIds = [];

    /** @var list<string> */
    private array $monitoringClubIds = [];

    /** @var list<string> */
    private array $jobRunIds = [];

    public function testClubJwtCanNeverCrossTheAdminFirewall(): void
    {
        $token = $this->registerVerified('SAA1');
        $this->client->request('GET', '/api/admin/auth/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/admin/overview', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/admin/capacity', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/admin/health', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/admin/jobs', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/admin/freshness', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/admin/jobs/import-school-holidays/run', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        // P5-12 — l'atelier du journal de nouveautés est une surface admin : un JWT
        // club ne franchit pas le firewall, en lecture comme en écriture.
        $this->client->request('GET', '/api/admin/release-notes', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/admin/release-notes', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        // P5-6 — le rail superadmin des signalements est une surface admin : un JWT
        // club ne le franchit pas, en lecture comme en écriture.
        $this->client->request('GET', '/api/admin/feedback', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/admin/feedback/' . Uuid::v4()->toRfc4122() . '/treat', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);

        // Anonyme (aucune identité) : même refus.
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/admin/release-notes');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/admin/feedback');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedAdminCanReadCrossTenantMonitoringAggregates(): void
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $clubId = Uuid::v4()->toRfc4122();
        $clubName = 'Club monitoring ' . $suffix;
        $this->monitoringClubIds[] = $clubId;
        $this->admin()->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed) VALUES (:id, 1, NOW(), NOW(), :name, :slug, 2, :timezone, :locale, FALSE)',
            ['id' => $clubId, 'name' => $clubName, 'slug' => 'monitoring-' . strtolower($suffix), 'timezone' => 'Europe/Paris', 'locale' => 'fr'],
        );
        self::assertNotSame('', $clubId);

        foreach (['COMPLETED', 'INFEASIBLE'] as $status) {
            $metricId = Uuid::v4()->toRfc4122();
            $this->metricIds[] = $metricId;
            $this->admin()->executeStatement(
                'INSERT INTO solver_metrics (id, schedule_id, club_id, status, wall_time_ms, created_at) VALUES (:id, :schedule, :club, :status, :duration, NOW())',
                [
                    'id' => $metricId,
                    'schedule' => Uuid::v4()->toRfc4122(),
                    'club' => $clubId,
                    'status' => $status,
                    'duration' => 'COMPLETED' === $status ? 1000 : 3000,
                ],
            );
        }

        [$secret] = $this->createSuperAdmin('monitoring@example.test', 'VeryStrongPassword!');
        $this->authenticate('monitoring@example.test', 'VeryStrongPassword!', $secret);

        $this->client->request('GET', '/api/admin/overview');
        self::assertResponseIsSuccessful();
        $overview = $this->responseBody();
        self::assertGreaterThanOrEqual(1, $overview['clubs']['total']);
        self::assertGreaterThanOrEqual(2, $overview['solver']['generations']);
        self::assertArrayHasKey('daily', $overview['solver']);

        $this->client->request('GET', '/api/admin/capacity');
        self::assertResponseIsSuccessful();
        $capacity = $this->responseBody();
        self::assertSame(90, $capacity['windowDays']);
        self::assertGreaterThanOrEqual(2, $capacity['totalSolves']);
        foreach (['volume', 'wait', 'bySize', 'memory', 'issues'] as $section) {
            self::assertArrayHasKey($section, $capacity);
        }

        $this->client->request('GET', '/api/admin/clubs?query=' . urlencode($clubName) . '&limit=10');
        self::assertResponseIsSuccessful();
        $clubs = $this->responseBody();
        self::assertSame(1, $clubs['pagination']['total']);
        self::assertSame($clubId, $clubs['items'][0]['id']);
        self::assertSame(2, $clubs['items'][0]['solver']['generations']);
        self::assertSame(1, $clubs['items'][0]['solver']['infeasible']);
        self::assertSame(0.5, $clubs['items'][0]['solver']['infeasibleRate']);

        $this->client->request('GET', '/api/admin/clubs?limit=101');
        self::assertResponseStatusCodeSame(400);

        // P5-6 — le rail des signalements est lisible par le SA authentifié (liste
        // paginée + bloc QoS), même quand rien n'est en attente.
        $this->client->request('GET', '/api/admin/feedback');
        self::assertResponseIsSuccessful();
        $feedback = $this->responseBody();
        self::assertArrayHasKey('items', $feedback);
        self::assertArrayHasKey('pagination', $feedback);
        self::assertArrayHasKey('qos', $feedback);
    }

    public function testAuthenticatedAdminCanReadBoundedInfrastructureHealthWithoutInternalErrors(): void
    {
        [$secret] = $this->createSuperAdmin('health@example.test', 'VeryStrongPassword!');
        $this->authenticate('health@example.test', 'VeryStrongPassword!', $secret);

        $this->client->request('GET', '/api/admin/health');
        self::assertResponseIsSuccessful();
        $health = $this->responseBody();
        self::assertContains($health['status'], ['healthy', 'degraded']);
        self::assertArrayHasKey('checkedAt', $health);
        foreach (['database', 'redis', 'engine', 'worker', 'mercure'] as $service) {
            self::assertArrayHasKey($service, $health['services']);
            self::assertContains($health['services'][$service]['status'], ['up', 'down', 'unknown']);
        }
        self::assertContains($health['messenger']['status'], ['up', 'degraded', 'unknown']);
        self::assertArrayHasKey('backlog', $health['messenger']);
        self::assertArrayHasKey('failed', $health['messenger']);
        self::assertArrayHasKey('retriesToday', $health['messenger']);
    }

    public function testAuthenticatedAdminCanReadTheAllowlistedJobsAndTheirLatestRun(): void
    {
        $olderId = Uuid::v4()->toRfc4122();
        $latestId = Uuid::v4()->toRfc4122();
        $this->jobRunIds = [$olderId, $latestId];
        foreach ([
            ['id' => $olderId, 'status' => 'failed', 'started' => '2026-07-16 08:00:00+00', 'finished' => '2026-07-16 08:00:02+00', 'duration' => 2000, 'exit' => 1],
            ['id' => $latestId, 'status' => 'succeeded', 'started' => '2026-07-16 09:00:00+00', 'finished' => '2026-07-16 09:00:01+00', 'duration' => 1000, 'exit' => 0],
        ] as $run) {
            $this->admin()->insert('admin_job_run', [
                'id' => $run['id'],
                'job_key' => 'period-reminders',
                'command_name' => 'app:periods:remind',
                'source' => 'scheduled',
                'status' => $run['status'],
                'started_at' => $run['started'],
                'finished_at' => $run['finished'],
                'duration_ms' => $run['duration'],
                'exit_code' => $run['exit'],
                'scheduled_for' => $run['started'],
            ]);
        }

        [$secret] = $this->createSuperAdmin('jobs@example.test', 'VeryStrongPassword!');
        $this->authenticate('jobs@example.test', 'VeryStrongPassword!', $secret);

        $this->client->request('GET', '/api/admin/jobs');
        self::assertResponseIsSuccessful();
        $jobs = $this->responseBody()['items'];
        self::assertCount(16, $jobs); // +coach-wish-digest (#10 C3) +club-approval-digest (P3-4 PR B) +purge-exports (P4-52) +feedback-digest (P5-6)
        $jobsByKey = array_column($jobs, null, 'key');
        self::assertSame('daily', $jobsByKey['coach-wish-digest']['cadence']);
        self::assertSame('daily', $jobsByKey['feedback-digest']['cadence']);
        self::assertFalse($jobsByKey['feedback-digest']['manualTriggerAllowed']);
        self::assertSame('every_10_minutes', $jobsByKey['reconcile-stuck-schedules']['cadence']);
        self::assertSame('every_10_minutes', $jobsByKey['health-alerts']['cadence']);
        self::assertFalse($jobsByKey['health-alerts']['manualTriggerAllowed']);
        self::assertSame('daily', $jobsByKey['period-reminders']['cadence']);
        self::assertNotEmpty($jobsByKey['period-reminders']['nextRunAt']);
        self::assertSame($latestId, $jobsByKey['period-reminders']['latestRun']['id']);
        self::assertSame('succeeded', $jobsByKey['period-reminders']['latestRun']['status']);
        self::assertSame(1000, $jobsByKey['period-reminders']['latestRun']['durationMs']);
        self::assertNull($jobsByKey['transition-reminders']['latestRun']);
        self::assertFalse($jobsByKey['purge-seasons']['manualTriggerAllowed']);
        self::assertTrue($jobsByKey['import-school-holidays']['manualTriggerAllowed']);
        self::assertTrue($jobsByKey['import-public-holidays']['manualTriggerAllowed']);
    }

    public function testManualJobTriggerRequiresCsrfAndOnlyAllowsReferenceImports(): void
    {
        [$secret] = $this->createSuperAdmin('manual-jobs@example.test', 'VeryStrongPassword!');
        $csrfToken = $this->authenticate('manual-jobs@example.test', 'VeryStrongPassword!', $secret);
        $this->client->disableReboot();
        $executor = self::getContainer()->get(RecordingAdminJobExecutor::class);
        $executor->reset();
        $executor->alreadyRunningForKey = 'import-public-holidays';

        $this->client->request('POST', '/api/admin/jobs/import-school-holidays/run');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/admin/jobs/purge-seasons/run', [], [], ['HTTP_X_CSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $executor->calls);

        $this->client->request('POST', '/api/admin/jobs/import-school-holidays/run', [], [], ['HTTP_X_CSRF_TOKEN' => $csrfToken]);
        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'import-school-holidays', 'status' => 'succeeded', 'exitCode' => 0], $this->responseBody());
        self::assertSame([['key' => 'import-school-holidays', 'superAdminId' => $this->adminId, 'arguments' => []]], $executor->calls);

        $this->client->request('POST', '/api/admin/jobs/import-public-holidays/run', [], [], ['HTTP_X_CSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Operational job already running.', $this->responseBody()['error']);

        $executor->alreadyRunningForKey = null;
        $executor->exitCodes['import-public-holidays'] = Command::FAILURE;
        $this->client->request('POST', '/api/admin/jobs/import-public-holidays/run', [], [], ['HTTP_X_CSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(502);
        self::assertSame(1, $this->responseBody()['exitCode']);
    }

    public function testPasswordAloneDoesNotAuthenticateAndTotpIsMandatory(): void
    {
        $this->createSuperAdmin('root@example.test', 'VeryStrongPassword!');
        $this->json('POST', '/api/admin/auth/password', ['email' => 'root@example.test', 'password' => 'VeryStrongPassword!']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/admin/auth/me');
        self::assertResponseStatusCodeSame(401, 'password stage must not create an admin session');

        $this->json('POST', '/api/admin/auth/totp', ['code' => '000000']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testValidPasswordAndTotpCreateAnAdminOnlySessionWithoutTenant(): void
    {
        [$secret] = $this->createSuperAdmin('ops@example.test', 'VeryStrongPassword!');
        $this->json('POST', '/api/admin/auth/password', ['email' => 'ops@example.test', 'password' => 'VeryStrongPassword!']);
        self::assertResponseIsSuccessful();

        $totp = self::getContainer()->get(TotpService::class);
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/admin/auth/me');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('ops@example.test', $body['email']);

        self::assertSame('', (string) self::getContainer()->get(ManagerRegistry::class)->getConnection()->fetchOne('SELECT current_setting(\'app.club_id\', true)'));
        self::assertGreaterThanOrEqual(3, (int) $this->admin()->fetchOne('SELECT COUNT(*) FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]));
    }

    public function testDisabledAdminAndExpiredChallengeAreRejected(): void
    {
        $this->createSuperAdmin('disabled@example.test', 'VeryStrongPassword!', false);
        $this->json('POST', '/api/admin/auth/password', ['email' => 'disabled@example.test', 'password' => 'VeryStrongPassword!']);
        self::assertResponseStatusCodeSame(401);

        $this->admin()->executeStatement('UPDATE super_admin SET enabled = TRUE WHERE id = :id', ['id' => $this->adminId]);
        $this->json('POST', '/api/admin/auth/password', ['email' => 'disabled@example.test', 'password' => 'VeryStrongPassword!']);
        self::assertResponseIsSuccessful();
        $session = $this->client->getRequest()->getSession();
        $session->set('admin_pending_at', time() - 301);
        $session->save();

        $this->json('POST', '/api/admin/auth/totp', ['code' => '123456']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Authentication challenge expired.', $this->responseBody()['error']);
    }

    public function testDisablingAnAdminRevokesItsExistingSession(): void
    {
        $secret = $this->createSuperAdmin('revoked@example.test', 'VeryStrongPassword!')[0];
        $this->authenticate('revoked@example.test', 'VeryStrongPassword!', $secret);
        $this->admin()->executeStatement('UPDATE super_admin SET enabled = FALSE WHERE id = :id', ['id' => $this->adminId]);

        $this->client->request('GET', '/api/admin/auth/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRequiresCsrfAndDestroysTheAdminSession(): void
    {
        $secret = $this->createSuperAdmin('logout@example.test', 'VeryStrongPassword!')[0];
        $csrfToken = $this->authenticate('logout@example.test', 'VeryStrongPassword!', $secret);

        $this->client->request('POST', '/api/admin/auth/logout');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/admin/auth/logout', [], [], ['HTTP_X_CSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/admin/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminAuthenticationIsRateLimitedPerIp(): void
    {
        $ip = '203.0.113.42';
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->json('POST', '/api/admin/auth/password', [
                'email' => 'missing@example.test',
                'password' => 'wrong-password',
            ], ['REMOTE_ADDR' => $ip]);
        }

        self::assertResponseStatusCodeSame(429);
    }

    public function testRuntimeRoleHasNoPrivilegeOnAdminOnlyTables(): void
    {
        foreach (['super_admin', 'admin_audit_log', 'admin_job_run'] as $table) {
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                self::assertFalse((bool) $this->admin()->fetchOne(
                    'SELECT has_table_privilege(\'amateo_app\', :table, :privilege)',
                    ['table' => $table, 'privilege' => $privilege],
                ));
            }
        }
    }

    public function testCreateCommandCreatesAnMfaIdentityAndRejectsDuplicateEmail(): void
    {
        $email = 'command@example.test';
        $application = new Application(self::$kernel);
        $weakPassword = new CommandTester($application->find('app:superadmin:create'));
        $weakPassword->setInputs(['onlylowercase!']);
        self::assertSame(Command::INVALID, $weakPassword->execute(['email' => 'weak@example.test']));

        $tester = new CommandTester($application->find('app:superadmin:create'));
        $tester->setInputs(['A-command-password-123!', 'A-command-password-123!']);

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => $email]));
        self::assertStringContainsString('Provisioning URI:', $tester->getDisplay());
        $this->adminId = (string) $this->admin()->fetchOne('SELECT id FROM super_admin WHERE email = :email', ['email' => $email]);
        self::assertNotSame('', $this->adminId);

        $duplicate = new CommandTester($application->find('app:superadmin:create'));
        $duplicate->setInputs(['A-command-password-123!', 'A-command-password-123!']);
        self::assertSame(Command::FAILURE, $duplicate->execute(['email' => strtoupper($email)]));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        if ([] !== $this->jobRunIds) {
            $this->admin()->executeStatement('DELETE FROM admin_job_run WHERE id IN (:ids)', ['ids' => $this->jobRunIds], ['ids' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->metricIds) {
            $this->admin()->executeStatement('DELETE FROM solver_metrics WHERE id IN (:ids)', ['ids' => $this->metricIds], ['ids' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->monitoringClubIds) {
            $this->admin()->executeStatement('DELETE FROM club WHERE id IN (:ids)', ['ids' => $this->monitoringClubIds], ['ids' => ArrayParameterType::STRING]);
        }
        if (isset($this->adminId)) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    /** @return array{0: string} */
    private function createSuperAdmin(string $email, string $password, bool $enabled = true): array
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, $password));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, :enabled, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret(), 'enabled' => $enabled],
            ['enabled' => ParameterType::BOOLEAN],
        );

        return [$secret];
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
            'firstName' => 'Super',
            'lastName' => 'Admin isolation',
            'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $ara,
            'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        return $token;
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

    /** @param array<string, mixed> $body */
    /** @param array<string, string> $server */
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

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
