<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\Geo\IgnRoutingClient;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;

/**
 * A clock frozen at one instant whose {@see sleep} is a NO-OP — the seam that
 * NEUTRALISES {@see IgnRoutingClient}'s 1-req/s pacing in a test that
 * wants to exercise something ELSE (e.g. the resolver's hard opponent cap) without the
 * paced sleeps eating the wall-clock budget. `now()` never moves and `sleep()` does
 * nothing, so the batch budget never bites and every paced call runs.
 *
 * Contrast with {@see SteppingClock}, whose reads/sleeps DO advance — used precisely
 * when a test wants the budget to bite.
 */
final class FrozenClock implements ClockInterface
{
    private readonly DateTimeImmutable $instant;

    public function __construct(?DateTimeImmutable $instant = null)
    {
        $this->instant = $instant ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function sleep(float|int $seconds): void
    {
        // No-op: the whole point is to disable the paced wait in tests.
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return $this;
    }
}
