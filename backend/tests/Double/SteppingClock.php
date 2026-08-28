<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\Geo\IgnRoutingClient;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;

/**
 * A clock whose {@see now} advances by a fixed step on EVERY call — the seam that
 * makes {@see IgnRoutingClient::travelMinutesBatch}'s global budget
 * testable without a real wait. The first read is the base instant (so the batch
 * captures its deadline), and each subsequent read jumps forward by the step, so a
 * large step forces the budget to expire after the first dispatched window.
 */
final class SteppingClock implements ClockInterface
{
    private DateTimeImmutable $current;

    public function __construct(?DateTimeImmutable $start = null, private readonly int $stepSeconds = 100)
    {
        $this->current = $start ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        $now = $this->current;
        $this->current = $this->current->modify(\sprintf('+%d seconds', $this->stepSeconds));

        return $now;
    }

    public function sleep(float|int $seconds): void
    {
        $this->current = $this->current->modify(\sprintf('+%d seconds', (int) ceil((float) $seconds)));
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return $this;
    }
}
