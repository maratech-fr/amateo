<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Mercure\ClubTopicUpdate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SEC-06 (A14): every club-scoped Mercure publish routes through
 * ClubTopicUpdate::private(), so guarding this factory guards ALL publish sites
 * at once (schedule progress, terminal/generation failure, reconcile, PDF
 * export) — the private flag can no longer be dropped at one call site.
 */
#[Group('phase1')]
final class ClubTopicUpdateTest extends TestCase
{
    public function testBuildsAPrivateUpdateOnAClubTopic(): void
    {
        $update = ClubTopicUpdate::private('/clubs/abc/schedules/def', '{"status":"COMPLETED"}');

        self::assertTrue($update->isPrivate(), 'Club-scoped updates must be Mercure-private.');
        self::assertSame(['/clubs/abc/schedules/def'], $update->getTopics());
    }

    public function testRefusesANonClubTopic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClubTopicUpdate::private('public:broadcast', '{}');
    }
}
