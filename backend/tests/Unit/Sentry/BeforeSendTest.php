<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sentry;

use App\Entity\Club;
use App\Entity\User;
use App\Sentry\BeforeSend;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityManagerClosed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Throwable;

/**
 * Le filtre `before_send` : un refus HTTP CLIENT (4xx) est du bruit de scan, jamais une erreur à
 * remonter ; un 5xx (ou une exception sans statut HTTP) passe toujours. Les événements ENVOYÉS
 * portent l'identité MINIMALE voulue (décision fondateur 2026-10-06) : id INTERNE de l'utilisateur
 * (jamais email/nom/IP) + code FFBB du club résolu par la requête en tag `club_ffbb`.
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
        self::assertNull($this->beforeSend()(Event::createEvent(), EventHint::fromArray(['exception' => $exception])));
    }

    public function testAServerHttpExceptionIsKept(): void
    {
        $event = Event::createEvent();
        self::assertSame($event, $this->beforeSend()($event, EventHint::fromArray(['exception' => new HttpException(500)])));
    }

    public function testAnOrdinaryExceptionIsKept(): void
    {
        $event = Event::createEvent();
        self::assertSame($event, $this->beforeSend()($event, EventHint::fromArray(['exception' => new RuntimeException('boom')])));
    }

    public function testAnEventWithoutAnExceptionHintIsKept(): void
    {
        $event = Event::createEvent();
        self::assertSame($event, $this->beforeSend()($event, null));
    }

    public function testASentEventCarriesTheUserIdAndClubFfbbTagAndNoPii(): void
    {
        $user = (new User)->setId('11111111-1111-1111-1111-111111111111');
        $club = (new Club)->setFfbbClubCode('ARA0069013');

        $event = $this->beforeSend($user, 'club-uuid', $club)(
            Event::createEvent(),
            EventHint::fromArray(['exception' => new RuntimeException('boom')]),
        );

        self::assertNotNull($event);
        $userBag = $event->getUser();
        self::assertNotNull($userBag);
        self::assertSame('11111111-1111-1111-1111-111111111111', $userBag->getId());
        // AUCUNE PII : ni email, ni nom, ni IP.
        self::assertNull($userBag->getEmail());
        self::assertNull($userBag->getUsername());
        self::assertNull($userBag->getIpAddress());
        self::assertSame(['club_ffbb' => 'ARA0069013'], $event->getTags());
    }

    public function testADemoClubIsAlsoTagged(): void
    {
        $user = (new User)->setId('22222222-2222-2222-2222-222222222222');
        $club = (new Club)->setFfbbClubCode('ARA9999999');

        $event = $this->beforeSend($user, 'demo-uuid', $club)(
            Event::createEvent(),
            EventHint::fromArray(['exception' => new RuntimeException('boom')]),
        );

        self::assertNotNull($event);
        self::assertSame(['club_ffbb' => 'ARA9999999'], $event->getTags());
    }

    public function testWithoutAResolvedClubNoClubTagIsSet(): void
    {
        // Pas de `_club_id` (cas `/api/admin/**` : le listener sort avant toute résolution de club).
        $user = (new User)->setId('33333333-3333-3333-3333-333333333333');

        $event = $this->beforeSend($user, null, null)(
            Event::createEvent(),
            EventHint::fromArray(['exception' => new RuntimeException('boom')]),
        );

        self::assertNotNull($event);
        self::assertSame('33333333-3333-3333-3333-333333333333', $event->getUser()?->getId());
        self::assertSame([], $event->getTags());
    }

    public function testWithoutAnAuthenticatedUserNothingIsSet(): void
    {
        $event = $this->beforeSend(null, null, null)(
            Event::createEvent(),
            EventHint::fromArray(['exception' => new RuntimeException('boom')]),
        );

        self::assertNotNull($event);
        self::assertNull($event->getUser());
        self::assertSame([], $event->getTags());
    }

    public function testAClosedEntityManagerNeverBreaksErrorSending(): void
    {
        // Cas réel : l'événement naît d'une 5xx dont la cause est une erreur DB, la transaction
        // Doctrine est avortée et l'EntityManager FERMÉ — `find()` jette alors. L'enrichissement
        // best-effort ne doit jamais masquer l'erreur d'origine ni faire échouer l'envoi : le `try`
        // de `currentClubFfbbCode` attrape tout `Throwable` (EntityManagerClosed en est un).
        $user = (new User)->setId('44444444-4444-4444-4444-444444444444');

        $tokenStorage = new TokenStorage;
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));

        $request = new Request;
        $request->attributes->set('_club_id', 'club-uuid');
        $requestStack = new RequestStack([$request]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willThrowException(EntityManagerClosed::create());

        $beforeSend = new BeforeSend($requestStack, $tokenStorage, $entityManager);

        $event = $beforeSend(Event::createEvent(), EventHint::fromArray(['exception' => new RuntimeException('db down')]));

        // L'événement part quand même : id utilisateur conservé, pas de tag club (lecture avortée).
        self::assertNotNull($event);
        self::assertSame('44444444-4444-4444-4444-444444444444', $event->getUser()?->getId());
        self::assertSame([], $event->getTags());
    }

    private function beforeSend(?User $user = null, ?string $clubId = null, ?Club $club = null): BeforeSend
    {
        $tokenStorage = new TokenStorage;
        if ($user instanceof User) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));
        }

        $requestStack = new RequestStack;
        if (null !== $clubId) {
            $request = new Request;
            $request->attributes->set('_club_id', $clubId);
            $requestStack->push($request);
        }

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->with(Club::class, $clubId)->willReturn($club);

        return new BeforeSend($requestStack, $tokenStorage, $entityManager);
    }
}
