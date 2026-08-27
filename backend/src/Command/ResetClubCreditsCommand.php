<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SA4 / P1-3 — action support : remet à zéro le pool de crédits de SORTIE d'un club
 * (offre Découverte). Reset superadmin seulement (le pool est non rechargeable par
 * l'utilisateur — spec docs/archive/bridage-freemium-decouverte §5). Connexion PAR DÉFAUT (même
 * patron que MarkSeasonPaid, et que son action sœur app:clubs:set-plan) : `club` n'a
 * pas de club_id (pas de policy RLS) — l'UPDATE ciblé par id passe, SA4 gate l'accès.
 */
#[AsCommand(
    name: 'app:clubs:reset-credits',
    description: 'Reset a club\'s output-credit pool to zero (Découverte). Support action.',
)]
final class ResetClubCreditsCommand extends Command
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (required).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clubId = $input->getOption('club');
        if (!\is_string($clubId) || '' === $clubId) {
            $io->error('--club <id> is required.');

            return Command::FAILURE;
        }

        $updated = $this->connection()->executeStatement(
            'UPDATE club SET output_credits_used = 0 WHERE id = :id',
            ['id' => $clubId],
        );
        if (0 === $updated) {
            $io->error(\sprintf('Club %s not found.', $clubId));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Output-credit pool reset to 0 for club %s.', $clubId));

        return Command::SUCCESS;
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection();
        \assert($connection instanceof Connection);

        return $connection;
    }
}
