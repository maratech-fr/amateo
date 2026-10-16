<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\AdminDataFreshnessService;
use App\Service\BackupCoverage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * La ligne de fraîcheur « Contrôle de restauration » rend VISIBLE un backup jamais
 * reprouvé : elle n'est fraîche qu'avec un run RÉUSSI de app:db:restore-check de moins
 * de 8 jours. Un run en ÉCHEC ne la rafraîchit jamais (c'est précisément la panne qu'on
 * veut voir). Lecture sur la connexion admin, patron de AdminDataFreshnessService::fromImport.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminDataFreshnessRestoreCheckTest extends KernelTestCase
{
    private const JOB_KEY = 'db-restore-check';

    private Connection $admin;

    private ManagerRegistry $registry;

    public function testRowIsFreshWhenASuccessfulRunIsWithinEightDays(): void
    {
        $now = new DateTimeImmutable('2026-07-20T12:00:00+02:00');
        $this->insertRun('succeeded', $now->modify('-7 days'));

        $row = $this->restoreCheckRow($now);
        self::assertSame(8, $row['staleAfterDays']);
        self::assertNotNull($row['lastUpdatedAt']);
        self::assertFalse($row['stale']);
    }

    public function testRowIsStaleWhenTheLastSuccessfulRunIsOlderThanEightDays(): void
    {
        $now = new DateTimeImmutable('2026-07-20T12:00:00+02:00');
        $this->insertRun('succeeded', $now->modify('-9 days'));

        self::assertTrue($this->restoreCheckRow($now)['stale']);
    }

    public function testAFailedRunNeverRefreshesTheRow(): void
    {
        // Un run RÉCENT mais en ÉCHEC ne doit pas verdir la ligne : seul le dernier run
        // RÉUSSI (ici vieux de 9 jours) compte. Si la requête comptait les échecs,
        // lastUpdatedAt remonterait à hier et la ligne passerait fraîche à tort.
        $now = new DateTimeImmutable('2026-07-20T12:00:00+02:00');
        $this->insertRun('succeeded', $now->modify('-9 days'));
        $this->insertRun('failed', $now->modify('-1 day'));

        self::assertTrue($this->restoreCheckRow($now)['stale']);
    }

    public function testRowIsStaleWhenNoRunHasEverSucceeded(): void
    {
        // Jamais de run réussi (seulement un échec) → périmé (fail-visible), jamais « pas d'info ».
        $now = new DateTimeImmutable('2026-07-20T12:00:00+02:00');
        $this->insertRun('failed', $now->modify('-1 day'));

        $row = $this->restoreCheckRow($now);
        self::assertNull($row['lastUpdatedAt']);
        self::assertTrue($row['stale']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $this->registry = $registry;
        $this->admin = $registry->getConnection('admin');
        // Nettoyage CIBLÉ (jamais un wipe global) : uniquement les runs de ce job.
        $this->admin->executeStatement('DELETE FROM admin_job_run WHERE job_key = :key', ['key' => self::JOB_KEY]);
    }

    protected function tearDown(): void
    {
        $this->admin->executeStatement('DELETE FROM admin_job_run WHERE job_key = :key', ['key' => self::JOB_KEY]);
        parent::tearDown();
    }

    private function insertRun(string $status, DateTimeImmutable $finishedAt): void
    {
        $this->admin->insert('admin_job_run', [
            'id' => \sprintf('00000000-0000-4000-8000-%012x', random_int(1, 0xFFFFFFFFFFFF)),
            'job_key' => self::JOB_KEY,
            'command_name' => 'app:db:restore-check',
            'source' => 'cli',
            'status' => $status,
            'started_at' => $finishedAt->modify('-1 minute')->format('Y-m-d H:i:sP'),
            'finished_at' => $finishedAt->format('Y-m-d H:i:sP'),
        ]);
    }

    /** @return array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool} */
    private function restoreCheckRow(DateTimeImmutable $now): array
    {
        // Service construit avec une horloge figée : la fraîcheur se mesure au jour près,
        // sans dépendre de l'instant réel du run de tests.
        $service = new AdminDataFreshnessService(
            $this->registry,
            new MockClock($now),
            new BackupCoverage($this->registry),
            sys_get_temp_dir() . '/amateo-no-backups-' . bin2hex(random_bytes(4)),
        );

        foreach ($service->referentials() as $row) {
            if (self::JOB_KEY === $row['key']) {
                return $row;
            }
        }

        self::fail('AdminDataFreshnessService::referentials() is missing the db-restore-check row.');
    }
}
