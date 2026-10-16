<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Mercure\ClubTopicUpdate;
use App\Mercure\MercureTopic;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Throwable;

/**
 * Publishes schedule-generation progress on the Mercure topic
 * `/clubs/{clubId}/schedules/{scheduleId}` (BCK-04: extracted from
 * GenerateScheduleHandler). Mercure is best-effort — the frontend polls as a
 * fallback — so publishSafely() never lets a publish failure abort or undo a
 * persisted result.
 */
final class ScheduleProgressPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @param array<string, mixed> $result */
    public function publish(Schedule $schedule, array $result): void
    {
        $topic = MercureTopic::for($schedule->getClubId(), $schedule->getId());
        if (MercureTopic::isEmpty($topic)) {
            throw new LogicException('Schedule Mercure topic cannot be empty.');
        }

        $this->hub->publish(ClubTopicUpdate::private($topic, json_encode([
            // FRT-04 : le front s'abonne au TEMPLATE du club (un seul EventSource pour
            // toutes les générations) — l'événement doit donc nommer son planning.
            'scheduleId' => $schedule->getId(),
            'status' => $schedule->getStatus(),
            'score' => $schedule->getScore(),
            'unplaced' => $this->countUnplacedTeams($result),
            'warnings' => array_values(\is_array($result['warnings'] ?? null) ? $result['warnings'] : []),
        ], \JSON_THROW_ON_ERROR)));
    }

    /**
     * Best-effort publish: a missed event self-heals on the next poll, so a
     * publish failure is logged and swallowed, never propagated.
     *
     * @param array<string, mixed> $result
     */
    public function publishSafely(Schedule $schedule, array $result): void
    {
        try {
            $this->publish($schedule, $result);
        } catch (Throwable $exception) {
            $this->logger?->warning('Mercure publish failed (best-effort)', [
                'scheduleId' => $schedule->getId(),
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Terminal-failure event for a schedule we cannot load (deleted / not found)
     * — the Schedule object is unavailable, so the topic is built from the raw ids.
     */
    public function publishTerminalFailure(string $clubId, string $scheduleId, string $error): void
    {
        $this->hub->publish(ClubTopicUpdate::private(
            MercureTopic::for($clubId, $scheduleId),
            json_encode(['scheduleId' => $scheduleId, 'status' => 'failed', 'error' => $error], \JSON_THROW_ON_ERROR),
        ));
    }

    /** @param array<string, mixed> $result */
    private function countUnplacedTeams(array $result): int
    {
        $unplaced = $result['unplaced'] ?? null;
        if (\is_array($unplaced)) {
            return \count($unplaced);
        }

        $diagnostics = $result['diagnostics'] ?? null;
        if (!\is_array($diagnostics)) {
            return 0;
        }

        $count = 0;
        foreach ($diagnostics as $diagnostic) {
            if (\is_array($diagnostic) && 'unplaced' === ($diagnostic['type'] ?? null)) {
                ++$count;
            }
        }

        return $count;
    }
}
