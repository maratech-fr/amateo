<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\Service\ScheduleProgressPublisher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * SEC-06 (A14): schedule-generation progress is published on a club-scoped topic
 * (/clubs/{clubId}/schedules/{id}). Those updates MUST be Mercure-private so the
 * subscriber JWT's topic authorization is enforced — a public update bypasses
 * per-topic scoping and would leak one club's status to any holder of a valid
 * subscriber token. MercureHardeningTest guards the hub config; this guards the
 * publish flag itself.
 */
#[Group('phase1')]
final class MercurePrivateUpdateTest extends TestCase
{
    public function testScheduleProgressIsPublishedAsPrivateOnAClubScopedTopic(): void
    {
        $captured = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$captured): string {
            $captured = $update;

            return '';
        });

        $schedule = new Schedule;
        $schedule->setClubId('11111111-1111-4111-8111-111111111111');
        $schedule->setStatus(ScheduleStatus::COMPLETED);

        new ScheduleProgressPublisher($hub)->publish($schedule, ['warnings' => []]);

        self::assertInstanceOf(Update::class, $captured);
        self::assertTrue($captured->isPrivate(), 'Schedule progress updates must be Mercure-private (SEC-06).');
        self::assertStringStartsWith('/clubs/', $captured->getTopics()[0]);
    }

    public function testTerminalFailureIsAlsoPrivate(): void
    {
        $captured = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$captured): string {
            $captured = $update;

            return '';
        });

        new ScheduleProgressPublisher($hub)->publishTerminalFailure(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'boom',
        );

        self::assertInstanceOf(Update::class, $captured);
        self::assertTrue($captured->isPrivate(), 'Terminal-failure updates must be Mercure-private (SEC-06).');
    }
}
