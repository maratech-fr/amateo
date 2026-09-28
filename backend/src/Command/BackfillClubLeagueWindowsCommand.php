<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Service\ClubLeagueWindowSeeder;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * P4-272 ① — pose la COPIE club de l'enveloppe ligue pour les clubs EXISTANTS :
 * la saison en cours (ACTIVE) et la saison suivante si elle existe déjà (DRAFT),
 * recopie de la ligue effective. Le passé (saisons ARCHIVED) reste consultatif —
 * jamais recopié. Comportement jour 1 identique (mêmes bornes/jours que le
 * catalogue).
 *
 * Idempotente : le seeder ne recopie que si la copie de la saison est vide (la
 * contrainte d'unicité serait le filet de toute façon). Un club fautif est isolé
 * et n'arrête pas les autres.
 *
 * ⚠ Chaque club est scopé par `TenantConnectionContext::setClubId` (GUC
 * `app.club_id`) pour que la RLS laisse écrire ses lignes, relâché en `finally`.
 */
#[AsCommand(
    name: 'app:club-league-windows:backfill',
    description: 'Seed the per-club league-window copy for existing clubs (current + next season).',
)]
final class BackfillClubLeagueWindowsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubLeagueWindowSeeder $clubLeagueWindowSeeder,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Limit to one club id (default: all clubs).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $clubFilter = $input->getOption('club');
        $repository = $this->entityManager->getRepository(Club::class);
        $clubs = \is_string($clubFilter) && '' !== $clubFilter
            ? array_filter([$repository->find($clubFilter)])
            : $repository->findAll();

        if ([] === $clubs) {
            $io->error(\is_string($clubFilter) ? \sprintf('Club %s not found.', $clubFilter) : 'No club found.');

            return Command::FAILURE;
        }

        $this->entityManager->clear();

        $failure = false;
        $windowsTotal = 0;
        foreach ($clubs as $club) {
            try {
                $windowsTotal += $this->backfillClub($club->getId(), $club->getLeague());
            } catch (Throwable $e) {
                $failure = true;
                $io->warning(\sprintf('Club %s skipped: %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d league window(s) seeded across %d club(s).', $windowsTotal, \count($clubs)));

        return $failure ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return int the number of league windows seeded for this club */
    private function backfillClub(string $clubId, ?string $league): int
    {
        $this->tenantConnectionContext->setClubId($clubId);

        // Saison en cours + suivante seulement (ACTIVE/DRAFT). Une ARCHIVED reste
        // consultative — le passé n'est jamais recopié.
        $seasons = array_filter(
            $this->entityManager->getRepository(Season::class)->findBy(['clubId' => $clubId]),
            static fn (Season $s): bool => \in_array($s->getStatus(), [SeasonStatus::ACTIVE, SeasonStatus::DRAFT], true),
        );

        $count = 0;
        foreach ($seasons as $season) {
            $count += $this->clubLeagueWindowSeeder->seedForSeason($clubId, $season->getId(), $league);
        }

        $this->entityManager->flush();

        return $count;
    }
}
