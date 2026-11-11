<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\ReconcileMode;
use App\Service\StuckPlacementRunReconciler;
use App\Service\TenantConnectionContext;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Throwable;

/**
 * Un worker qui DÉMARRE rattrape d'abord les placements que le précédent a laissés en vol (tué
 * par l'OOM, SIGKILL du déploiement, mort de Redis) — décision fondateur : un hook au démarrage
 * du worker, pas de cron. `--time-limit=3600` + `restart: unless-stopped` le font jouer au moins
 * une fois par heure, à chaque déploiement et à chaque mort du processus.
 *
 * Seul le worker qui consomme `async` rattrape (pas un `messenger:failed:retry`). `Worker::run()`
 * émet `WorkerStartedEvent` AVANT sa première lecture de file : aucun autre consommateur n'existe
 * à cet instant, d'où le mode « démarrage ».
 *
 * Un rattrapage qui casse n'empêche JAMAIS le worker de démarrer : erreur journalisée, EM remis à
 * neuf (le premier message ne doit pas hériter d'un EM fermé), GUC du tenant vidé. Périmètre par
 * le GUC seul — jamais de filtre Doctrine, qui survivrait jusqu'au premier message.
 */
#[AsEventListener(event: WorkerStartedEvent::class)]
final readonly class PlacementRunStartupReconciler
{
    public function __construct(
        private StuckPlacementRunReconciler $reconciler,
        private ManagerRegistry $managerRegistry,
        private TenantConnectionContext $tenantConnectionContext,
        private ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(WorkerStartedEvent $event): void
    {
        if (!\in_array('async', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }

        try {
            $result = $this->reconciler->reconcile(ReconcileMode::Startup);
            if ($result->count() > 0 || [] !== $result->skippedClubIds || !$result->queueInspected) {
                $this->logger?->warning('Placement runs reconciled at worker startup', [
                    'runningRunIds' => $result->runningRunIds,
                    'pendingRunIds' => $result->pendingRunIds,
                    'skippedClubIds' => $result->skippedClubIds,
                    'queueInspected' => $result->queueInspected,
                ]);
            }
        } catch (Throwable $exception) {
            $this->logger?->error('Placement run reconciliation failed at worker startup', ['exception' => $exception]);
            $this->managerRegistry->resetManager();
        } finally {
            $this->tenantConnectionContext->clear();
        }
    }
}
