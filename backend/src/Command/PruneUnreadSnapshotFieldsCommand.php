<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\Plans\PlanStates;
use App\Service\ClubGenerationLock;
use App\Service\ScheduleConstraintBuilder;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Le contrat moteur allégé (ENG-60) ne porte plus les champs que le solveur ne lit pas, et
 * l'empreinte de structure trie désormais les contraintes. L'empreinte de toute version antérieure
 * diffère donc de l'empreinte courante : tout planning paraîtrait périmé sans que rien n'ait changé.
 *
 * Lancée seule par la mise en prod (`scripts/remote-deploy.sh`), juste après « Deploy … OK ». Pour
 * chaque version TERMINÉE (`COMPLETED`, `FAILED`), les clés de {@see self::REMOVED_KEYS} sont retirées
 * du snapshot et le `snapshot_hash` est recalculé DEPUIS ce snapshot, jamais recopié de la structure
 * courante : une version vraiment périmée le reste. Écriture seulement si le snapshot ou l'empreinte
 * change, ce qui rend la commande idempotente. Une version en cours (`PENDING`, `GENERATING`) ou un
 * brouillon n'est jamais touché, verrou posé ou non : le worker peut encore l'écrire, et une écriture
 * rafraîchirait `updatedAt`, ce qui retarderait la fermeture d'une génération orpheline.
 *
 * Mêmes règles d'obstacle que `NormalizeTeamCoachSnapshotsCommand` : le verrou de génération est relu
 * avant chaque version et le club est sauté ; une version en échec arrête tout ; le flush est par
 * version. `--max-seconds` arrête la commande ENTRE deux versions une fois la durée écoulée (horloge
 * monotone : une durée, pas un « aujourd'hui » métier) ; rejouer termine. Le pool `cache.schedule` est
 * purgé avant et après, jamais en essai à blanc.
 */
#[AsCommand(
    name: 'app:schedule:prune-unread-snapshot-fields',
    description: 'Remove the fields the solver never reads from schedule snapshots and recompute their hash. Run once after deployment.',
)]
final class PruneUnreadSnapshotFieldsCommand extends Command
{
    /**
     * Les champs que l'envoi au moteur portait avant l'allègement et que le solveur ne lit pas.
     * `seasonId` n'y figure pas, à dessein.
     *
     * @var array{venues: list<string>, teams: list<string>, coaches: list<string>, constraints: list<string>}
     */
    public const array REMOVED_KEYS = [
        'venues' => ['isExternal', 'color', 'latitude', 'longitude', 'source', 'externalRef', 'parentVenueId'],
        'teams' => ['ageMax', 'gender', 'level', 'parentTeamId', 'sportCategoryId'],
        'coaches' => ['parentCoachId'],
        'constraints' => ['sortOrder'],
    ];

    private const string OUTCOME_DONE = 'done';
    private const string OUTCOME_SKIPPED = 'skipped';
    private const string OUTCOME_FAILED = 'failed';
    private const string OUTCOME_STOPPED = 'stopped';

    /** @var list<ScheduleStatus> */
    private const array FINISHED_STATUSES = [ScheduleStatus::COMPLETED, ScheduleStatus::FAILED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly ClubGenerationLock $generationLock,
        #[Autowire(service: 'cache.schedule')]
        private readonly TagAwareAdapterInterface $scheduleCachePool,
        private readonly PlanStates $planStates,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count affected schedules without writing or purging anything.')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Stop by itself between two schedules once N seconds have elapsed; rerun to finish.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = hrtime(true);
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $maxSecondsOption = $input->getOption('max-seconds');
        $maxSeconds = null;
        if (null !== $maxSecondsOption) {
            if (!\is_string($maxSecondsOption) || 1 !== preg_match('/^\d+$/', $maxSecondsOption)) {
                $io->error('--max-seconds expects a whole number of seconds (0 or more).');

                return Command::INVALID;
            }
            $maxSeconds = (int) $maxSecondsOption;
        }
        $deadlineReached = static fn (): bool => null !== $maxSeconds && (hrtime(true) - $startedAt) / 1e9 >= $maxSeconds;

        $clubIds = array_map(
            static fn (Club $club): string => $club->getId(),
            $this->entityManager->getRepository(Club::class)->findAll(),
        );
        $this->entityManager->clear();

        if (!$dryRun) {
            $this->purgeCache($clubIds);
        }

        $complete = true;
        $pruned = 0;
        try {
            foreach ($clubIds as $clubId) {
                $outcome = $this->pruneClub($clubId, $dryRun, $deadlineReached, $io, $pruned);
                $complete = $complete && self::OUTCOME_DONE === $outcome;
                if (self::OUTCOME_STOPPED === $outcome) {
                    $io->warning(\sprintf('Stopped after %d s: time limit reached — rerun to finish.', intdiv(hrtime(true) - $startedAt, 1_000_000_000)));
                    break;
                }
                if (self::OUTCOME_FAILED === $outcome) {
                    break;
                }
            }
        } finally {
            if (!$dryRun) {
                $this->purgeCache($clubIds);
            }
        }

        $summary = \sprintf($dryRun ? '%d schedule(s) would be pruned (dry-run).' : '%d schedule(s) pruned.', $pruned);
        if ($complete) {
            $io->success($summary);

            return Command::SUCCESS;
        }
        $io->warning($summary);

        return Command::FAILURE;
    }

    /**
     * @param callable(): bool $deadlineReached
     *
     * @return self::OUTCOME_*
     */
    private function pruneClub(string $clubId, bool $dryRun, callable $deadlineReached, SymfonyStyle $io, int &$pruned): string
    {
        $this->tenantConnectionContext->setClubId($clubId);
        try {
            /** @var list<string> $scheduleIds */
            $scheduleIds = $this->entityManager->createQuery('SELECT s.id FROM ' . Schedule::class . ' s WHERE s.clubId = :clubId AND s.status IN (:statuses) ORDER BY s.createdAt ASC, s.id ASC')
                ->setParameter('clubId', $clubId)
                ->setParameter('statuses', array_map(static fn (ScheduleStatus $status): string => $status->value, self::FINISHED_STATUSES))
                ->getSingleColumnResult();

            foreach ($scheduleIds as $scheduleId) {
                try {
                    if ($deadlineReached()) {
                        return self::OUTCOME_STOPPED;
                    }
                    if ($this->generationLock->isGenerating($clubId)) {
                        $io->warning(\sprintf('Club %s skipped: a generation is running — rerun later.', $clubId));

                        return self::OUTCOME_SKIPPED;
                    }
                    if ($this->pruneSchedule($scheduleId, $dryRun)) {
                        ++$pruned;
                    }
                } catch (Throwable $e) {
                    $io->error(\sprintf('Schedule %s (club %s) failed: %s', $scheduleId, $clubId, $e->getMessage()));

                    return self::OUTCOME_FAILED;
                } finally {
                    $this->entityManager->clear();
                }
            }

            return self::OUTCOME_DONE;
        } finally {
            $this->tenantConnectionContext->clear();
        }
    }

    /** Rend vrai si le snapshot ou l'empreinte de la version change ; n'écrit rien en dry-run. */
    private function pruneSchedule(string $scheduleId, bool $dryRun): bool
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);
        // Statut relu sur la version chargée : une version repartie en génération depuis la requête
        // n'est pas touchée.
        if (!$schedule instanceof Schedule || !\in_array($schedule->getStatus(), self::FINISHED_STATUSES, true)) {
            return false;
        }

        $snapshot = $schedule->getSnapshotData();
        // Une version sans snapshot (échec avant l'envoi) n'a ni clé à retirer ni empreinte à recalculer.
        if ([] === $snapshot) {
            return false;
        }

        $prunedSnapshot = $snapshot;
        foreach (self::REMOVED_KEYS as $object => $keys) {
            if (!\is_array($prunedSnapshot[$object] ?? null)) {
                continue;
            }
            foreach ($prunedSnapshot[$object] as $index => $row) {
                if (\is_array($row)) {
                    foreach ($keys as $key) {
                        unset($row[$key]);
                    }
                    $prunedSnapshot[$object][$index] = $row;
                }
            }
        }
        // Lot 5 : l'empreinte recalculée garde la règle de trajet que la version a vue (sa copie du froid).
        $hash = ScheduleConstraintBuilder::fingerprint($prunedSnapshot, $this->planStates->travelRuleOfVersion($schedule->getId()));
        if ($prunedSnapshot === $snapshot && $hash === $schedule->getSnapshotHash()) {
            return false;
        }

        if (!$dryRun) {
            $schedule->setSnapshotData($prunedSnapshot);
            $schedule->setSnapshotHash($hash);
            $this->entityManager->flush();
        }

        return true;
    }

    /** @param list<string> $clubIds */
    private function purgeCache(array $clubIds): void
    {
        foreach ($clubIds as $clubId) {
            $this->scheduleCachePool->invalidateTags([ScheduleConstraintBuilder::cacheTag($clubId)]);
        }
    }
}
