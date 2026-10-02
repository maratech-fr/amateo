<?php

declare(strict_types=1);

namespace App\Command;

use App\Clock\ClubClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * P4-16 / P2-4 — pose (ou relâche) l'« aujourd'hui » simulé d'un club de démo.
 *
 * Toute l'application vit alors à cette date pour CE club : le serveur via
 * {@see ClubClock} (SeasonResolver, OverlayManager, guards…),
 * le front via `/api/me` → `clock.ts`. Rejouer « à trois semaines des vacances »
 * en plein été, en rendez-vous.
 *
 * Même idiome que app:clubs:mark-next-season-paid : connexion PAR DÉFAUT (`club` n'a
 * pas de colonne club_id, donc pas de policy RLS — l'UPDATE ciblé par id passe),
 * action support pilotée par --club (SA4).
 */
#[AsCommand(
    name: 'app:demo:clock',
    description: 'Set (or clear with --clear) the simulated "today" of a demo club. Support action.',
)]
final class DemoClockCommand extends Command
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (required).');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Simulated today, YYYY-MM-DD.');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Release the simulated clock (back to real time).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clubId = $input->getOption('club');
        if (!\is_string($clubId) || '' === $clubId) {
            $io->error('--club <id> is required.');

            return Command::FAILURE;
        }

        $clear = (bool) $input->getOption('clear');
        $rawDate = $input->getOption('date');
        if ($clear === \is_string($rawDate)) {
            $io->error('Exactly one of --date YYYY-MM-DD or --clear is required.');

            return Command::FAILURE;
        }

        $date = null;
        if (\is_string($rawDate)) {
            // La FORME ne suffit pas (même règle que clock.ts côté front) : 2026-02-31
            // « parse » en reportant au 3 mars — la date doit se relire à l'identique.
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
            if (false === $parsed || $parsed->format('Y-m-d') !== $rawDate) {
                $io->error(\sprintf('"%s" is not a real YYYY-MM-DD date.', $rawDate));

                return Command::FAILURE;
            }
            $date = $rawDate;
        }

        // P2-4 — RÉSERVÉ aux clubs de démonstration : décaler l'horloge d'un vrai
        // club mentirait à son gestionnaire (radar, bascule de saison, guards).
        $updated = $this->connection()->executeStatement(
            'UPDATE club SET simulated_today = :date WHERE id = :id AND is_demo = TRUE',
            ['date' => $date, 'id' => $clubId],
        );
        if (0 === $updated) {
            $io->error(\sprintf('Club %s not found — or not a DEMO club (the simulated clock is demo-only).', $clubId));

            return Command::FAILURE;
        }

        $io->success(null === $date
            ? \sprintf('Club %s is back on the real clock.', $clubId)
            : \sprintf('Club %s now lives on %s (server AND frontend).', $clubId, $date));

        return Command::SUCCESS;
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection();
        \assert($connection instanceof Connection);

        return $connection;
    }
}
