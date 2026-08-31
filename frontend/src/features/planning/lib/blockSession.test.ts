import { describe, expect, it } from "vitest";

import { type CoLocatableSlot, blocksAtCase, blocksForSlot } from "./blockSession";

const slot = (teamId: string, venueId: string, dayOfWeek: number, startTime: string): CoLocatableSlot => ({ teamId, venueId, dayOfWeek, startTime });

describe("blockSession — dérivation FAIL-SAFE des séances de bloc (P2-51 PR-6)", () => {
  const block = { id: "b1", teamIds: ["t1", "t2", "t3"] };

  it("un bloc est présent sur la case quand TOUS ses membres y siègent", () => {
    const slots = [slot("t1", "v1", 3, "17:30"), slot("t2", "v1", 3, "17:30"), slot("t3", "v1", 3, "17:30")];
    expect(blocksAtCase([block], slots, { venueId: "v1", dayOfWeek: 3, startTime: "17:30" }).map((b) => b.id)).toEqual(["b1"]);
  });

  it("un membre ABSENT de la case → le bloc n'y est PAS présent (co-localisation incomplète)", () => {
    // t3 est ailleurs : le bloc n'est pas reconstitué sur cette case.
    const slots = [slot("t1", "v1", 3, "17:30"), slot("t2", "v1", 3, "17:30"), slot("t3", "v2", 4, "19:00")];
    expect(blocksAtCase([block], slots, { venueId: "v1", dayOfWeek: 3, startTime: "17:30" })).toEqual([]);
  });

  it("normalise l'heure (les créneaux peuvent porter « 17:30:00 » / un ISO)", () => {
    const slots = [slot("t1", "v1", 3, "1970-01-01T17:30:00+00:00"), slot("t2", "v1", 3, "17:30:00"), slot("t3", "v1", 3, "17:30")];
    expect(blocksAtCase([block], slots, { venueId: "v1", dayOfWeek: 3, startTime: "17:30" }).map((b) => b.id)).toEqual(["b1"]);
  });

  it("blocksForSlot ne retient que les blocs CONTENANT l'équipe du créneau", () => {
    const other = { id: "b2", teamIds: ["t4", "t5"] };
    const slots = [slot("t1", "v1", 3, "17:30"), slot("t2", "v1", 3, "17:30"), slot("t3", "v1", 3, "17:30"), slot("t4", "v1", 3, "17:30"), slot("t5", "v1", 3, "17:30")];
    // Les deux blocs sont co-localisés sur la case, mais depuis le créneau de t1 seul b1 le concerne.
    expect(blocksForSlot(slot("t1", "v1", 3, "17:30"), [block, other], slots).map((b) => b.id)).toEqual(["b1"]);
  });

  it("blocksForSlot est vide sur un créneau ordinaire (aucun bloc)", () => {
    const slots = [slot("t1", "v1", 3, "17:30"), slot("t2", "v2", 4, "19:00")];
    expect(blocksForSlot(slot("t1", "v1", 3, "17:30"), [block], slots)).toEqual([]);
  });
});
