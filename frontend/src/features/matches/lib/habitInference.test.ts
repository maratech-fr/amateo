import { describe, expect, it } from "vitest";

import type { Fixture, TeamMatchHabit } from "../api";
import { inferHabits } from "./habitInference";

const fixture = (over: Partial<Fixture>): Fixture => ({
  id: Math.random().toString(36).slice(2),
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03", // Saturday
  homeAway: "HOME",
  opponentLabel: "Adv",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: "15:30",
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null,  ...over,
});

/** N Saturday-15:30 fixtures (successive weeks). */
const saturdays = (n: number, over: Partial<Fixture> = {}): Fixture[] =>
  Array.from({ length: n }, (_, i) => {
    const day = 3 + 7 * i;
    const date = new Date(`2026-10-01T12:00:00Z`);
    date.setUTCDate(day);
    return fixture({ matchDate: date.toISOString().slice(0, 10), ...over });
  });

describe("inferHabits (seuils fondateur : ≥ 3 matchs ET ≥ 50 %)", () => {
  it("suggests the majority (weekday, kickoff) group with its count", () => {
    // 6× Saturday 15:30 + 2 outliers → suggested (6 ≥ 3, 6/8 ≥ 50 %).
    const fixtures = [
      ...saturdays(6),
      fixture({ matchDate: "2026-10-04", kickoffTime: "10:30" }), // Sunday
      fixture({ matchDate: "2026-10-11", kickoffTime: "20:30" }),
    ];
    const suggestions = inferHabits(fixtures, []);
    expect(suggestions).toEqual([{ teamId: "team-1", dayOfWeek: 6, kickoffTime: "15:30", venueId: null, count: 6 }]);
  });

  it("stays silent under 3 fixtures or under 50 %", () => {
    expect(inferHabits(saturdays(2), [])).toEqual([]); // < 3
    const scattered = [
      ...saturdays(3),
      fixture({ matchDate: "2026-10-04", kickoffTime: "10:00" }),
      fixture({ matchDate: "2026-10-11", kickoffTime: "11:00" }),
      fixture({ matchDate: "2026-10-18", kickoffTime: "12:00" }),
      fixture({ matchDate: "2026-10-25", kickoffTime: "13:00" }),
    ];
    expect(inferHabits(scattered, [])).toEqual([]); // 3/7 < 50 %
  });

  it("ignores untimed fixtures (00:00 → null) — DF2's 22 blank matches suggest nothing", () => {
    expect(inferHabits(saturdays(22, { kickoffTime: null }), [])).toEqual([]);
  });

  it("never re-suggests a day that already carries a DECLARED habit", () => {
    const declared: TeamMatchHabit[] = [{ id: "h1", teamId: "team-1", dayOfWeek: 6, kickoffTime: "14:00", venueId: null }];
    expect(inferHabits(saturdays(6), declared)).toEqual([]);
  });

  it("attaches the venue shared by ≥ 50 % of the group's placed HOME fixtures", () => {
    const fixtures = [
      ...saturdays(2, { venueId: "venue-mateo", status: "PLACED" }),
      fixture({ matchDate: "2026-11-07", homeAway: "AWAY" }), // Saturdays too
      fixture({ matchDate: "2026-11-14", homeAway: "AWAY" }),
    ];
    const suggestions = inferHabits(fixtures, []);
    expect(suggestions).toHaveLength(1);
    expect(suggestions[0].venueId).toBe("venue-mateo");
    expect(suggestions[0].count).toBe(4);
  });
});
