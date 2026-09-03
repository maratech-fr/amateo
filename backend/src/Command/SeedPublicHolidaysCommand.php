<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\HolidaySeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Seeds the national public-holiday (jours fériés) reference from a versioned JSON —
 * OFFLINE, unlike app:public-holidays:import which fetches calendrier.api.gouv.fr and
 * so can't run in fixtures/CI. Métropole fériés → zone NATIONAL (apply to all clubs).
 * Idempotent: upsert by the natural key (zone, date). Display-only — never feeds the
 * solver. The seeding logic lives in HolidaySeeder (shared with the data fixtures).
 */
#[AsCommand(
    name: 'app:public-holidays:seed',
    description: 'Seed/refresh national public-holiday reference from data/public-holidays.fr-national.json (offline, idempotent).',
)]
final class SeedPublicHolidaysCommand extends Command
{
    public function __construct(private readonly HolidaySeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the JSON source', $this->seeder->defaultPublicFile());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->seeder->seedPublic((string) $input->getOption('file'));
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Fail LOUD on a malformed row (typo'd date/empty label) — else a broken JSON
        // edit seeds a calendar silently missing a férié while the seed stays green.
        if ($result['skipped'] > 0) {
            $io->warning(\sprintf('%d malformed row(s) skipped.', $result['skipped']));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Public holidays seeded: %d created, %d updated.', $result['created'], $result['updated']));

        return Command::SUCCESS;
    }
}
