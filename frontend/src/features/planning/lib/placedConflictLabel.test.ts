import { describe, expect, it } from "vitest";

import type { PlacedConflict } from "../api";
import { placedConflictLabel } from "./placedConflictLabel";

const conflict = (over: Partial<PlacedConflict> = {}): PlacedConflict => ({
  personId: "p1",
  personName: "Anna Dupont",
  dayOfWeek: 2,
  first: { teamId: "tA", teamName: "U13F", venueId: "vB", venueName: "Gymnase B", startTime: "18h00" },
  second: { teamId: "tB", teamName: "U11M1", venueId: "vA", venueName: "Gymnase A", startTime: "18h00" },
  ...over,
});

describe("placedConflictLabel", () => {
  it("compose le gabarit fondateur : personne, jour, heure, les deux équipes et gymnases", () => {
    expect(placedConflictLabel(conflict())).toBe(
      "Anna Dupont est à deux endroits le mardi à 18h00 (U13F · Gymnase B / U11M1 · Gymnase A)",
    );
  });

  it("nomme le jour ISO en français", () => {
    expect(placedConflictLabel(conflict({ dayOfWeek: 6 }))).toContain("le samedi");
  });
});
