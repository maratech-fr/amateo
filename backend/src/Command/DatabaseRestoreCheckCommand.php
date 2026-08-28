<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * « Un backup jamais restauré n'existe pas » : restaure le dump le plus récent
 * (ou --file) dans une base JETABLE, vérifie qu'elle est lisible (nombre de
 * tables + un SELECT métier), puis la détruit. À lancer après tout changement
 * d'infra et périodiquement (runbook docs/ops/backup-restore.md).
 */
#[AsCommand(
    name: 'app:db:restore-check',
    description: 'Restore the latest dump into a throwaway database and sanity-check it (proof the backup exists).',
)]
final class DatabaseRestoreCheckCommand extends Command
{
    private const MIN_TABLES = 20;

    public function __construct(
        // Mêmes credentials que le dump (connexion admin Doctrine, superuser) : la
        // base jetable se crée/détruit là où le backup a été pris, pas là où un
        // POSTGRES_* d'env pointerait (désync env = CI qui vérifie la mauvaise base).
        #[Autowire(service: 'doctrine.dbal.admin_connection')]
        private readonly Connection $adminConnection,
        #[Autowire('%kernel.project_dir%/var/backups')]
        private readonly string $defaultBackupDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Dump file to check (default: latest in the backup dir).');
        $this->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Backup directory (default: var/backups).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $this->resolveDumpFile($input);
        if (null === $file) {
            $io->error('No dump file found — run app:db:backup first.');

            return Command::FAILURE;
        }

        // Nom jetable aléatoire : deux checks concurrents ne se marchent pas dessus.
        // CREATE/DROP via psql (base de maintenance 'postgres'), PAS via Doctrine :
        // CREATE DATABASE refuse de tourner dans une transaction, et la connexion
        // Doctrine peut en porter une (wrapper transactionnel des tests).
        $database = 'amateo_restore_' . bin2hex(random_bytes(4));
        $this->maintenance(\sprintf('CREATE DATABASE %s', $database));
        $dropFailed = false;

        try {
            $db = $this->connectionParams();
            $restore = new Process(
                ['pg_restore', '--no-owner', '--no-privileges', '--dbname', $database, '--host', $db['host'], '--port', $db['port'], '--username', $db['user'], $file],
                env: ['PGPASSWORD' => $db['password']],
                timeout: 900,
            );
            $restore->run();
            if (!$restore->isSuccessful()) {
                $io->error('pg_restore failed: ' . trim($restore->getErrorOutput()));

                return Command::FAILURE;
            }

            // Sanity via psql (la connexion Doctrine pointe la base nominale, pas la jetable).
            $tables = (int) $this->scalar($database, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \'public\'');
            $clubs = (int) $this->scalar($database, 'SELECT COUNT(*) FROM club');
            if ($tables < self::MIN_TABLES) {
                $io->error(\sprintf('Restored database has only %d tables (expected >= %d) — dump likely truncated.', $tables, self::MIN_TABLES));

                return Command::FAILURE;
            }

            $io->writeln(\sprintf('Restore check: %s → %d tables, %d club(s).', basename($file), $tables, $clubs));
        } finally {
            // Un DROP qui échoue (connexion résiduelle après un pg_restore tué) ne doit
            // JAMAIS masquer l'erreur d'origine du bloc try — warning + flag : sur le
            // chemin SUCCÈS, la fuite d'une copie complète de prod derrière un exit 0
            // serait invisible du monitoring (round 2, finding 4) → FAILURE plus bas.
            try {
                $this->maintenance(\sprintf('DROP DATABASE IF EXISTS %s', $database));
            } catch (Throwable $e) {
                $dropFailed = true;
                $io->warning(\sprintf('Could not drop throwaway database %s (drop it manually): %s', $database, $e->getMessage()));
            }
        }

        if ($dropFailed) {
            $io->error(\sprintf('Restore verified BUT the throwaway database %s leaked a full data copy — drop it, then re-run.', $database));

            return Command::FAILURE;
        }

        $io->success('The backup is real.');

        return Command::SUCCESS;
    }

    /** Ordre administratif hors transaction, sur la base de maintenance 'postgres'. */
    private function maintenance(string $sql): void
    {
        $db = $this->connectionParams();
        $psql = new Process(
            ['psql', '--host', $db['host'], '--port', $db['port'], '--username', $db['user'], '--dbname', 'postgres', '--command', $sql],
            env: ['PGPASSWORD' => $db['password']],
            timeout: 60,
        );
        $psql->mustRun();
    }

    private function resolveDumpFile(InputInterface $input): ?string
    {
        $fileOption = $input->getOption('file');
        if (\is_string($fileOption) && '' !== $fileOption) {
            return is_file($fileOption) ? $fileOption : null;
        }

        $dirOption = $input->getOption('dir');
        $dir = \is_string($dirOption) && '' !== $dirOption ? $dirOption : $this->defaultBackupDir;
        $files = glob(rtrim($dir, '/') . '/amateo-*.dump') ?: [];
        sort($files);

        return [] === $files ? null : end($files);
    }

    private function scalar(string $database, string $sql): string
    {
        $db = $this->connectionParams();
        $psql = new Process(
            ['psql', '--tuples-only', '--no-align', '--host', $db['host'], '--port', $db['port'], '--username', $db['user'], '--dbname', $database, '--command', $sql],
            env: ['PGPASSWORD' => $db['password']],
            timeout: 60,
        );
        $psql->mustRun();

        return trim($psql->getOutput());
    }

    /**
     * Paramètres de la connexion admin Doctrine — même source de vérité que le dump
     * (DatabaseBackupCommand). AUCUN fallback env.
     *
     * @return array{host: string, port: string, user: string, password: string}
     */
    private function connectionParams(): array
    {
        $params = $this->adminConnection->getParams();
        $host = $params['host'] ?? null;
        $user = $params['user'] ?? null;
        if (!\is_string($host) || !\is_string($user)) {
            throw new RuntimeException('Admin connection is missing host/user — cannot reach the database server.');
        }
        $password = $params['password'] ?? '';
        $port = $params['port'] ?? 5432;

        return [
            'host' => $host,
            'port' => \is_int($port) || \is_string($port) ? (string) $port : '5432',
            'user' => $user,
            'password' => \is_string($password) ? $password : '',
        ];
    }
}
