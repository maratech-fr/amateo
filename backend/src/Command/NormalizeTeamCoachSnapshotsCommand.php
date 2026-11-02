<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Schedule;
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
 * « Coach obligatoire » a quitté le payload : un lien TEAM_COACH n'y porte plus ni `severity` ni
 * `metadata.isRequired`. L'empreinte de structure de toute version antérieure diffère donc de
 * l'empreinte courante, et tout planning paraîtrait périmé sans que rien n'ait changé.
 *
 * À jouer une fois juste après le déploiement. Pour chaque version qui porte ces clés, elles sont
 * retirées du snapshot et le `snapshot_hash` est recalculé DEPUIS ce snapshot, jamais recopié de la
 * structure courante : une version vraiment périmée le reste. Aucune écriture sans clé retirée, ce
 * qui rend la commande idempotente. Seul TEAM_COACH est touché : la `severity` d'un lien coach-joueur
 * reste un signal lu par le moteur.
 *
 * Une génération en cours écrit la version qu'elle importe (`#[ORM\Version]`) : le verrou est relu
 * avant chaque version et le club est sauté. Une version en échec arrête tout ; le flush étant par
 * version, ce qui est écrit le reste et un second passage termine le travail.
 *
 * Le pool `cache.schedule` est purgé pour tous les clubs avant le traitement (le payload de l'ancien
 * code ne doit plus être lu) et après (un écran ouvert a pu le réamorcer entre-temps).
 */
#[AsCommand(
    name: 'app:schedule:normalize-team-coach-snapshots',
    description: 'Remove the former required-coach keys from schedule snapshots and recompute their hash. Run once after deployment.',
)]
final class NormalizeTeamCoachSnapshotsCommand extends Command
{
    private const string OUTCOME_DONE = 'done';
    private const string OUTCOME_SKIPPED = 'skipped';
    private const string OUTCOME_FAILED = 'failed';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly ClubGenerationLock $generationLock,
        #[Autowire(service: 'cache.schedule')]
        private readonly TagAwareAdapterInterface $scheduleCachePool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count affected schedules without writing or purging anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $clubIds = array_map(
            static fn (Club $club): string => $club->getId(),
            $this->entityManager->getRepository(Club::class)->findAll(),
        );
        $this->entityManager->clear();

        if (!$dryRun) {
            $this->purgeCache($clubIds);
        }

        $complete = true;
        $normalized = 0;
        try {
            foreach ($clubIds as $clubId) {
                $outcome = $this->normalizeClub($clubId, $dryRun, $io, $normalized);
                $complete = $complete && self::OUTCOME_DONE === $outcome;
                if (self::OUTCOME_FAILED === $outcome) {
                    break;
                }
            }
        } finally {
            if (!$dryRun) {
                $this->purgeCache($clubIds);
            }
        }

        $summary = \sprintf($dryRun ? '%d schedule(s) would be normalized (dry-run).' : '%d schedule(s) normalized.', $normalized);
        if ($complete) {
            $io->success($summary);

            return Command::SUCCESS;
        }
        $io->warning($summary);

        return Command::FAILURE;
    }

    /** @return self::OUTCOME_* */
    private function normalizeClub(string $clubId, bool $dryRun, SymfonyStyle $io, int &$normalized): string
    {
        $this->tenantConnectionContext->setClubId($clubId);
        try {
            /** @var list<string> $scheduleIds */
            $scheduleIds = $this->entityManager->createQuery('SELECT s.id FROM ' . Schedule::class . ' s WHERE s.clubId = :clubId ORDER BY s.createdAt ASC, s.id ASC')
                ->setParameter('clubId', $clubId)
                ->getSingleColumnResult();

            foreach ($scheduleIds as $scheduleId) {
                try {
                    if ($this->generationLock->isGenerating($clubId)) {
                        $io->warning(\sprintf('Club %s skipped: a generation is running — rerun later.', $clubId));

                        return self::OUTCOME_SKIPPED;
                    }
                    if ($this->normalizeSchedule($scheduleId, $dryRun)) {
                        ++$normalized;
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

    /** Rend vrai si la version porte une clé à retirer ; n'écrit rien en dry-run. */
    private function normalizeSchedule(string $scheduleId, bool $dryRun): bool
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);
        if (!$schedule instanceof Schedule) {
            return false;
        }

        $snapshot = $schedule->getSnapshotData();
        $constraints = $snapshot['constraints'] ?? null;
        if (!\is_array($constraints)) {
            return false;
        }

        $removed = false;
        foreach ($constraints as $index => $constraint) {
            if (!\is_array($constraint) || 'TEAM_COACH' !== ($constraint['type'] ?? null)) {
                continue;
            }
            if (\array_key_exists('severity', $constraint)) {
                unset($constraints[$index]['severity']);
                $removed = true;
            }
            if (\is_array($constraint['metadata'] ?? null) && \array_key_exists('isRequired', $constraint['metadata'])) {
                unset($constraints[$index]['metadata']['isRequired']);
                $removed = true;
            }
        }
        if (!$removed) {
            return false;
        }

        if (!$dryRun) {
            $snapshot['constraints'] = $constraints;
            $schedule->setSnapshotData($snapshot);
            $schedule->setSnapshotHash(ScheduleConstraintBuilder::structureHash($snapshot));
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
