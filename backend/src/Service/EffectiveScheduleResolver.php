<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\CalendarEntryPeriodType;
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
 * Fermetures de gymnase (C2, capture INVERSÉE) — une FERMETURE (`closure`) sans version ne
 * capture plus sa date : elle ne suspend jamais tout l'entraînement du club. Le planning en
 * vigueur s'applique (période pointée qui gouverne, sinon le socle) et c'est le GYMNASE fermé qui
 * sort (`excludedVenueIds`), date par date, depuis l'union de toutes les fermetures déclarées
 * (`closedDatesByVenue`). La réouverture fait foi : un jour que le plan POINTÉ qui gouverne la date
 * a rouvert (masque OPEN ou mode BLANK) n'exclut pas son gymnase ; sous le socle, la fermeture est
 * un fait brut. Vacances et coupures sans version, elles, capturent toujours (aucun entraînement).
 *
 * Pure/stateless — the caller loads the scoped context (see
 * TrainingCalendarContext) and passes it in.
 */
final class EffectiveScheduleResolver
{
    /**
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null, periodType?: CalendarEntryPeriodType|null, reopenedWeekdaysByVenue?: array<string, array<int, true>>}> $activePeriods
     * @param array<string, array<string, true>>                                                                                                                                                           $closedDatesByVenue venueId → dates fermées (Y-m-d)
     *
     * @return array{scheduleId: string|null, excludedVenueIds: list<string>}
     */
    public function resolve(DateTimeImmutable $date, array $activePeriods, ?string $seasonScheduleId, array $closedDatesByVenue): array
    {
        $narrowest = null;
        foreach ($activePeriods as $period) {
            if ($date < $period['start'] || $date > $period['end']) {
                continue;
            }
            // Une fermeture sans version ne capture pas : le planning en vigueur s'applique.
            if (null === $period['scheduleId'] && CalendarEntryPeriodType::CLOSURE === ($period['periodType'] ?? null)) {
                continue;
            }
            if (null === $narrowest
                || ($period['end']->getTimestamp() - $period['start']->getTimestamp())
                   < ($narrowest['end']->getTimestamp() - $narrowest['start']->getTimestamp())
            ) {
                $narrowest = $period;
            }
        }

        $scheduleId = null !== $narrowest ? $narrowest['scheduleId'] : $seasonScheduleId;
        $day = $date->format('Y-m-d');
        $weekday = (int) $date->format('N');
        $reopened = null !== $narrowest && null !== $narrowest['scheduleId'] ? ($narrowest['reopenedWeekdaysByVenue'] ?? []) : [];
        $excluded = [];
        foreach ($closedDatesByVenue as $venueId => $dates) {
            if (isset($dates[$day]) && !isset($reopened[$venueId][$weekday])) {
                $excluded[] = (string) $venueId;
            }
        }

        return ['scheduleId' => $scheduleId, 'excludedVenueIds' => $excluded];
    }
}
