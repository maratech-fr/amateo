<?php

declare(strict_types=1);

namespace App\Sentry;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Filtre `before_send` du SDK Sentry : écarte les REFUS HTTP NORMAUX avant l'envoi.
 *
 * Contexte (2026-10-03) : un scan Nuclei a rempli Sentry de 404/405/400/401/403 — du bruit de
 * robot, aucune vraie erreur. On abandonne silencieusement toute exception HTTP CLIENT (statut
 * 4xx) : `NotFoundHttpException`, `MethodNotAllowedHttpException`, `BadRequestHttpException`,
 * `AccessDeniedHttpException`, `UnauthorizedHttpException`… — toutes implémentent
 * {@see HttpExceptionInterface}, le filtre se fait donc sur LE STATUT, pas sur une liste fermée de
 * classes. Les 5xx passent (vraie panne serveur), de même que les échecs Messenger et les erreurs
 * de commande console (ni l'un ni l'autre ne portent de statut HTTP < 500).
 *
 * L'`AccessDeniedException` de SÉCURITÉ (`Symfony\Component\Security\Core\Exception`) n'est PAS une
 * HttpException (aucun statut à filtrer) : elle est écartée via `ignore_exceptions` dans
 * `config/packages/sentry.yaml`.
 */
final class BeforeSend
{
    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        return $event;
    }
}
