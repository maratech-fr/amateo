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

  // Bug prod BCCL (lot 3a) : la grille d'une période est RETAILLÉE après la génération, donc une
  // séance VERROUILLÉE garde un début qui ne coïncide plus avec le début de la fenêtre. Un créneau
  // compte comme occupé dès qu'une séance du même gymnase+jour le CHEVAUCHE, pas seulement si elle
  // partage son début exact.
  it("treats a window as filled when a placement OVERLAPS it (retailored window, locked session)", () => {
    // Cas prod exact : fenêtre 17:30 (75 min → 17:30-18:45), séance verrouillée 17:15 (90 min → 17:15-18:45).
    const training = [ts({ id: "w", venueId: "v1", dayOfWeek: 3, startTime: "17:30:00", durationMinutes: 75 })];
    const placements = [slot({ venueId: "v1", dayOfWeek: 3, startTime: "17:15", durationMinutes: 90 })];

    expect(computeEmptySlots(training, placements, "sc")).toHaveLength(0);
  });

  it("keeps the window empty for a CONTIGUOUS placement that does not overlap ([start, end) half-open)", () => {
    // Séance 16:00-17:30 (90 min) FINIT quand la fenêtre 17:30 COMMENCE : pas de chevauchement → VIDE gardé.
    const training = [ts({ id: "w", venueId: "v1", dayOfWeek: 3, startTime: "17:30:00", durationMinutes: 90 })];
    const placements = [slot({ venueId: "v1", dayOfWeek: 3, startTime: "16:00", durationMinutes: 90 })];

    const result = computeEmptySlots(training, placements, "sc");
    expect(result).toHaveLength(1);
    expect(result[0].id).toBe(`${EMPTY_SLOT_PREFIX}w`);
  });

  it("keeps the window empty when the overlapping placement is in another venue or another day", () => {
    const training = [ts({ id: "w", venueId: "v1", dayOfWeek: 3, startTime: "17:30:00", durationMinutes: 75 })];
    // Même heure chevauchante mais autre gymnase ET autre jour : la fenêtre v1/mercredi reste vide.
    const placements = [slot({ venueId: "v2", dayOfWeek: 3, startTime: "17:15", durationMinutes: 90 }), slot({ venueId: "v1", dayOfWeek: 4, startTime: "17:15", durationMinutes: 90 })];

    expect(computeEmptySlots(training, placements, "sc")).toHaveLength(1);
  });

  it("treats a window as filled on a PARTIAL overlap at the END of the window", () => {
    // Fenêtre 17:30-19:00 (90 min), séance 18:45-19:45 (60 min) : chevauchement 18:45-19:00 → occupé.
    const training = [ts({ id: "w", venueId: "v1", dayOfWeek: 3, startTime: "17:30:00", durationMinutes: 90 })];
    const placements = [slot({ venueId: "v1", dayOfWeek: 3, startTime: "18:45", durationMinutes: 60 })];

    expect(computeEmptySlots(training, placements, "sc")).toHaveLength(0);
  });
});
