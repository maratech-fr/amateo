<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Service\ClubDay;
use App\Service\PeriodPlanAutoValidator;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Tâche quotidienne (catalogue `period-plan-auto-validation`, 00:15 Europe/Paris) : valide les
 * plannings de période dont le 7ᵉ jour après le début est atteint, club par club, chacun à SON
 * jour ({@see ClubDay::todayFor} : fuseau, horloge simulée d'une démo). Le travail vit dans
 * {@see PeriodPlanAutoValidator} ; ici, le parcours des clubs (patron `PeriodReminderCommand` :
 * GUC posé par club, relâché en `finally`, un club en échec n'arrête jamais les suivants mais fait
 * sortir le passage en échec pour le moniteur cron).
 *
 * `--date` force le jour de TOUS les clubs ; une date postérieure au jour RÉEL n'est acceptée qu'en
 * `--dry-run` (une répétition peut regarder l'avenir, jamais l'écrire).
 */
#[AsCommand(
    name: 'app:period-plans:auto-validate',
    description: 'Validate a period plan 7 days after its start date: one finished version is pointed, the loaded one if several (siblings kept); never a season plan, never a plan validated once.',
)]
final class PeriodPlanAutoValidateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly PeriodPlanAutoValidator $validator,
        private readonly ClubDay $clubDay,
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $realClock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be validated without writing anything.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" for every club (rehearsal/tests); a future date requires --dry-run.');
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Only sweep this club (id).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $dateOption = $input->getOption('date');
        $forcedToday = null;
        if (\is_string($dateOption) && '' !== $dateOption) {
            $forcedToday = $this->parseDate($dateOption);
            if (!$forcedToday instanceof DateTimeImmutable) {
                $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

                return Command::FAILURE;
            }
            $realToday = DateTimeImmutable::createFromInterface($this->realClock->now())->format('Y-m-d');
            if (!$dryRun && $forcedToday->format('Y-m-d') > $realToday) {
                $io->error('--date cannot be in the future unless --dry-run.');

                return Command::FAILURE;
            }
        }

        $clubOption = $input->getOption('club');
        $repository = $this->entityManager->getRepository(Club::class);
        if (\is_string($clubOption) && '' !== $clubOption) {
            $club = $repository->find($clubOption);
            if (!$club instanceof Club) {
                $io->error(\sprintf('Unknown club %s.', $clubOption));

                return Command::FAILURE;
            }
            $clubs = [$club];
        } else {
            $clubs = $repository->findAll();
        }
        // Détachés après clear() : seuls les getters (id, fuseau, horloge simulée) sont lus.
        $this->entityManager->clear();

        $validated = 0;
        $startedWithoutPlan = 0;
        $skippedInFlight = 0;
        $failed = false;
        foreach ($clubs as $club) {
            try {
                $this->tenantConnectionContext->setClubId($club->getId());
                $today = $forcedToday ?? $this->clubDay->todayFor($club);
                $report = $this->validator->sweepClub($club, $today, $dryRun);
                foreach ($report->validated as $line) {
                    $io->writeln(\sprintf('  %s period plan %s (club %s) → version %s, %d other version(s) kept', $dryRun ? 'would validate' : 'validated', $line['planId'], $club->getId(), $line['scheduleId'], $line['keptVersions']));
                }
                $validated += \count($report->validated);
                $startedWithoutPlan += \count($report->startedWithoutPlan);
                $skippedInFlight += \count($report->skippedInFlight);
            } catch (Throwable $e) {
                // Un club en échec n'empêche jamais les suivants ; le passage sort en échec.
                $failed = true;
                $io->warning(\sprintf('Club %s skipped: %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf(
            '%d period plan(s) validated, %d started without a finished version, %d skipped (generation in flight).%s',
            $validated,
            $startedWithoutPlan,
            $skippedInFlight,
            $dryRun ? ' Dry run: nothing written.' : '',
        ));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** Une date calendaire RÉELLE (refuse un débordement comme 2026-02-30), sinon null. */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
