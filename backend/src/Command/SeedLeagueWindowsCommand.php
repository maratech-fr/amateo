<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\LeagueMatchWindow;
use App\Repository\LeagueMatchWindowRepository;
use App\Service\LeagueWindowCatalogFile;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the federation league-match-window catalog from a versioned JSON — no
 * network at runtime (spec gestion-matchs §6bis). Idempotent: upsert by the
 * natural key (league, category, level, gender, dayOfWeek, kickoffMin). The
 * AURA seed is the default base inherited by every club.
 */
#[AsCommand(
    name: 'app:league-windows:seed',
    description: 'Seed/refresh the league-match-window catalog from data/league-match-windows.aura.json (idempotent).',
)]
final class SeedLeagueWindowsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeagueMatchWindowRepository $repository,
        private readonly LeagueWindowCatalogFile $catalogFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the JSON source', \dirname(__DIR__, 2) . '/data/league-match-windows.aura.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getOption('file');

        // Read/validate through the shared, dependency-free reader (same house as
        // the catalog-load data migration). A malformed file throws → nothing written.
        try {
            $rows = $this->catalogFile->read($file);
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $parsed = [];
        foreach ($rows as $row) {
            $parsed[] = [
                'league' => $row['league'],
                'category' => $row['category'],
                'level' => $row['level'],
                'gender' => $row['gender'],
                'dayOfWeek' => $row['dayOfWeek'],
                'min' => $this->time($row['kickoffMin']),
                'max' => $this->time($row['kickoffMax']),
            ];
        }

        $leagues = array_values(array_unique(array_column($parsed, 'league')));
        $created = 0;
        $updated = 0;
        // Track which rows the file still declares, per seeded league, so stale
        // rows (a window whose keyed field was edited) are pruned — the file is
        // the source of truth for the leagues it covers.
        $kept = [];
        foreach ($parsed as $row) {
            $entity = $this->repository->findOneByNaturalKey($row['league'], $row['category'], $row['level'], $row['gender'], $row['dayOfWeek'], $row['min']);
            if (!$entity instanceof LeagueMatchWindow) {
                $entity = new LeagueMatchWindow;
                $entity->setLeague($row['league']);
                $entity->setCategory($row['category']);
                $entity->setLevel($row['level']);
                $entity->setGender($row['gender']);
                $entity->setDayOfWeek($row['dayOfWeek']);
                $entity->setKickoffMin($row['min']);
                $this->entityManager->persist($entity);
                ++$created;
            } else {
                ++$updated;
            }
            $entity->setKickoffMax($row['max']);
            $kept[$entity->getId()] = true;
        }

        // Prune stale rows of the seeded leagues (edited/removed windows).
        $pruned = 0;
        foreach ($this->repository->findBy(['league' => $leagues]) as $existing) {
            if (!isset($kept[$existing->getId()])) {
                $this->entityManager->remove($existing);
                ++$pruned;
            }
        }

        $this->entityManager->flush();

        $io->success(\sprintf('League match windows seeded: %d created, %d updated, %d pruned.', $created, $updated, $pruned));

        return Command::SUCCESS;
    }

    /** The `HH:MM` string is already validated by {@see LeagueWindowCatalogFile}. */
    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!H:i', $value);
        if (false === $time) {
            throw new RuntimeException(\sprintf('Unparseable time slipped past validation: %s', $value));
        }

        return $time;
    }
}
