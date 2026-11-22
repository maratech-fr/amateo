<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Season;
use App\Enum\AuditAction;
use App\Service\AuditTrail;
use App\Service\SeasonDataPurger;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use App\Structure\UnusedIdentitySweeper;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Retention purge (spec transition-de-saison §3): keep a sliding window of two
 * seasons — the CURRENT one and its immediate predecessor (N-1, read-only) —
 * plus any future draft. Everything older (N-2 and beyond) is deleted, Season
 * row included. AUTOMATED since RGPD PR-3 (cron-runner, daily): the retention
 * policy must be effectively enforced, not merely available (P0-1).
 *
 * Walks clubs on the runtime (RLS) connection like PeriodReminderCommand —
 * each club under its own GUC, a failure on one never blocks the others.
 */
#[AsCommand(
    name: 'app:seasons:purge',
    description: 'Delete seasons older than N-1 (retention: current + predecessor + futures kept). Runs daily (cron-runner, RGPD).',
)]
final class PurgeSeasonsCommand extends Command
{
    private bool $hadFailure = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly SeasonResolver $seasonResolver,
        private readonly SeasonDataPurger $seasonDataPurger,
        private readonly UnusedIdentitySweeper $unusedIdentitySweeper,
        private readonly AuditTrail $auditTrail,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be purged without deleting anything.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" (rehearsal/tests).');
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Restrict to a single club id.');
        // SA4 : la grâce post-pivot protège le cron AUTOMATIQUE d'une purge sans préavis ;
        // un geste HUMAIN explicite (action support confirmée nominativement) ne doit pas
        // être un no-op silencieux pendant 30 jours chaque été (revue SA4, finding 1).
        $this->addOption('no-grace', null, InputOption::VALUE_NONE, 'Skip the 30-day post-pivot grace window (explicit support gesture).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->hadFailure = false;
        $dryRun = (bool) $input->getOption('dry-run');

        $dateOption = $input->getOption('date');
        $today = $this->resolveToday($dateOption);
        if (\is_string($dateOption) && '' !== $dateOption && !$today instanceof DateTimeImmutable) {
            $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

            return Command::FAILURE;
        }
        // Sans --date : « aujourd'hui » vient de l'HORLOGE (simulateur compris), pas du mur —
        // sinon l'action support SA4 (--no-grace, geste destructif) résoudrait la rétention
        // N-1 sur une autre saison que celle que la console affiche (revue SA4 round 2).
        $today ??= $this->clock->now();

        $clubFilter = $input->getOption('club');
        $repository = $this->entityManager->getRepository(Club::class);
        $clubs = \is_string($clubFilter) && '' !== $clubFilter
            ? array_filter([$repository->find($clubFilter)])
            : $repository->findAll();
        $this->entityManager->clear();

        $noGrace = (bool) $input->getOption('no-grace');
        $purgedTotal = 0;
        foreach ($clubs as $club) {
            try {
                $purgedTotal += $this->purgeClub($club->getId(), $today, $dryRun, $io, $noGrace);
            } catch (Throwable $e) {
                $this->hadFailure = true;
                $io->warning(\sprintf('Club %s skipped: %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d season(s) %s.', $purgedTotal, $dryRun ? 'would be purged (dry-run)' : 'purged'));

        return $this->hadFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function purgeClub(string $clubId, DateTimeImmutable $today, bool $dryRun, SymfonyStyle $io, bool $noGrace): int
    {
        $this->tenantConnectionContext->setClubId($clubId);

        $seasons = $this->seasonResolver->seasonsForClub($clubId);
        $current = SeasonResolver::currentAmong($seasons, $today);
        if (!$current instanceof Season) {
            return 0;
        }

        // Grâce post-bascule (revue PR-3) : au pivot, N-2 devient purgeable
        // d'un coup — automatisé, il serait supprimé dans l'heure sans aucun
        // préavis. On laisse 30 j après le DÉBUT de la saison courante avant de
        // purger, le temps qu'un gestionnaire consulte/exporte l'historique.
        // --no-grace (SA4) : un geste support explicite saute cette fenêtre.
        if (!$noGrace && $current->getStartDate() > $today->modify('-30 days')) {
            return 0;
        }

        $currentYear = SeasonResolver::seasonYear($current->getStartDate());
        // Keep: current, its immediate predecessor (currentYear - 1), and any
        // future (>= currentYear) draft. Purge strictly older than N-1.
        $purged = 0;
        foreach ($seasons as $season) {
            if (SeasonResolver::seasonYear($season->getStartDate()) >= $currentYear - 1) {
                continue;
            }
            $io->writeln(\sprintf('  %s purge season %s (%s, club %s)', $dryRun ? '<comment>would</comment>' : '<info>✓</info>', $season->getName(), $season->getId(), $clubId));
            if (!$dryRun) {
                $this->seasonDataPurger->purge($clubId, $season->getId(), deleteSeasonRow: true);
                $this->auditTrail->record(AuditAction::SEASON_PURGED, null, $clubId, 'Season', $season->getId());
            }
            ++$purged;
        }
        // Les fiches de personne qui n'avaient plus de rôle que dans les saisons purgées partent avec elles.
        if ($purged > 0 && !$dryRun) {
            $this->unusedIdentitySweeper->sweep($clubId);
        }

        return $purged;
    }

    /** Strict: a real calendar date, else null. No --date → null (calendar-derived per club). */
    private function resolveToday(mixed $dateOption): ?DateTimeImmutable
    {
        if (!\is_string($dateOption) || '' === $dateOption) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOption);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
