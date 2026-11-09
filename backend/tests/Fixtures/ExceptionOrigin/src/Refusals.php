<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ExceptionOrigin\src;

use App\Tests\Unit\EventListener\HttpExceptionDetailListenerTest;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Racine de projet FACTICE pour {@see HttpExceptionDetailListenerTest} :
 * le listener ne relaie que les exceptions INSTANCIÉES sous `{projectDir}/src/` (PHP fixe `getFile()`
 * à l'instanciation). Les exceptions fabriquées ICI ont donc `getFile()` sous
 * `tests/Fixtures/ExceptionOrigin/src/` — le test passe `tests/Fixtures/ExceptionOrigin` comme
 * `projectDir`, et chaque refus unitaire ne diffère d'un refus relayé que par UNE condition de la
 * règle (statut, type, message, requête), jamais par l'origine. Sans ça, une exception créée dans le
 * fichier de test serait écartée par l'origine et les autres conditions resteraient infalsifiables.
 */
final class Refusals
{
    /** Le dossier à passer comme `projectDir` : son `src/` contient ce fichier. */
    public static function projectDir(): string
    {
        return \dirname(__DIR__);
    }

    public static function conflict(string $message): ConflictHttpException
    {
        return new ConflictHttpException($message);
    }

    public static function tooManyRequests(int $retryAfter, string $message): TooManyRequestsHttpException
    {
        return new TooManyRequestsHttpException($retryAfter, $message);
    }

    public static function status(int $status, string $message): HttpException
    {
        return new HttpException($status, $message);
    }

    /** Une exception NON-HTTP dont le CODE ressemble à un statut 4xx (un SQLSTATE, un code métier…). */
    public static function notHttp(string $message, int $code): Throwable
    {
        return new RuntimeException($message, $code);
    }
}
