import type { Slot, VenueTrainingSlot } from "../api";

import { parseTimeToMinutes } from "./grid";

/** Synthetic-slot id prefix marking a defined-but-unfilled venue window. */
export const EMPTY_SLOT_PREFIX = "empty:";

export const isEmptySlotId = (slotId: string): boolean => slotId.startsWith(EMPTY_SLOT_PREFIX);

/** Two half-open minute intervals [aStart, aEnd) and [bStart, bEnd) intersect. */
const overlaps = (aStart: number, aEnd: number, bStart: number, bEnd: number): boolean => aStart < bEnd && bStart < aEnd;

/**
 * Availability windows the solver placed NO team on ("créneaux vides"). Returned
 * as synthetic Slots (teamId "") so the existing venue-view grid machinery lays
 * them out as `vide` cells — WeekGrid renders them muted, DiagnosticsPanel lists
 * them as warnings. A window counts as FILLED as soon as at least one placement on
 * the same venue + day OVERLAPS it in time ([start, end) intervals that intersect),
 * not only one that shares its exact start. A period grid can be retailored AFTER
 * generation, so a LOCKED session keeps a start that no longer coincides with the
 * window's (prod BCCL : fenêtre 17:30-18:45, séance verrouillée 17:15-18:45) — the
 * old exact-start match drew a spurious half-width `vide` cell beside it. Capacity
 * is not split: one overlap is enough (MVP).
 *
 * MIROIR DÉCLARÉ (régime 2 de `.claude/rules/frontend.md`) : la MÊME règle vit côté backend dans
 * `App\Service\VenueWindowOccupancy::windowIsFilled` (la case « vide » de l'export PDF/XLSX). Parité
 * mécanique sur `emptySlots.parity.json` : `emptySlots.parity.test.ts` ⇄ `EmptySlotOverlapMirrorParityTest.php`,
 * inscrit au registre `FrontRederivationRegistryTest`. Ne pas modifier la règle d'un seul côté sans
 * porter le cas partagé.
 */
export function computeEmptySlots(trainingSlots: VenueTrainingSlot[], slots: Slot[], scheduleId: string): Slot[] {
  return trainingSlots
    .filter((ts) => {
      const windowStart = parseTimeToMinutes(ts.startTime);
      const windowEnd = windowStart + ts.durationMinutes;
      return !slots.some((s) => {
        if (s.venueId !== ts.venueId || s.dayOfWeek !== ts.dayOfWeek) {
          return false;
        }
        const slotStart = parseTimeToMinutes(s.startTime);
        return overlaps(slotStart, slotStart + s.durationMinutes, windowStart, windowEnd);
      });
    })
    .map((ts) => ({
      id: `${EMPTY_SLOT_PREFIX}${ts.id}`,
      scheduleId,
      teamId: "",
      venueId: ts.venueId,
      coachId: null,
      dayOfWeek: ts.dayOfWeek,
      startTime: ts.startTime,
      durationMinutes: ts.durationMinutes,
      lockLevel: "NONE" as const,
      lockOrigin: null,
      sharedTrainingBlockId: null,
    }));
}
