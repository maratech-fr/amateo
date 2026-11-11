<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\MatchPlacementRun;
use App\Enum\MatchPlacementRunStatus;
use App\Message\PlaceMatchesMessage;
use App\MessageHandler\PlaceMatchesHandler;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementProgressPublisher;
use App\Service\PlacementRunFailureNotifier;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Throwable;

/**
 * Un message de placement qui échoue DÉFINITIVEMENT (retries épuisés : base injoignable au
 * démarrage du handler…) ne laisse pas son run en vol (patron `ScheduleGenerationFailureListener`) :
 * le run PENDING/RUNNING passe FAILED « n'a pas pu être lancé », l'écran est réveillé, le verrou du
 * message est rendu (le club peut relancer tout de suite). Rien sur un échec qui sera rejoué, rien
 * pour un autre message, rien sur un run déjà terminé.
 *
 * Best-effort : une base encore morte se journalise en warning, jamais propagée (le rattrapage du
 * démarrage suivant prend le relais : message dans le transport `failed`, que la file `async` ne
 * contient plus → « relancez-le » après la grâce). Périmètre par le GUC du club seul — jamais de
 * filtre Doctrine ; GUC et EM vidés en `finally`.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class PlaceMatchesFailureListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantConnectionContext $tenantConnectionContext,
        private MatchPlacementLock $lock,
        private MatchPlacementProgressPublisher $publisher,
        private PlacementRunFailureNotifier $failureNotifier,
        #[Autowire(service: 'app.clock.real')]
        private ClockInterface $clock,
        private ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof PlaceMatchesMessage) {
            return;
        }

        $clubId = $message->getClubId();

        try {
            $this->tenantConnectionContext->setClubId($clubId);
            $run = $this->entityManager->find(MatchPlacementRun::class, $message->getRunId());
            if (!$run instanceof MatchPlacementRun
                || !\in_array($run->getStatus(), [MatchPlacementRunStatus::PENDING, MatchPlacementRunStatus::RUNNING], true)) {
                return;
            }

            $run->setStatus(MatchPlacementRunStatus::FAILED)
                ->setFinishedAt(DateTimeImmutable::createFromInterface($this->clock->now()))
                ->setResultData(['error' => PlaceMatchesHandler::ERROR_NOT_STARTED]);
            $this->entityManager->flush();

            $this->publisher->publishTerminal($clubId, $run->getId(), MatchPlacementRunStatus::FAILED->value);
            $this->failureNotifier->notify($run);
            $this->lock->release($clubId, $message->getLockToken());
        } catch (Throwable $exception) {
            $this->logger?->warning('Match placement failure listener could not fail the run (best-effort)', ['clubId' => $clubId, 'runId' => $message->getRunId(), 'exception' => $exception]);
        } finally {
            try {
                $this->entityManager->clear();
            } catch (Throwable) {
                // EM inutilisable : rien à vider.
            }
            $this->tenantConnectionContext->clear();
        }
    }
}
