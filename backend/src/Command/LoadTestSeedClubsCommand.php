<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\BcclSeeder;
use App\Seed\BcclSeedProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Seed N throwaway clubs for the multi-club load-measurement harness. Each club
 * carries the full BCCL terrain state under a distinct fictitious identity
 * (`Club Charge 1..N`) so the harness can fire N concurrent generations at once.
 *
 * Dev-only on TWO layers: this class is registered only in `services_dev.yaml`
 * (absent from the prod/test container, so absent from `bin/console list`
 * there), AND a runtime guard refuses any environment other than `dev`.
 *
 * ⚠ Like `app:demo:seed`, needs the ADMIN connection (seed traverses the RLS):
 * run under `DATABASE_URL=$DATABASE_ADMIN_URL` — the seeder's superuser guard
 * fails fast otherwise.
 */
#[AsCommand(
    name: 'app:load-test:seed-clubs',
    description: 'Seed N throwaway load-test clubs (dev only). Needs the admin connection.',
)]
final class LoadTestSeedClubsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BcclSeeder $seeder,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('count', null, InputOption::VALUE_REQUIRED, 'Number of load-test clubs to seed (1..99).', 5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->kernel->getEnvironment()) {
            $io->error(\sprintf('This command is dev-only (throwaway load-test clubs) — refusing to run in "%s".', $this->kernel->getEnvironment()));

            return Command::FAILURE;
        }

        $count = (int) $input->getOption('count');
        if ($count < 1 || $count > 99) {
            $io->error(\sprintf('--count must be between 1 and 99, got %d.', $count));

            return Command::FAILURE;
        }

        for ($i = 1; $i <= $count; ++$i) {
            $club = $this->seeder->run($this->entityManager, BcclSeedProfile::loadTest($i));
            $io->writeln(\sprintf('  seeded "%s" — id %s', $club->getName(), $club->getId()));
            // Free the identity map between clubs: the seed is massive, N of them
            // in one process would balloon memory for no reason.
            $this->entityManager->clear();
        }

        $io->success(\sprintf('%d load-test club(s) ready.', $count));

        return Command::SUCCESS;
    }
}
