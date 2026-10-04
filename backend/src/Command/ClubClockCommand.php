<?php

declare(strict_types=1);

namespace App\Command;

use App\Clock\ClubClock;
use App\Entity\Season;
use App\Service\ClubMailboxPurgerInterface;
use App\Service\SeasonResolver;
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
 * `--club` accepte un id (UUID) OU un code FFBB. RÉSERVÉ aux clubs de DÉMONSTRATION
 * (`is_demo = TRUE`) : décaler l'horloge d'un vrai club donnerait la main sur des actions
 * qui ne le concernent pas (décision fondateur 2026-10-02) — un club réel est REFUSÉ, franc.
 *
 * Idiome support (connexion ADMIN, cross-tenant — même connexion que {@see ClubMailboxPurgerInterface},
 * pour que le vidage de boîte au `--clear` voie bien les lignes).
 */
#[AsCommand(
    name: 'app:club:clock',
    description: 'Set (or clear with --clear) the simulated "today" of a club. Support action.',
)]
final class ClubClockCommand extends Command
{
    private const string UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ClubMailboxPurgerInterface $mailboxPurger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (UUID) or FFBB club code (required).');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Simulated today, YYYY-MM-DD.');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Release the simulated clock (back to real time).');
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

        // RÉSERVÉ aux clubs de démonstration : décaler l'horloge d'un vrai club donnerait la
        // main sur des actions qui ne le concernent pas (radar, bascule de saison, e-mails).
        if (!(bool) $club['is_demo']) {
            $io->error(\sprintf('Club "%s" is NOT a demo club — the simulated clock is demo-only.', $clubRef));

            return Command::FAILURE;
        }

        // BCK-34 — une date simulée doit rester dans la fenêtre des saisons du club
        // (début de la saison en cours → fin de la saison suivante). Hors bornes → échec.
        if (null !== $date) {
            $bounds = SeasonResolver::simulatedClockBoundsAmong(
                $this->seasonsForClub((string) $club['id']),
                new DateTimeImmutable('now'),
            );
            $parsed = new DateTimeImmutable($date);
            if (null !== $bounds && ($parsed < $bounds[0] || $parsed > $bounds[1])) {
                $io->error(\sprintf(
                    'La date simulée doit être comprise entre le %s et le %s.',
                    $bounds[0]->format('Y-m-d'),
                    $bounds[1]->format('Y-m-d'),
                ));

                return Command::FAILURE;
            }
        }

        $this->connection()->executeStatement(
            'UPDATE club SET simulated_today = :date WHERE id = :id AND is_demo = TRUE',
            ['date' => $date, 'id' => $club['id']],
        );

        // Relâcher l'horloge VIDE la boîte aux lettres : hors horloge le club redevient un club
        // qui envoie pour de vrai, les e-mails boxés n'ont plus de raison d'être (même maison que
        // la console — décision fondateur 2026-10-02). Poser une date ne touche jamais la boîte.
        if ($clear) {
            $this->mailboxPurger->purge((string) $club['id']);
        }

        $io->success(null === $date
            ? \sprintf('Club %s is back on the real clock.', $club['id'])
            : \sprintf('Club %s now lives on %s (server AND frontend).', $club['id'], $date));

        return Command::SUCCESS;
    }

    /**
     * Les saisons du club, hydratées en entités TRANSIENTES depuis la connexion ADMIN
     * (cross-tenant) pour nourrir {@see SeasonResolver::simulatedClockBoundsAmong}.
     *
     * @return list<Season>
     */
    private function seasonsForClub(string $clubId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT start_date, end_date FROM season WHERE club_id = :id ORDER BY start_date ASC',
            ['id' => $clubId],
        );

        return array_map(
            static fn (array $row): Season => new Season()
                ->setClubId($clubId)
                ->setStartDate(new DateTimeImmutable((string) $row['start_date']))
                ->setEndDate(new DateTimeImmutable((string) $row['end_date'])),
            $rows,
        );
    }

    /**
     * Connexion ADMIN (amateo_owner) : action support cross-tenant, comme la console. Elle vise
     * n'importe quel club sans dépendre d'un GUC tenant — et c'est la même connexion que
     * {@see ClubMailboxPurgerInterface}, pour que le vidage de boîte au `--clear` voie bien les lignes.
     */
    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
