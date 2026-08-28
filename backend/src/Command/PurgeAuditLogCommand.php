<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RGPD — purge du journal d'audit (rétention 12 mois, le journal est lui-même
 * une donnée à durée limitée).
 *
 * CONNEXION ADMIN obligatoire : audit_log n'a volontairement AUCUNE policy
 * DELETE pour amateo_app (append-only tenu par la DB) — seule la porte ops peut
 * purger.
 */
#[AsCommand(
    name: 'app:audit:purge',
    description: 'Delete audit-log entries older than 12 months (admin connection — the runtime role cannot delete, append-only).',
)]
final class PurgeAuditLogCommand extends Command
{
    private const RETENTION = '-12 months';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be deleted without deleting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $threshold = $this->clock->now()->modify(self::RETENTION)->format('Y-m-d H:i:s');

        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        // GARDE BRUYANT (revue PR-4, patron RlsIsolationTest) : si la connexion
        // « admin » régresse vers le rôle applicatif restreint (app_user hérité ou
        // amateo_app après le rename), le DELETE sous RLS affecterait 0 ligne SANS
        // erreur — la rétention 12 mois serait silencieusement inappliquée à
        // jamais. On refuse de tourner plutôt que mentir.
        $currentUser = (string) $connection->fetchOne('SELECT current_user');
        if (\in_array($currentUser, ['app_user', 'amateo_app'], true)) {
            $io->error('The admin connection runs as the restricted runtime role (RLS would silently no-op the purge). Fix DATABASE_ADMIN_URL.');

            return Command::FAILURE;
        }

        if ($dryRun) {
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE occurred_at < :t', ['t' => $threshold]);
            $io->success(\sprintf('%d audit entrie(s) would be purged (dry-run).', $count));

            return Command::SUCCESS;
        }

        $deleted = (int) $connection->executeStatement('DELETE FROM audit_log WHERE occurred_at < :t', ['t' => $threshold]);
        $io->success(\sprintf('%d audit entrie(s) purged (retention 12 months).', $deleted));

        return Command::SUCCESS;
    }
}
