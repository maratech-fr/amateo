<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sentry;

use App\Sentry\BeforeSend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * Le filtre `before_send` : un refus HTTP CLIENT (4xx) est du bruit de scan, jamais une erreur à
 * remonter ; un 5xx (ou une exception sans statut HTTP) passe toujours.
 */
#[Group('phase1')]
final class BeforeSendTest extends TestCase
{
    /** @return iterable<string, array{0: Throwable}> */
    public static function clientRefusals(): iterable
    {
        yield '404' => [new NotFoundHttpException];
        yield '405' => [new MethodNotAllowedHttpException(['GET'])];
        yield '400' => [new BadRequestHttpException];
        yield '403' => [new AccessDeniedHttpException];
        yield '401' => [new UnauthorizedHttpException('Bearer')];
        yield '429' => [new HttpException(429)];
    }

    #[DataProvider('clientRefusals')]
    public function testAClientHttpExceptionIsDropped(Throwable $exception): void
    {
        self::assertNull((new BeforeSend)(Event::createEvent(), EventHint::fromArray(['exception' => $exception])));
    }

    public function testAServerHttpExceptionIsKept(): void
    {
        $event = Event::createEvent();
        self::assertSame($event, (new BeforeSend)($event, EventHint::fromArray(['exception' => new HttpException(500)])));
    }

    public function testAnOrdinaryExceptionIsKept(): void
    {
        $event = Event::createEvent();
        self::assertSame($event, (new BeforeSend)($event, EventHint::fromArray(['exception' => new RuntimeException('boom')])));
    }

    public function testAnEventWithoutAnExceptionHintIsKept(): void
    {
        $event = Event::createEvent();
        self::assertSame($event, (new BeforeSend)($event, null));
    }
}
