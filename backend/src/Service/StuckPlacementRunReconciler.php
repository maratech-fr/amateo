<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\MatchPlacementRun;
use App\Enum\MatchPlacementRunStatus;
use App\MessageHandler\PlaceMatchesHandler;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Rattrapage des runs de placement laissés en vol par un worker mort (OOM, SIGKILL du
 * déploiement, Redis vidé). Joué au DÉMARRAGE du worker ({@see ReconcileMode::Startup}, par
 * `PlacementRunStartupReconciler`) et à la main par l'opérateur ({@see ReconcileMode::Cli},
 * `app:match-placement:reconcile-stuck`) — pas de cron.
 *
 *  - RUNNING : au démarrage, TOUT run RUNNING est orphelin (aucun autre consommateur du transport
 *    `async` n'existe encore). En mode commande (worker vivant), seulement si le verrou du club a
 *    disparu — armé au budget du run avant le passage en RUNNING, il n'expire qu'au-delà — ET que
 *    le run a démarré il y a plus de 5 min (fenêtre lecture → réarmement du handler). Résidu
 *    nommé : quelques secondes après l'expiration de la clé, un handler qui applique encore son
 *    résultat devient perdant (il n'écrit rien). → FAILED T1, verrou JAMAIS touché (le calcul
 *    interrompu peut encore tenir le moteur).
 *  - PENDING : absent de la file Messenger (lue AVANT la base) et créé il y a plus que la grâce →
 *    FAILED T2. File illisible = bras PENDING entièrement sauté.
 *  - COMPLETED / FAILED : jamais touchés.
 *
 * Isolation : les clubs sont lus sans GUC (la table club n'a pas de policy RLS), puis chaque club
 * est traité sous SON GUC (`TenantConnectionContext`), vidé en `finally` avec l'EM. Ce service
 * n'utilise PAS `TenantDoctrineScope` : hors requête, son périmètre mémorisé (filtre Doctrine +
 * club) survivrait jusqu'au premier message du worker, peut-être d'un autre club. Un club qui
 * casse est journalisé et sauté ; l'EM est alors remis à neuf PAR RÉAFFECTATION (le premier
 * message du worker, et le notifier des clubs suivants, travaillent sur un EM ouvert).
 */
class StuckPlacementRunReconciler
{
    public const int RUNNING_GRACE_SECONDS = 300;

    public const int DEFAULT_PENDING_GRACE_SECONDS = 300;

    public function __construct(
        // NON readonly : réaffecté après `resetManager()` quand un club casse.
        private EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly MatchPlacementLock $lock,
        private readonly PlacementQueueInspector $queueInspector,
        private readonly MatchPlacementProgressPublisher $publisher,
        private readonly PlacementRunFailureNotifier $failureNotifier,
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function reconcile(ReconcileMode $mode, bool $dryRun = false, int $pendingGraceSeconds = self::DEFAULT_PENDING_GRACE_SECONDS): StuckPlacementReconciliation
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        // La FILE d'abord, la base ensuite : un run terminé pendant la lecture de la file est vu
        // terminé par la base, jamais « absent de la file et encore PENDING ».
        $queueInspected = true;
        $queued = [];

        try {
            $queued = array_fill_keys($this->queueInspector->queuedRunIds(), true);
        } catch (PlacementQueueUnreadable $exception) {
            $queueInspected = false;
            $this->logger?->warning('Placement queue not inspected: pending runs skipped', ['mode' => $mode->value, 'reason' => $exception->getMessage()]);
        }

        // Club n'a pas de policy RLS : lisible sans GUC. Ordre de la table (création).
        $clubIds = array_map(
            static fn (Club $club): string => $club->getId(),
            $this->entityManager->getRepository(Club::class)->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']),
        );
        $this->entityManager->clear();

        $runningRunIds = [];
        $pendingRunIds = [];
        $skippedClubIds = [];

        foreach ($clubIds as $clubId) {
            $this->tenantConnectionContext->setClubId($clubId);

            try {
                [$running, $pending] = $this->reconcileClub($clubId, $mode, $dryRun, $now, $queueInspected ? $queued : null, $pendingGraceSeconds);
                array_push($runningRunIds, ...$running);
                array_push($pendingRunIds, ...$pending);
            } catch (Throwable $exception) {
                $this->logger?->error('Placement run reconciliation failed for a club — skipped', ['clubId' => $clubId, 'mode' => $mode->value, 'exception' => $exception]);
                $skippedClubIds[] = $clubId;
                $this->resetEntityManager();
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        return new StuckPlacementReconciliation($runningRunIds, $pendingRunIds, $skippedClubIds, $queueInspected);
    }

    /**
     * @param array<string, true>|null $queued ids des runs en file ; null = file non lue
     *
     * @return array{0: list<string>, 1: list<string>} [runs RUNNING, runs PENDING] déclarés interrompus
     */
    private function reconcileClub(string $clubId, ReconcileMode $mode, bool $dryRun, DateTimeImmutable $now, ?array $queued, int $pendingGraceSeconds): array
    {
        /** @var list<MatchPlacementRun> $inFlight */
        $inFlight = $this->entityManager->getRepository(MatchPlacementRun::class)->findBy(
            ['clubId' => $clubId, 'status' => [MatchPlacementRunStatus::PENDING, MatchPlacementRunStatus::RUNNING]],
            ['createdAt' => 'ASC'],
        );

        $stuck = [];
        $running = [];
        $pending = [];
        foreach ($inFlight as $run) {
            if (MatchPlacementRunStatus::RUNNING === $run->getStatus() && $this->runningIsOrphan($run, $clubId, $mode, $now)) {
                $stuck[] = [$run, PlaceMatchesHandler::ERROR_INTERRUPTED];
                $running[] = $run->getId();
            } elseif (MatchPlacementRunStatus::PENDING === $run->getStatus() && null !== $queued
                && !isset($queued[$run->getId()])
                && $run->getCreatedAt() < $now->modify(\sprintf('-%d seconds', max(0, $pendingGraceSeconds)))) {
                $stuck[] = [$run, PlaceMatchesHandler::ERROR_ORPHANED];
                $pending[] = $run->getId();
            }
        }

        if ($dryRun || [] === $stuck) {
            return [$running, $pending];
        }

        foreach ($stuck as [$run, $error]) {
            $run->setStatus(MatchPlacementRunStatus::FAILED)->setFinishedAt($now)->setResultData(['error' => $error]);
        }
        // Le FAILED est écrit AVANT d'être annoncé : jamais un écran réveillé sur un statut que la
        // base ne porte pas encore.
        $this->entityManager->flush();

        foreach ($stuck as [$run]) {
            $this->publisher->publishTerminal($clubId, $run->getId(), MatchPlacementRunStatus::FAILED->value);
            $this->failureNotifier->notify($run);
        }

        return [$running, $pending];
    }

    private function runningIsOrphan(MatchPlacementRun $run, string $clubId, ReconcileMode $mode, DateTimeImmutable $now): bool
    {
        if (ReconcileMode::Startup === $mode) {
            return true;
        }

        $startedAt = $run->getStartedAt() ?? $run->getCreatedAt();
        if ($startedAt >= $now->modify(\sprintf('-%d seconds', self::RUNNING_GRACE_SECONDS))) {
            return false;
        }

        try {
            return !$this->lock->isHeld($clubId);
        } catch (Throwable $exception) {
            // Verrou illisible : on ne tue jamais un run qui tourne peut-être encore.
            $this->logger?->warning('Placement lock not readable: running run left alone', ['clubId' => $clubId, 'runId' => $run->getId(), 'exception' => $exception]);

            return false;
        }
    }

    private function resetEntityManager(): void
    {
        $this->managerRegistry->resetManager();
        $entityManager = $this->managerRegistry->getManager();
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
    }
}
