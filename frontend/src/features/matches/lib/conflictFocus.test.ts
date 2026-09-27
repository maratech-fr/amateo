import { describe, expect, it } from "vitest";

import type { Conflict } from "../api";
import { conflictFixtureIds, findFocusedConflict, isFocusableConflict } from "./conflictFocus";

function side(fixtureId: string) {
  return { fixtureId, teamId: "t", homeAway: "HOME" as const, matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" };
}

describe("conflictFixtureIds", () => {
  it("MATCH_MATCH → les deux fixtureId (left, right), dédoublonnés", () => {
    const c: Conflict = { type: "MATCH_MATCH", severity: 3, resolution: null, coachId: "c1", left: side("fx-1"), right: side("fx-2") };
    expect(conflictFixtureIds(c)).toEqual(["fx-1", "fx-2"]);
  });

  it("MATCH_TRAINING → le seul fixtureId (l'entraînement n'en a pas)", () => {
    const c: Conflict = {
      type: "MATCH_TRAINING",
      severity: 5,
      resolution: null,
      coachId: "c1",
      fixture: side("fx-1"),
      training: { slotTemplateId: "t", scheduleId: "s", teamId: "t2", venueId: "v", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, windowStart: "", windowEnd: "" },
    };
    expect(conflictFixtureIds(c)).toEqual(["fx-1"]);
  });

  it("COMPETITION_INCOMPLETE (aucun côté) → aucun id, non focalisable", () => {
    const c: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, teamId: "t1", competitionId: "comp" };
    expect(conflictFixtureIds(c)).toEqual([]);
    expect(isFocusableConflict(c)).toBe(false);
  });
});

describe("findFocusedConflict", () => {
  const conflicts: Conflict[] = [
    { type: "MATCH_MATCH", severity: 3, resolution: null, coachId: "c1", left: side("fx-1"), right: side("fx-2") },
    { type: "VENUE_OVERLAP", severity: 1, resolution: null, left: side("fx-3"), right: side("fx-4") },
  ];

  it("retrouve le conflit par jeu EXACT de fixtureId, ordre indifférent", () => {
    expect(findFocusedConflict(conflicts, ["fx-2", "fx-1"])).toBe(conflicts[0]);
    expect(findFocusedConflict(conflicts, ["fx-3", "fx-4"])).toBe(conflicts[1]);
  });

  it("jeu vide ou sans correspondance → null", () => {
    expect(findFocusedConflict(conflicts, [])).toBeNull();
    expect(findFocusedConflict(conflicts, ["fx-9"])).toBeNull();
    // Un sur-ensemble/sous-ensemble ne matche pas (match exact).
    expect(findFocusedConflict(conflicts, ["fx-1"])).toBeNull();
  });
});
