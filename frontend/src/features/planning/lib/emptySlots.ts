import type { Slot, VenueTrainingSlot } from "../api";

import { parseTimeToMinutes } from "./grid";

/** Synthetic-slot id prefix marking a defined-but-unfilled venue window. */
export const EMPTY_SLOT_PREFIX = "empty:";

export const isEmptySlotId = (slotId: string): boolean => slotId.startsWith(EMPTY_SLOT_PREFIX);

const windowKey = (venueId: string, dayOfWeek: number, startTime: string): string => `${venueId}|${dayOfWeek}|${parseTimeToMinutes(startTime)}`;

/**
 * Availability windows the solver placed NO team on ("créneaux vides"). Returned
 * as synthetic Slots (teamId "") so the existing venue-view grid machinery lays
 * them out as `vide` cells — WeekGrid renders them muted, DiagnosticsPanel lists
 * them as warnings. Matching is by venue + day + start (minute-normalised so a
 * "18:00" placement matches an "18:00:00" window). Capacity is not split: a
 * window with at least one placement counts as filled (MVP).
 */
export function computeEmptySlots(trainingSlots: VenueTrainingSlot[], slots: Slot[], scheduleId: string): Slot[] {
  const filled = new Set(slots.map((s) => windowKey(s.venueId, s.dayOfWeek, s.startTime)));
  return trainingSlots
    .filter((ts) => !filled.has(windowKey(ts.venueId, ts.dayOfWeek, ts.startTime)))
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
