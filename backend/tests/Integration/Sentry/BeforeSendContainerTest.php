<?php

declare(strict_types=1);

namespace App\Tests\Integration\Sentry;

use App\Sentry\BeforeSend;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Câblage DI du filtre `before_send` : {@see BeforeSend} dépend du conteneur (RequestStack +
 * TokenStorage + EntityManager), mais il n'est RÉFÉRENCÉ que par la définition du client Sentry
 * (`sentry.client.options`, cf. `config/packages/sentry.yaml`) — aucun autre service, aucun test,
 * ne le récupère normalement du conteneur. Le test unitaire `Unit\Sentry\BeforeSendTest` construit
 * l'objet À LA MAIN : il ne voit donc jamais une rupture de câblage (constructeur modifié sans que
 * la définition de service suive). Ce test-ci ferme ce trou : il RÉCUPÈRE le service depuis
 * le conteneur réel et l'INVOQUE, de sorte qu'un `ArgumentCountError` d'autowiring tombe ici,
 * explicitement, plutôt qu'en cascade opaque au premier boot de kernel de toute la suite.
 */
#[Group('phase1')]
#[Group('integration')]
final class BeforeSendContainerTest extends KernelTestCase
{
    public function testTheBeforeSendServiceIsWiredAndCallableFromTheContainer(): void
    {
        self::bootKernel();

        // En test, le conteneur spécial expose les services privés : la récupération déclenche
        // l'instanciation RÉELLE avec ses arguments autowirés — c'est l'assertion de câblage.
        // Avec un constructeur dont une dépendance n'est pas fournie, ce `get()` jette un
        // `ArgumentCountError` AVANT toute assertion : la rupture de câblage tombe ici.
        $beforeSend = self::getContainer()->get(BeforeSend::class);
        self::assertInstanceOf(BeforeSend::class, $beforeSend);
    }

    public function testTheWiredFilterStillDropsClientHttpRefusalsAndKeepsRealErrors(): void
    {
        self::bootKernel();

        $beforeSend = self::getContainer()->get(BeforeSend::class);
        self::assertInstanceOf(BeforeSend::class, $beforeSend);

        // 4xx (bruit de scan) : écarté. 5xx / exception ordinaire : conservé. Hors requête (pas de
        // `_club_id`), l'enrichissement club ne touche pas l'EntityManager.
        self::assertNull(
            $beforeSend(Event::createEvent(), EventHint::fromArray(['exception' => new NotFoundHttpException])),
        );

        $kept = Event::createEvent();
        self::assertSame(
            $kept,
            $beforeSend($kept, EventHint::fromArray(['exception' => new RuntimeException('boom')])),
        );
    }
}
