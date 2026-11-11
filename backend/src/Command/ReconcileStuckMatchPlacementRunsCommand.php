<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReconcileMode;
use App\Service\StuckPlacementRunReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rattrapage À LA MAIN des runs de placement figés, worker VIVANT (mode commande du
 * {@see StuckPlacementRunReconciler}) — le même rattrapage joue de lui-même à chaque démarrage du
 * worker. Rend toujours 0 : un club en erreur ou une file illisible se DISENT, ils n'arrêtent pas
 * la passe. CLI seulement (aucune entrée au catalogue des tâches d'administration).
 */
#[AsCommand(
    name: 'app:match-placement:reconcile-stuck',
    description: 'Fail automatic match placement runs left in flight by a dead worker (lock gone, or message missing from the queue).',
    help: 'A RUNNING run is only failed when its lock key is gone (budget elapsed) and it started more than 5 minutes ago. '
        . 'A PENDING run is failed when its message is no longer in the Messenger queue and it was created longer ago than the pending grace. '
        . 'Finished runs and placement locks are never touched.',
)]
final class ReconcileStuckMatchPlacementRunsCommand extends Command
{
    private const int DEFAULT_PENDING_GRACE_MINUTES = 5;

    public function __construct(
        private readonly StuckPlacementRunReconciler $reconciler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List stuck placement runs without changing them.',
            )
            ->addOption(
                'pending-grace',
                null,
                InputOption::VALUE_REQUIRED,
                'Minutes a PENDING run may wait for its queued message before it is considered lost.',
                (string) self::DEFAULT_PENDING_GRACE_MINUTES,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $graceMinutes = max(0, (int) $input->getOption('pending-grace'));

        $result = $this->reconciler->reconcile(ReconcileMode::Cli, $dryRun, $graceMinutes * 60);

        foreach ($result->runningRunIds as $runId) {
            $io->writeln(\sprintf('  %s running placement run %s', $dryRun ? 'would fail' : 'failed', $runId));
        }
        foreach ($result->pendingRunIds as $runId) {
            $io->writeln(\sprintf('  %s pending placement run %s', $dryRun ? 'would fail' : 'failed', $runId));
        }

        if (!$result->queueInspected) {
            $io->warning('queue not inspected: pending runs skipped');
        }
        if ([] !== $result->skippedClubIds) {
            $io->warning(\sprintf('%d club(s) skipped after an error — see the log.', \count($result->skippedClubIds)));
        }

        $io->success(\sprintf(
            '%d stuck placement run(s) %s.',
            $result->count(),
            $dryRun ? 'detected (dry-run)' : 'marked FAILED',
        ));

        return Command::SUCCESS;
    }
}
