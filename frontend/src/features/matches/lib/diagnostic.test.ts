import { describe, expect, it } from "vitest";

import type { Conflict } from "../api";
import { groupBySeverity, toneOf } from "./diagnostic";

const conflict = (type: Conflict["type"], severity: number): Conflict => ({ type, severity, resolution: null });

describe("groupBySeverity (P1-4 PR E2)", () => {
  it("sorts groups worst-first and keeps the server's severity as-is", () => {
    const groups = groupBySeverity([
      conflict("AWAY_NO_FOOTPRINT", 7),
      conflict("MATCH_MATCH", 3),
      conflict("VENUE_OVERLAP", 1),
      conflict("MATCH_MATCH", 3),
    ]);
    expect(groups.map((g) => g.severity)).toEqual([1, 3, 7]);
    expect(groups[1]?.conflicts).toHaveLength(2);
  });

  it("grades the tone: 1-2 destructive, 3-5 warning, 7 muted", () => {
    const groups = groupBySeverity([
      conflict("VENUE_OVERLAP", 1),
      conflict("LEAGUE_WINDOW_VIOLATION", 2),
      conflict("VENUE_UNAVAILABLE", 4),
      conflict("AWAY_NO_FOOTPRINT", 7),
    ]);
    expect(groups.map((g) => g.tone)).toEqual(["destructive", "destructive", "warning", "muted"]);
  });

  it("folds the structural groups 6 and 7 (N teams = N lines max, not N alerts)", () => {
    const groups = groupBySeverity([conflict("AWAY_NO_FOOTPRINT", 7), conflict("COMPETITION_INCOMPLETE", 6), conflict("VENUE_OVERLAP", 1)]);
    expect(groups.find((g) => 7 === g.severity)?.folded).toBe(true);
    expect(groups.find((g) => 6 === g.severity)?.folded).toBe(true);
    expect(groups.find((g) => 6 === g.severity)?.title).toBe("Calendriers incomplets");
    expect(groups.find((g) => 1 === g.severity)?.folded).toBe(false);
  });

  it("a conflict without severity falls in the watch group (5), never crashes", () => {
    const legacy = { type: "MATCH_MATCH" } as Conflict;
    expect(groupBySeverity([legacy])[0]?.severity).toBe(5);
  });
});

describe("toneOf (exporté pour l'onglet Conflits)", () => {
  it("grade la tonalité : 1-2 destructive, 3-5 warning, 6-7 muted", () => {
    expect(toneOf(1)).toBe("destructive");
    expect(toneOf(2)).toBe("destructive");
    expect(toneOf(3)).toBe("warning");
    expect(toneOf(5)).toBe("warning");
    expect(toneOf(6)).toBe("muted");
    expect(toneOf(7)).toBe("muted");
  });
});
