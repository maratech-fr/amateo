<?php

declare(strict_types=1);

namespace App\Service;

final class DiagnosticMessageBuilder
{
    /** ISO day-of-week (1=Monday..7=Sunday) → French, matching the backend/frontend convention. */
    private const DAY_NAMES = [
        1 => 'lundi',
        2 => 'mardi',
        3 => 'mercredi',
        4 => 'jeudi',
        5 => 'vendredi',
        6 => 'samedi',
        7 => 'dimanche',
    ];

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames  teamId => name
     * @param array<string, string> $coachNames coachId => name
     * @param array<string, string> $venueNames venueId => name
     */
    public function build(
        array $diagnostic,
        array $teamNames = [],
        array $coachNames = [],
        array $venueNames = [],
    ): string {
        $type = (string) ($diagnostic['type'] ?? '');
        $engineMessage = trim((string) ($diagnostic['message'] ?? ''));

        // The engine already emits precise, manager-ready French messages that
        // name the teams / venue / coach + day + time + reason (who/when/why).
        // Prefer them; the localized builders below are only a fallback for
        // payloads that arrive without a rich message. soft_lock_moved and
        // unused_slot are the exceptions: the engine still sends a raw English
        // message for those, so we always rebuild them locally (unused_slot also
        // fixes the engine's Sunday day-name mislabel — it maps day 0=Sunday
        // while the payload's dayOfWeek is ISO 1=Monday..7=Sunday).
        return match ($type) {
            'unplaced' => '' !== $engineMessage ? $engineMessage : $this->buildUnplaced($diagnostic, $teamNames),
            'conflict' => '' !== $engineMessage ? $engineMessage : $this->buildConflict($diagnostic, $teamNames, $coachNames, $venueNames),
            'coach_overload' => '' !== $engineMessage ? $engineMessage : $this->buildCoachOverload($diagnostic, $coachNames),
            'soft_lock_moved' => $this->buildSoftLockMoved($diagnostic, $teamNames, $venueNames),
            'unused_slot' => $this->buildUnusedSlot($diagnostic, $venueNames),
            default => '' !== $engineMessage ? $engineMessage : 'Diagnostic inconnu.',
        };
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     */
    private function buildUnplaced(array $diagnostic, array $teamNames): string
    {
        $teamId = $this->extractId($diagnostic, 'teamId', 'team_id');
        $teamName = null !== $teamId ? ($teamNames[$teamId] ?? $teamId) : 'L\'équipe';

        return \sprintf(
            '%s n\'a pas pu être placée dans le planning : aucun créneau ne correspondait à ses contraintes.',
            $teamName,
        );
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     * @param array<string, string> $coachNames
     * @param array<string, string> $venueNames
     */
    private function buildConflict(
        array $diagnostic,
        array $teamNames,
        array $coachNames,
        array $venueNames,
    ): string {
        $venueId = $this->extractId($diagnostic, 'venueId', 'venue_id');
        $coachId = $this->extractId($diagnostic, 'coachId', 'coach_id');

        if (null !== $venueId) {
            $venueName = $venueNames[$venueId] ?? $venueId;

            return \sprintf(
                '%s accueille plusieurs équipes simultanément. Veuillez déplacer l\'une des séances.',
                $venueName,
            );
        }

        if (null !== $coachId) {
            $coachName = $coachNames[$coachId] ?? $coachId;

            return \sprintf(
                '%s est assigné(e) à plusieurs équipes simultanément. Veuillez réattribuer l\'une des séances.',
                $coachName,
            );
        }

        return 'Le planning n\'a pas pu être généré : les contraintes actuelles sont incompatibles.';
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $coachNames
     */
    private function buildCoachOverload(array $diagnostic, array $coachNames): string
    {
        $coachId = $this->extractId($diagnostic, 'coachId', 'coach_id');
        $coachName = null !== $coachId ? ($coachNames[$coachId] ?? $coachId) : 'L\'entraîneur';
        $count = (int) ($diagnostic['count'] ?? 0);
        $threshold = (int) ($diagnostic['threshold'] ?? 0);

        if ($count > 0 && $threshold > 0) {
            return \sprintf(
                '%s est surchargé(e) avec %d séances (limite recommandée : %d).',
                $coachName,
                $count,
                $threshold,
            );
        }

        return \sprintf(
            '%s est surchargé(e). Veuillez réduire son nombre de séances.',
            $coachName,
        );
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $teamNames
     * @param array<string, string> $venueNames
     */
    private function buildSoftLockMoved(array $diagnostic, array $teamNames, array $venueNames): string
    {
        $teamId = $this->extractId($diagnostic, 'teamId', 'team_id');
        $teamName = null !== $teamId ? ($teamNames[$teamId] ?? $teamId) : 'L\'équipe';
        $venueId = $this->extractId($diagnostic, 'venueId', 'venue_id');
        $venueName = null !== $venueId ? ($venueNames[$venueId] ?? $venueId) : null;

        if (null !== $venueName) {
            return \sprintf(
                'Le créneau préféré de %s (%s) a été déplacé par le solveur pour un meilleur ajustement global.',
                $teamName,
                $venueName,
            );
        }

        return \sprintf(
            'Le créneau préféré de %s a été déplacé par le solveur pour un meilleur ajustement global.',
            $teamName,
        );
    }

    /**
     * @param array<string, mixed>  $diagnostic
     * @param array<string, string> $venueNames
     */
    private function buildUnusedSlot(array $diagnostic, array $venueNames): string
    {
        $venueId = $this->extractId($diagnostic, 'venueId', 'venue_id');
        $venueName = null !== $venueId ? ($venueNames[$venueId] ?? $venueId) : 'Un gymnase';

        $dayOfWeek = (int) ($diagnostic['dayOfWeek'] ?? $diagnostic['day_of_week'] ?? 0);
        $day = self::DAY_NAMES[$dayOfWeek] ?? '';
        $start = $this->hhmm((string) ($diagnostic['startTime'] ?? $diagnostic['start_time'] ?? ''));
        $duration = (int) ($diagnostic['durationMinutes'] ?? $diagnostic['duration_minutes'] ?? 0);
        $end = '' !== $start && $duration > 0 ? $this->addMinutes($start, $duration) : '';

        $slot = '';
        if ('' !== $start) {
            $slot = '' !== $end ? \sprintf('de %s à %s', $start, $end) : \sprintf('à %s', $start);
        }
        $when = trim($day . ' ' . $slot);

        return \sprintf(
            'Créneau disponible non utilisé : %s%s — aucune équipe n\'y est placée.',
            $venueName,
            '' !== $when ? \sprintf(' (%s)', $when) : '',
        );
    }

    private function hhmm(string $time): string
    {
        return 1 === preg_match('/(\d{2}):(\d{2})/', $time, $matches) ? $matches[0] : $time;
    }

    private function addMinutes(string $hhmm, int $minutes): string
    {
        if (1 !== preg_match('/^(\d{2}):(\d{2})/', $hhmm, $matches)) {
            return '';
        }

        $total = (((int) $matches[1]) * 60 + (int) $matches[2] + $minutes) % (24 * 60);

        return \sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /** @param array<string, mixed> $diagnostic */
    private function extractId(array $diagnostic, string $camelKey, string $snakeKey): ?string
    {
        if (isset($diagnostic[$camelKey])) {
            return (string) $diagnostic[$camelKey];
        }

        if (isset($diagnostic[$snakeKey])) {
            return (string) $diagnostic[$snakeKey];
        }

        return null;
    }
}
