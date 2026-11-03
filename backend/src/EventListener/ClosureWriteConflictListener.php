<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ClosurePinsPreviewRequiredException;
use App\Exception\ScheduleGenerationInProgressException;
use App\Service\ClosurePinRemover;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fermetures de gymnase (« Enregistrer vaut geste ») — rend les deux 409 STRUCTURÉS des écritures
 * qui retirent des épingles ({@see ClosurePinRemover}) : un State Processor ne peut pas émettre un
 * corps sur-mesure (patron {@see WindowAlreadyPlannedListener}).
 *
 *  - {@see ScheduleGenerationInProgressException} → `{code: 'generation_in_progress', error}` — même
 *    code machine que les gestes du planning (`ManualEditController`) ; les contrôleurs qui la
 *    rattrapent déjà eux-mêmes ne sont pas concernés (elle n'arrive ici que non rattrapée) ;
 *  - {@see ClosurePinsPreviewRequiredException} → `{code: 'closure_pins_preview_required', error, toRemove}`.
 *
 * Priority 256 : AVANT Sentry et l'ExceptionListener d'API Platform — un résultat métier attendu,
 * pas une erreur à journaliser.
 */
final class ClosureWriteConflictListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 256]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if ($exception instanceof ScheduleGenerationInProgressException) {
            $message = '' !== $exception->getMessage() ? $exception->getMessage() : 'Une génération est en cours pour ce club : réessayez quand elle est terminée.';
            $event->setResponse(new JsonResponse(['code' => 'generation_in_progress', 'error' => $message], Response::HTTP_CONFLICT));
            $event->stopPropagation();

            return;
        }
        if ($exception instanceof ClosurePinsPreviewRequiredException) {
            $event->setResponse(new JsonResponse([
                'code' => 'closure_pins_preview_required',
                'error' => $exception->getMessage(),
                'toRemove' => $exception->toRemove,
                'pinRemoval' => $exception->pinRemoval,
            ], Response::HTTP_CONFLICT));
            $event->stopPropagation();
        }
    }
}
