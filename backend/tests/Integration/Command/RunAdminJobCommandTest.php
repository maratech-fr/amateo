<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\AdminJob\AdminJobCatalog;
use App\AdminJob\AdminJobDefinition;
use App\AdminJob\AdminJobRunner;
use App\AdminJob\AdminJobRunStore;
use App\AdminJob\AdminJobSchedule;
use App\Command\RunAdminJobCommand;
use App\Command\RunDueAdminJobsCommand;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('phase1')]
#[Group('integration')]
final class RunAdminJobCommandTest extends KernelTestCase
{
    private Connection $admin;

    public function testSuccessfulRunIsPersistedWithoutCommandOutput(): void
    {
        $tester = $this->testerFor('test:job:success', Command::SUCCESS);

        self::assertSame(Command::SUCCESS, $tester->execute(['job' => 'test-job', '--source' => 'scheduled', '--scheduled-for' => '2026-07-16T08:00:00+00:00']));

        $run = $this->singleRun();
        self::assertSame('test-job', $run['job_key']);
        self::assertSame('test:job:success', $run['command_name']);
        self::assertSame('scheduled', $run['source']);
        self::assertSame('2026-07-16T08:00:00+00:00', new DateTimeImmutable((string) $run['scheduled_for'])->format(DateTimeInterface::ATOM));
        self::assertSame('succeeded', $run['status']);
        self::assertSame(0, (int) $run['exit_code']);
        self::assertGreaterThanOrEqual(0, (int) $run['duration_ms']);
        self::assertNotNull($run['finished_at']);
        self::assertArrayNotHasKey('output', $run);
        self::assertArrayNotHasKey('error_message', $run);
    }

    public function testNonZeroAndUnexpectedFailuresArePersisted(): void
    {
        $failed = $this->testerFor('test:job:failure', 7);
        self::assertSame(7, $failed->execute(['job' => 'test-job']));
        self::assertSame(['status' => 'failed', 'exit_code' => 7], $this->admin->fetchAssociative('SELECT status, exit_code FROM admin_job_run'));

        $this->admin->executeStatement('DELETE FROM admin_job_run');
        $throwing = $this->testerFor('test:job:throws', new RuntimeException('sensitive internal detail'));
        self::assertSame(Command::FAILURE, $throwing->execute(['job' => 'test-job']));
        self::assertSame(['status' => 'failed', 'exit_code' => 1], $this->admin->fetchAssociative('SELECT status, exit_code FROM admin_job_run'));
    }

    public function testStaleRunningAttemptIsInterruptedBeforeTheNextRun(): void
    {
        $staleId = '00000000-0000-4000-8000-000000000001';
        $this->admin->insert('admin_job_run', [
            'id' => $staleId,
            'job_key' => 'test-job',
            'command_name' => 'test:job:old',
            'source' => 'scheduled',
            'status' => 'running',
            // Date RELATIVE, > 24,86 jours (2^31 ms) : une date figée a explosé le
            // 2026-08-10 (integer out of range sur duration_ms) — le cas ancien doit
            // rester EXERCÉ, c'est lui qui garde le plafond LEAST du run store.
            'started_at' => new DateTimeImmutable('-40 days')->format('Y-m-d H:i:sP'),
        ]);

        $tester = $this->testerFor('test:job:success', Command::SUCCESS);
        self::assertSame(Command::SUCCESS, $tester->execute(['job' => 'test-job']));

        self::assertSame('interrupted', $this->admin->fetchOne('SELECT status FROM admin_job_run WHERE id = :id', ['id' => $staleId]));
        self::assertSame(1, (int) $this->admin->fetchOne('SELECT COUNT(*) FROM admin_job_run WHERE job_key = \'test-job\' AND status = \'succeeded\''));
    }

    public function testUnknownKeyCannotExecuteAnArbitraryCommand(): void
    {
        $tester = $this->testerFor('test:job:success', Command::SUCCESS);

        self::assertSame(Command::INVALID, $tester->execute(['job' => 'cache:clear']));
        self::assertSame(0, (int) $this->admin->fetchOne('SELECT COUNT(*) FROM admin_job_run'));
    }

    public function testScheduledSourceRequiresAValidSlotAndCliRejectsOne(): void
    {
        $tester = $this->testerFor('test:job:success', Command::SUCCESS);

        self::assertSame(Command::INVALID, $tester->execute(['job' => 'test-job', '--source' => 'scheduled']));
        self::assertSame(Command::INVALID, $tester->execute(['job' => 'test-job', '--source' => 'scheduled', '--scheduled-for' => 'tomorrow']));
        self::assertSame(Command::INVALID, $tester->execute(['job' => 'test-job', '--scheduled-for' => '2026-07-16T08:00:00+00:00']));
        self::assertSame(0, (int) $this->admin->fetchOne('SELECT COUNT(*) FROM admin_job_run'));
    }

    public function testDueRunnerCatchesUpOnceAndDoesNotDuplicateTheScheduledSlot(): void
    {
        $definition = new AdminJobDefinition('test-job', 'Test job', 'test:job:count', AdminJobSchedule::daily(8));
        $catalog = new AdminJobCatalog([$definition]);
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $store = new AdminJobRunStore($registry);
        $wrapper = new RunAdminJobCommand($catalog, new AdminJobRunner($store));
        $target = new class extends Command {
            public int $executions = 0;

            public function __construct()
            {
                parent::__construct('test:job:count');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                ++$this->executions;

                return Command::SUCCESS;
            }
        };
        $dueRunner = new RunDueAdminJobsCommand($catalog, $store, new MockClock('2026-07-16T10:05:00+02:00'));
        $application = new Application;
        $application->setAutoExit(false);
        $application->addCommands([$target, $wrapper, $dueRunner]);
        $tester = new CommandTester($dueRunner);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, $target->executions);
        self::assertSame(1, (int) $this->admin->fetchOne('SELECT COUNT(*) FROM admin_job_run'));
        $scheduledFor = new DateTimeImmutable((string) $this->admin->fetchOne('SELECT scheduled_for FROM admin_job_run'));
        self::assertSame('2026-07-16T08:00:00+02:00', $scheduledFor->setTimezone(new DateTimeZone('Europe/Paris'))->format(DateTimeInterface::ATOM));
    }

    public function testDatabaseRejectsADuplicateScheduledSlotUnlessThePreviousRunWasInterrupted(): void
    {
        $connection = DriverManager::getConnection($this->admin->getParams());
        $base = [
            'job_key' => 'test-job',
            'command_name' => 'test:job:success',
            'source' => 'scheduled',
            'started_at' => '2026-07-16 08:00:00+00',
            'scheduled_for' => '2026-07-16 08:00:00+00',
        ];
        try {
            $connection->insert('admin_job_run', $base + ['id' => '00000000-0000-4000-8000-000000000011', 'status' => 'succeeded']);

            try {
                $connection->insert('admin_job_run', $base + ['id' => '00000000-0000-4000-8000-000000000012', 'status' => 'failed']);
                self::fail('The same scheduled slot must be unique for a non-interrupted run.');
            } catch (UniqueConstraintViolationException) {
                self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM admin_job_run WHERE job_key = \'test-job\''));
            }

            $connection->update('admin_job_run', ['status' => 'interrupted'], ['id' => '00000000-0000-4000-8000-000000000011']);
            $connection->insert('admin_job_run', $base + ['id' => '00000000-0000-4000-8000-000000000013', 'status' => 'succeeded']);
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM admin_job_run WHERE job_key = \'test-job\''));
        } finally {
            $connection->executeStatement('DELETE FROM admin_job_run WHERE id IN (:first, :second, :third)', [
                'first' => '00000000-0000-4000-8000-000000000011',
                'second' => '00000000-0000-4000-8000-000000000012',
                'third' => '00000000-0000-4000-8000-000000000013',
            ]);
            $connection->close();
        }
    }

    public function testConcurrentRunOfTheSameJobIsRejectedWithoutCreatingHistory(): void
    {
        $lockConnection = DriverManager::getConnection($this->admin->getParams());
        try {
            self::assertTrue((bool) $lockConnection->fetchOne(
                'SELECT pg_try_advisory_lock(hashtext(\'amateo.admin_job\'), hashtext(\'test-job\'))',
            ));
            $tester = $this->testerFor('test:job:success', Command::SUCCESS);

            self::assertSame(Command::FAILURE, $tester->execute(['job' => 'test-job']));
            self::assertSame(0, (int) $this->admin->fetchOne('SELECT COUNT(*) FROM admin_job_run'));
        } finally {
            $lockConnection->fetchOne('SELECT pg_advisory_unlock(hashtext(\'amateo.admin_job\'), hashtext(\'test-job\'))');
            $lockConnection->close();
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $this->admin = $registry->getConnection('admin');
        $this->admin->executeStatement('DELETE FROM admin_job_run');
    }

    private function testerFor(string $targetName, int|RuntimeException $result): CommandTester
    {
        $definition = new AdminJobDefinition('test-job', 'Test job', $targetName, AdminJobSchedule::daily(8));
        $catalog = new AdminJobCatalog([$definition]);
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $runner = new AdminJobRunner(new AdminJobRunStore($registry));
        $wrapper = new RunAdminJobCommand($catalog, $runner);
        $target = new class($targetName, $result) extends Command {
            public function __construct(string $name, private readonly int|RuntimeException $result)
            {
                parent::__construct($name);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                if ($this->result instanceof RuntimeException) {
                    throw $this->result;
                }

                $output->writeln('potentially sensitive output');

                return $this->result;
            }
        };

        $application = new Application;
        $application->setAutoExit(false);
        $application->addCommand($target);
        $application->addCommand($wrapper);

        return new CommandTester($wrapper);
    }

    /** @return array<string, mixed> */
    private function singleRun(): array
    {
        $run = $this->admin->fetchAssociative('SELECT * FROM admin_job_run');
        self::assertIsArray($run);

        return $run;
    }
}
