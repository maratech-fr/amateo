<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\HttpExceptionDetailListener;
use App\Tests\Fixtures\ExceptionOrigin\src\Refusals;
use App\Tests\Integration\Api\ErrorDetailSurvivesProdModeTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * La règle du relais d'un refus 4xx en prod, condition par condition : le listener ne rend
 * `{type,title,status,detail}` (application/problem+json) QUE pour une `HttpExceptionInterface`
 * 4xx INSTANCIÉE sous `{projectDir}/src/`, à message propre, sur une requête `/api/` JSON que
 * n'a pas prise API Platform — tout le reste est laissé à Symfony / API Platform tel quel.
 *
 * Chaque refus ci-dessous ne diffère du cas relayé que par UNE condition : les exceptions viennent
 * d'une racine de projet FACTICE ({@see Refusals}, sous `tests/Fixtures/ExceptionOrigin/src/`), si
 * bien que l'origine est toujours « src » et que retirer une seule condition du listener rougit le
 * test qui la nomme. La preuve « une exception créée sous vendor/ n'est jamais relayée » ne vit
 * PAS ici (jamais par réflexion sur `file`) : elle vit en intégration, sur les vraies exceptions du
 * framework — {@see ErrorDetailSurvivesProdModeTest}.
 */
final class HttpExceptionDetailListenerTest extends TestCase
{
    /** Le cas nominal : un 409 de notre code, requête JSON, sort tel qu'écrit et arrête la propagation. */
    public function testARefusalInstantiatedUnderSrcIsRelayedAsProblemJson(): void
    {
        $event = $this->dispatch(Refusals::conflict('Validez le planning de saison avant de créer un match ou un planning de période.'));

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response, 'un refus 4xx de src/ sur une route JSON est relayé');
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => 'An error occurred',
            'status' => 409,
            'detail' => 'Validez le planning de saison avant de créer un match ou un planning de période.',
        ], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR), 'forme du corps identique au debug d\'aujourd\'hui, sans class ni trace');
        self::assertTrue($event->isPropagationStopped(), 'API Platform (-96) et Symfony (-128) ne repassent pas derrière');
    }

    /** Les en-têtes portés par le refus (Retry-After d'un 429) survivent au relais. */
    public function testTheRefusalHeadersAreKept(): void
    {
        $response = $this->dispatch(Refusals::tooManyRequests(42, 'Trop de requêtes d\'affilée. Patientez quelques instants avant de réessayer.'))->getResponse();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('42', $response->headers->get('Retry-After'));
    }

    /** Un 5xx n'est JAMAIS relayé, même né dans src/ : son message peut porter du SQL ou un chemin. */
    public function testAServerErrorIsNeverRelayed(): void
    {
        foreach ([500, 502, 503] as $status) {
            $event = $this->dispatch(Refusals::status($status, 'SQLSTATE[23505]: Unique violation at /app/backend/src/Service/X.php'));

            self::assertFalse($event->hasResponse(), $status . ' : laissé à Symfony (reason-phrase seule en prod)');
            self::assertFalse($event->isPropagationStopped());
        }
    }

    /**
     * Une exception qui n'est pas une HttpExceptionInterface (RuntimeException, DBAL…) est ignorée,
     * même quand son CODE ressemble à un 4xx : le statut ne se lit jamais dans `getCode()`.
     */
    public function testANonHttpThrowableIsIgnored(): void
    {
        foreach ([409, 422] as $code) {
            $event = $this->dispatch(Refusals::notHttp('Connection refused to postgres:5432', $code));

            self::assertFalse($event->hasResponse(), 'code ' . $code . ' : jamais relayé');
            self::assertFalse($event->isPropagationStopped());
        }
    }

    /** Message vide ou égal à la reason-phrase : rien à dire de plus que Symfony, laissé à Symfony. */
    public function testAnEmptyOrStatusTextMessageIsLeftToSymfony(): void
    {
        foreach ([
            'vide' => Refusals::conflict(''),
            'reason-phrase 409' => Refusals::conflict('Conflict'),
            'reason-phrase 404' => Refusals::status(404, 'Not Found'),
            'reason-phrase 429' => Refusals::tooManyRequests(10, 'Too Many Requests'),
        ] as $case => $exception) {
            $event = $this->dispatch($exception);

            self::assertFalse($event->hasResponse(), $case . ' : laissé à Symfony');
            self::assertFalse($event->isPropagationStopped(), $case);
        }
    }

    /**
     * Une requête que traite API Platform lui est laissée — condition EXACTE de
     * `ApiPlatform\Symfony\EventListener\ExceptionListener:39-40` : `extractAttributes()['respond']`
     * (opération qui répond) ?? `_api_respond`, ou `_graphql`. Témoin : une opération déclarée
     * `_api_respond: false` n'est PAS prise par API Platform → elle est relayée (une condition lâche
     * « des attributs API Platform existent » la laisserait tomber dans la reason-phrase).
     */
    public function testAnApiPlatformRequestIsLeftToApiPlatform(): void
    {
        $refusal = 'Validez le planning de saison avant de créer un match ou un planning de période.';
        foreach ([
            'opération API Platform' => ['_api_resource_class' => 'App\Entity\Fixture', '_api_operation_name' => '_api_/fixtures{._format}_post'],
            '_api_respond seul' => ['_api_respond' => true],
            '_graphql' => ['_graphql' => true],
        ] as $case => $attributes) {
            $event = $this->dispatch(Refusals::conflict($refusal), $this->jsonRequest($attributes));

            self::assertFalse($event->hasResponse(), $case . ' : API Platform rend lui-même (message déjà gardé en prod)');
            self::assertFalse($event->isPropagationStopped(), $case . ' : API Platform doit encore voir l\'événement');
        }

        $silentOperation = $this->dispatch(Refusals::conflict($refusal), $this->jsonRequest([
            '_api_resource_class' => 'App\Entity\Fixture',
            '_api_operation_name' => '_api_/fixtures{._format}_post',
            '_api_respond' => false,
        ]));
        self::assertTrue($silentOperation->hasResponse(), 'respond=false : API Platform se retire, le refus est relayé');
    }

    /**
     * Une navigation navigateur (format préféré html) garde la page d'erreur Symfony d'aujourd'hui ;
     * `getPreferredFormat('json')` : Accept `*\/*` (fetch sans `.json()`) vaut json → relayé.
     */
    public function testAHtmlRequestIsLeftToSymfony(): void
    {
        $refusal = Refusals::conflict('La fermeture a changé depuis l\'aperçu : redemandez l\'aperçu des effets avant de confirmer.');

        $browser = $this->dispatch($refusal, Request::create('/api/fixtures/review', 'POST', server: ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']));
        self::assertFalse($browser->hasResponse(), 'Accept text/html : page HTML Symfony inchangée');
        self::assertFalse($browser->isPropagationStopped());

        $anyType = $this->dispatch($refusal, Request::create('/api/fixtures/review', 'POST', server: ['HTTP_ACCEPT' => '*/*']));
        self::assertTrue($anyType->hasResponse(), 'Accept */* : format préféré json → relayé');
    }

    /** Hors `/api/`, rien n'est relayé. */
    public function testARequestOutsideTheApiIsLeftToSymfony(): void
    {
        $event = $this->dispatch(
            Refusals::conflict('Validez le planning de saison avant de créer un match ou un planning de période.'),
            Request::create('/club-approval/abc', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
        );

        self::assertFalse($event->hasResponse());
    }

    /**
     * -32 : APRÈS Sentry (128) et `ErrorListener::logKernelException` (0) — le refus est toujours
     * journalisé/remonté — et AVANT `RouterListener` (-64), API Platform (-96) et le rendu Symfony
     * (-128), que le `stopPropagation()` court-circuite.
     */
    public function testPriorityIsBetweenSentryAndTheApiPlatformListener(): void
    {
        self::assertSame(
            [KernelEvents::EXCEPTION => ['onKernelException', -32]],
            HttpExceptionDetailListener::getSubscribedEvents(),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function jsonRequest(array $attributes = []): Request
    {
        $request = Request::create('/api/fixtures/review', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        foreach ($attributes as $key => $value) {
            $request->attributes->set($key, $value);
        }

        return $request;
    }

    private function dispatch(Throwable $throwable, ?Request $request = null): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request ?? $this->jsonRequest(),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
        new HttpExceptionDetailListener(Refusals::projectDir())->onKernelException($event);

        return $event;
    }
}
