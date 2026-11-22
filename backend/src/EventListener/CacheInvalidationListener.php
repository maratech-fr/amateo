<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\ScheduleConstraintBuilder;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Purge le cache du payload solveur (`cache.schedule`) quand une entité métier
 * d'un club change — par TAG club, ce qui couvre toutes les saisons d'un coup
 * (`SportCategory` alimente le payload sans porter de `seasonId`).
 *
 * P2-12 (2026-08-04) : le pool `cache.tenant` et les clés `tenant_data`/
 * `schedule_snapshot` ont été SUPPRIMÉS — aucun writer nulle part, le listener
 * purgait du vide depuis la phase 1. Le seul cache métier réel est
 * `cache.schedule` (P2-9ter).
 *
 * La purge est différée à la FIN du travail (jamais pendant — le même cycle
 * peut encore lire le cache), sur les DEUX fins possibles :
 *  - requête HTTP → `kernel.terminate` ;
 *  - message Messenger → `WorkerMessageHandled`/`Failed` (P2-11 : le worker
 *    n'émet JAMAIS de TerminateEvent — les invalidations collectées pendant
 *    `GenerateScheduleHandler` ne partaient jamais, le payload restait périmé
 *    et `$pendingInvalidations` croissait sur un process long ; `Failed`
 *    aussi : un handler peut avoir flushé des entités AVANT d'échouer).
 */
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postRemove)]
class CacheInvalidationListener
{
    /** @var array<string, true> club ids dont le payload solveur doit être purgé */
    private array $pendingInvalidations = [];

    public function __construct(
        // Tag-aware : le payload solveur est purgé par TAG (cf. flushInvalidations).
        private readonly TagAwareAdapterInterface $scheduleCachePool,
    ) {}

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collectInvalidation($args->getObject());
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collectInvalidation($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->collectInvalidation($args->getObject());
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->flushInvalidations();
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->flushInvalidations();
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->flushInvalidations();
    }

    /**
     * Flush collected invalidations — différé à la fin du travail pour ne pas
     * purger un cache que le même cycle est encore en train de lire.
     */
    public function flushInvalidations(): void
    {
        foreach (array_keys($this->pendingInvalidations) as $clubId) {
            // Le payload solveur : purgé par TAG, toutes saisons du club d'un coup.
            // Indispensable — `SportCategory` alimente le payload (âges, et les étiquettes
            // qui éclatent les règles de groupe se calculent depuis elle) mais ne porte PAS
            // de `seasonId` : aucune clé par saison ne serait devinable depuis l'entité éditée.
            $this->scheduleCachePool->invalidateTags([ScheduleConstraintBuilder::cacheTag((string) $clubId)]);
        }

        $this->pendingInvalidations = [];
    }

    private function collectInvalidation(object $entity): void
    {
        $clubId = $this->resolveClubId($entity);
        if (null === $clubId) {
            return;
        }

        $this->pendingInvalidations[$clubId] = true;
    }

    private function resolveClubId(object $entity): ?string
    {
        $method = 'getClubId';
        if (!method_exists($entity, $method)) {
            return null;
        }

        $clubId = $entity->$method();

        return \is_string($clubId) && '' !== $clubId ? $clubId : null;
    }
}
