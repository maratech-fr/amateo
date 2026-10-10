import { describe, expect, it } from "vitest";

import type { Slot, VenueTrainingSlot } from "../api";

import { computeEmptySlots, EMPTY_SLOT_PREFIX, isEmptySlotId } from "./emptySlots";

const ts = (o: Partial<VenueTrainingSlot>): VenueTrainingSlot => ({ id: "ts", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1, ...o });
const slot = (o: Partial<Slot>): Slot => ({ id: "s", scheduleId: "sc", teamId: "t1", venueId: "v1", coachId: null, dayOfWeek: 1, startTime: "18:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null, sharedTrainingBlockId: null, ...o });

describe("computeEmptySlots", () => {
  it("returns only the windows with no placement, as synthetic empty slots", () => {
    const training = [ts({ id: "filled", dayOfWeek: 1, startTime: "18:00:00" }), ts({ id: "empty", dayOfWeek: 2, startTime: "19:00:00" })];
    const placements = [slot({ venueId: "v1", dayOfWeek: 1, startTime: "18:00" })]; // matches "filled"

    const result = computeEmptySlots(training, placements, "sc");

    expect(result).toHaveLength(1);
    expect(result[0].id).toBe(`${EMPTY_SLOT_PREFIX}empty`);
    expect(result[0].teamId).toBe("");
    expect(result[0]).toMatchObject({ venueId: "v1", dayOfWeek: 2, startTime: "19:00:00", scheduleId: "sc" });
    expect(isEmptySlotId(result[0].id)).toBe(true);
  });

  it("matches placement and window regardless of HH:MM vs HH:MM:SS format", () => {
    const training = [ts({ id: "w", startTime: "18:00:00" })];
    const placements = [slot({ startTime: "18:00" })];

    expect(computeEmptySlots(training, placements, "sc")).toHaveLength(0);
  });

  it("treats a window as filled if at least one team is placed (capacity not split)", () => {
    const training = [ts({ id: "w", venueId: "v1", dayOfWeek: 3, startTime: "20:00:00", capacity: 2 })];
    const placements = [slot({ venueId: "v1", dayOfWeek: 3, startTime: "20:00", teamId: "t1" })];

    expect(computeEmptySlots(training, placements, "sc")).toHaveLength(0);
  });
});
