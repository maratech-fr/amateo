<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\Geo\IgnRoutingClient;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps every record in memory, for tests that assert on what was
 * logged (levels, messages, context) — e.g. {@see IgnRoutingClient}'s
 * rate-limit warnings.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
