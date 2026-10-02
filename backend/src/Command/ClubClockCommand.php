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
 * Pose (ou relâche) l'« aujourd'hui » simulé d'un club.
 *
 * L'horloge simulée est une capacité GÉNÉRIQUE par club (décision fondateur 2026-10-02) :
 * toute l'application vit alors à cette date pour CE club — le serveur via {@see ClubClock}
 * (SeasonResolver, OverlayManager, guards…), le front via `/api/me` → `clock.ts`. Rejouer
 * « à trois semaines des vacances » en plein été, en rendez-vous.
 *
 * `--club` accepte un id (UUID) OU un code FFBB. Garde-fou : poser l'horloge sur un club
 * RÉEL (non démo) le coupe de tout e-mail réel (ils partent en boîte aux lettres) — la
 * commande l'EXIGE de confirmer avec `--yes`. Un club démo n'a jamais besoin de `--yes`.
 *
 * Idiome support (connexion PAR DÉFAUT : `club` n'a pas de colonne club_id, donc pas de
 * policy RLS — l'UPDATE ciblé par id passe). Alias déprécié `app:demo:clock` conservé le
 * temps que les habitudes migrent.
 */
#[AsCommand(
    name: 'app:club:clock',
    description: 'Set (or clear with --clear) the simulated "today" of a club. Support action.',
    aliases: ['app:demo:clock'],
)]
final class ClubClockCommand extends Command
{
    private const string UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (UUID) or FFBB club code (required).');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Simulated today, YYYY-MM-DD.');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Release the simulated clock (back to real time).');
        $this->addOption('yes', null, InputOption::VALUE_NONE, 'Confirm setting the clock on a real (non-demo) club — stops its real e-mails.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clubRef = $input->getOption('club');
        if (!\is_string($clubRef) || '' === $clubRef) {
            $io->error('--club <id|FFBB code> is required.');

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

        // Résolution par id (UUID) OU par code FFBB — le support tient l'un ou l'autre en main.
        $column = 1 === preg_match(self::UUID_PATTERN, $clubRef) ? 'id' : 'ffbb_club_code';
        $club = $this->connection()->fetchAssociative(
            \sprintf('SELECT id, is_demo FROM club WHERE %s = :ref', $column),
            ['ref' => $clubRef],
        );
        if (false === $club) {
            $io->error(\sprintf('Club "%s" not found.', $clubRef));

            return Command::FAILURE;
        }

        // Club RÉEL : poser l'horloge le coupe de ses e-mails réels — exiger --yes.
        if (!(bool) $club['is_demo'] && !(bool) $input->getOption('yes')) {
            $io->error('This is a REAL club — setting its clock stops its real e-mails. Pass --yes to confirm.');

            return Command::FAILURE;
        }

        $this->connection()->executeStatement(
            'UPDATE club SET simulated_today = :date WHERE id = :id',
            ['date' => $date, 'id' => $club['id']],
        );

        $io->success(null === $date
            ? \sprintf('Club %s is back on the real clock.', $club['id'])
            : \sprintf('Club %s now lives on %s (server AND frontend).', $club['id'], $date));

        return Command::SUCCESS;
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection();
        \assert($connection instanceof Connection);

        return $connection;
    }
}
