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
 * Seeds the national school-holiday reference from a versioned JSON — no network
 * at runtime (accueil-cockpit-temporel.md §4bis). Idempotent: upsert by the
 * natural key (zone, holidayType, schoolYear). Run once a year when the official
 * calendar for the next year is published. The seeding logic lives in
 * HolidaySeeder (shared with the data fixtures).
 */
#[AsCommand(
    name: 'app:school-holidays:seed',
    description: 'Seed/refresh school-holiday reference data from data/school-holidays.fr-metropole.json (idempotent).',
)]
final class SeedSchoolHolidaysCommand extends Command
{
    public function __construct(private readonly HolidaySeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the JSON source', $this->seeder->defaultSchoolFile());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->seeder->seedSchool((string) $input->getOption('file'));
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Fail LOUD on a malformed row — else a broken JSON edit seeds a calendar
        // silently missing a holiday while the seed stays green.
        if ($result['skipped'] > 0) {
            $io->warning(\sprintf('%d malformed row(s) skipped.', $result['skipped']));

            return Command::FAILURE;
        }

        $io->success(\sprintf('School holidays seeded: %d created, %d updated.', $result['created'], $result['updated']));

        return Command::SUCCESS;
    }
}
