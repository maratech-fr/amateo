<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\TravelComputeScope;
use App\Service\TravelProgressPublisher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * C6 — le publieur de progression du calcul des trajets : sur le topic FIXE
 * `club:{clubId}:travel`, PRIVÉ (le claim d'abonnement est la frontière), avec la
 * charge `{scope, done, total, terminal, verdict?}`.
 */
#[Group('unit')]
final class TravelProgressPublisherTest extends TestCase
{
    public function testPublishesPrivateProgressAndTerminalWithVerdictOnTheClubTravelTopic(): void
    {
        /** @var list<Update> $captured */
        $captured = [];
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$captured): string {
            $captured[] = $update;

            return 'id';
        });

        $publisher = new TravelProgressPublisher($hub);
        $publisher->publishProgress('club-1', TravelComputeScope::OPPONENTS, 5, 20);
        $publisher->publishTerminal('club-1', TravelComputeScope::VENUE_MATRIX, 20, 20, ['filled' => 3, 'unresolved' => []]);

        self::assertCount(2, $captured);

        // Progression : topic fixe, privé, terminal false, pas de verdict.
        self::assertSame(['club:club-1:travel'], $captured[0]->getTopics());
        self::assertTrue($captured[0]->isPrivate(), 'un update de trajet est TOUJOURS privé');
        $progress = json_decode($captured[0]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['scope' => 'OPPONENTS', 'done' => 5, 'total' => 20, 'terminal' => false], $progress);

        // Terminal : verdict de la matrice joint.
        $terminal = json_decode($captured[1]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($terminal['terminal']);
        self::assertSame(['filled' => 3, 'unresolved' => []], $terminal['verdict']);
    }

    public function testAnEmptyClubIdNeverPublishes(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        new TravelProgressPublisher($hub)->publishTerminal('', TravelComputeScope::OPPONENTS, 0, 0, null);
    }
}
