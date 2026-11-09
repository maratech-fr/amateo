<?php

declare(strict_types=1);

namespace App\EventListener;

use ApiPlatform\State\Util\RequestAttributesExtractor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Relaie en prod (debug coupé) le motif d'un refus 4xx VOULU, levé par notre code sur une route
 * Symfony simple. Sans lui, Symfony rend `detail = reason-phrase` (« Conflict ») dès que le debug
 * est coupé, et l'écran tombe sur son repli générique.
 *
 * Liste BLANCHE par origine — le refus n'est relayé que si TOUTES les conditions tiennent :
 * - une `HttpExceptionInterface` de statut 4xx (un 5xx peut porter du SQL ou un chemin) ;
 * - INSTANCIÉE sous `{projectDir}/src/` (PHP fixe `getFile()` à l'instanciation) : les exceptions
 *   créées par le framework (firewall « Access Denied. », routeur « No route found… », 405 qui
 *   porte l'URL — donc le jeton d'une page publique) restent à Symfony ; une fabrique du vendor
 *   appelée depuis `src/` (`createNotFoundException`…) n'est PAS relayée, c'est voulu ;
 * - un message non vide et différent de la reason-phrase ;
 * - une requête que n'a pas prise API Platform (condition EXACTE de son `ExceptionListener`, qui
 *   garde déjà le message en prod) ;
 * - un chemin `/api/` et un format préféré autre que `html` (une navigation garde la page Symfony).
 *
 * Priorité -32 : après Sentry (128) et `ErrorListener::logKernelException` (0) — le refus reste
 * journalisé — et avant `RouterListener` (-64), API Platform (-96) et le rendu Symfony (-128).
 */
final class HttpExceptionDetailListener implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', -32]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $status = $exception->getStatusCode();
        if ($status < 400 || $status > 499) {
            return;
        }

        if (!str_starts_with($exception->getFile(), $this->projectDir . '/src/')) {
            return;
        }

        $message = $exception->getMessage();
        if ('' === $message || $message === (Response::$statusTexts[$status] ?? null)) {
            return;
        }

        $request = $event->getRequest();
        if ($this->isHandledByApiPlatform($request)) {
            return;
        }

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        if ('html' === $request->getPreferredFormat('json')) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => 'An error occurred',
            'status' => $status,
            'detail' => $message,
        ], $status, $exception->getHeaders() + ['Content-Type' => 'application/problem+json']));
        // Redondant avec setResponse(), gardé pour la lecture : API Platform et Symfony ne
        // repassent pas derrière.
        $event->stopPropagation();
    }

    /** Reprise mot pour mot de `ApiPlatform\Symfony\EventListener\ExceptionListener` (l. 39-40). */
    private function isHandledByApiPlatform(Request $request): bool
    {
        return (RequestAttributesExtractor::extractAttributes($request)['respond'] ?? $request->attributes->getBoolean('_api_respond', false))
            || $request->attributes->getBoolean('_graphql', false);
    }
}
