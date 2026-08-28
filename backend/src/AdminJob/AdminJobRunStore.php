<?php

declare(strict_types=1);

namespace App\AdminJob;

use App\Enum\AdminJobSource;
use App\Enum\AdminJobStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/** Admin-connection store. No command output or exception text is persisted. */
final readonly class AdminJobRunStore
{
    public function __construct(private ManagerRegistry $managerRegistry) {}

    public function tryAcquire(string $jobKey): bool
    {
        return (bool) $this->connection()->fetchOne(
            'SELECT pg_try_advisory_lock(hashtext(\'amateo.admin_job\'), hashtext(:job_key))',
            ['job_key' => $jobKey],
        );
    }

    public function release(string $jobKey): void
    {
        $this->connection()->fetchOne(
            'SELECT pg_advisory_unlock(hashtext(\'amateo.admin_job\'), hashtext(:job_key))',
            ['job_key' => $jobKey],
        );
    }

    public function start(AdminJobDefinition $definition, AdminJobSource $source, ?string $superAdminId, ?DateTimeImmutable $scheduledFor = null): string
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'UPDATE admin_job_run SET status = :interrupted, finished_at = NOW(), duration_ms = LEAST(GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000)), 2147483647)::INT WHERE job_key = :job_key AND status = :running',
            ['job_key' => $definition->key, 'interrupted' => AdminJobStatus::INTERRUPTED->value, 'running' => AdminJobStatus::RUNNING->value],
        );

        $id = $this->newUuid();
        $connection->insert('admin_job_run', [
            'id' => $id,
            'job_key' => $definition->key,
            'command_name' => $definition->command,
            'source' => $source->value,
            'status' => AdminJobStatus::RUNNING->value,
            'started_at' => (new DateTimeImmutable)->format('Y-m-d H:i:sP'),
            'super_admin_id' => $superAdminId,
            'scheduled_for' => $scheduledFor?->format('Y-m-d H:i:sP'),
            // SA4 : la trace « quelle action, sur QUEL club » — les arguments du
            // catalogue (jamais de saisie libre). null pour les jobs sans paramètre.
            'arguments' => [] === $definition->arguments ? null : json_encode($definition->arguments, \JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    public function latestScheduledFor(string $jobKey): ?DateTimeImmutable
    {
        $value = $this->connection()->fetchOne(
            'SELECT MAX(scheduled_for) FROM admin_job_run WHERE job_key = :job_key AND scheduled_for IS NOT NULL AND status <> :interrupted',
            ['job_key' => $jobKey, 'interrupted' => AdminJobStatus::INTERRUPTED->value],
        );

        return false === $value || null === $value ? null : new DateTimeImmutable((string) $value);
    }

    public function finish(string $runId, AdminJobStatus $status, int $exitCode): void
    {
        $this->connection()->executeStatement(
            'UPDATE admin_job_run SET status = :status, finished_at = NOW(), duration_ms = LEAST(GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000)), 2147483647)::INT, exit_code = :exit_code WHERE id = :id',
            ['id' => $runId, 'status' => $status->value, 'exit_code' => $exitCode],
        );
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
