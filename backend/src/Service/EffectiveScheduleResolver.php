<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * THE rule « which training calendar applies on a given date » (ADR-0002),
 * extracted from MatchConflictDetector so every consumer shares one truth
 * (P1-4 PR B: the venue-unavailability impact needs it too — two copies of a
 * capture rule inevitably diverge, §7.2.1).
 *
 * An active period covering the date CAPTURES it — its overlay (may be null →
 * no training at all, a closure) wins. Several periods may cover the same date
 * (a root closure split into début·milieu·fin, the root and a child sharing a
 * boundary): the NARROWEST one wins (smallest end − start), because it is the
 * most specific statement about that date; ties keep the first in the list.
 * A narrower child pointing at nothing (scheduleId null) still wins over a
 * wider pointed period — the cut SUSPENDS training there, it never falls back
 * to the broader calendar. Outside every period, the season's own calendar
 * (the version its plan points at) applies.
 *
 * Order-independence is deliberate: period ids are random UUIDv4, so the list
 * order (CalendarEntryRepository, ORDER BY startDate, id) cannot be relied on
 * to put a child before its root — the narrowest-wins rule is what makes the
 * result deterministic (P4-188).
 *
 * Pure/stateless — the caller loads the scoped context (see
 * TrainingCalendarContext) and passes it in.
 */
final class EffectiveScheduleResolver
{
    /**
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     */
    public function resolve(DateTimeImmutable $date, array $activePeriods, ?string $seasonScheduleId): ?string
    {
        $narrowest = null;
        foreach ($activePeriods as $period) {
            if ($date < $period['start'] || $date > $period['end']) {
                continue;
            }
            if (null === $narrowest
                || ($period['end']->getTimestamp() - $period['start']->getTimestamp())
                   < ($narrowest['end']->getTimestamp() - $narrowest['start']->getTimestamp())
            ) {
                $narrowest = $period;
            }
        }

        return null !== $narrowest ? $narrowest['scheduleId'] : $seasonScheduleId;
    }
}
