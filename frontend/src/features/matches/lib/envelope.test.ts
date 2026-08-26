import { describe, expect, it } from "vitest";

import type { Fixture, LeagueWindow } from "../api";
import { isInEnvelope, isoWeekday, resolveEnvelope, timeToMinutes } from "./envelope";

const fixture = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03", // Saturday
  homeAway: "HOME",
  opponentLabel: "Adv",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null,  ...over,
});
const window = (over: Partial<LeagueWindow> = {}): LeagueWindow => ({
  id: "w-1",
  league: "AURA",
  category: "U13",
  level: "DEPARTEMENTAL",
  gender: null,
  dayOfWeek: 6, // Saturday
  kickoffMin: "13:00",
  kickoffMax: "18:00",
  ...over,
});

describe("envelope helpers", () => {
  it("timeToMinutes tolerates HH:MM and HH:MM:SS", () => {
    expect(timeToMinutes("13:00")).toBe(780);
    expect(timeToMinutes("18:30:00")).toBe(1110);
  });

  it("isoWeekday returns 6 for Saturday, 7 for Sunday", () => {
    expect(isoWeekday("2026-10-03")).toBe(6);
    expect(isoWeekday("2026-10-04")).toBe(7);
  });
});

// P1-4 PR E2 (dette iv): the team↔window join lives on the SERVER — the client
// only looks up `resolvedTeamWindows`. These tests pin the lookup semantics.
describe("resolveEnvelope", () => {
  it("maps a team via the server-resolved ids and validates day + time", () => {
    const env = resolveEnvelope(fixture(), { "team-1": ["w-1"] }, [window()]);
    expect(env.mapped).toBe(true);
    expect(env.dayOk).toBe(true);
    expect(env.timeOk("14:00")).toBe(true);
    expect(env.timeOk("20:00")).toBe(false); // past kickoffMax
  });

  it("does not map a team the server resolved to [] (unmapped = advisory)", () => {
    const env = resolveEnvelope(fixture(), { "team-1": [] }, [window()]);
    expect(env.mapped).toBe(false);
    expect(env.windows).toHaveLength(0);
  });

  it("does not map a team absent from the server resolution", () => {
    const env = resolveEnvelope(fixture(), {}, [window()]);
    expect(env.mapped).toBe(false);
  });

  it("ignores resolved ids that no longer exist in the catalog items", () => {
    const env = resolveEnvelope(fixture(), { "team-1": ["w-gone"] }, [window()]);
    expect(env.mapped).toBe(false);
  });

  it("flags the wrong day as out of envelope", () => {
    // A Sunday match against a Saturday-only window.
    const env = resolveEnvelope(fixture({ matchDate: "2026-10-04" }), { "team-1": ["w-1"] }, [window()]);
    expect(env.mapped).toBe(true);
    expect(env.dayOk).toBe(false);
  });
});

describe("isInEnvelope", () => {
  it("never blocks an unmapped team (advisory degradation)", () => {
    const env = resolveEnvelope(fixture(), { "team-1": [] }, [window()]);
    expect(env.mapped).toBe(false);
    expect(isInEnvelope(env, "23:00")).toBe(true);
  });

  it("blocks a mapped team placed outside its window", () => {
    const env = resolveEnvelope(fixture(), { "team-1": ["w-1"] }, [window()]);
    expect(isInEnvelope(env, "14:00")).toBe(true);
    expect(isInEnvelope(env, "20:00")).toBe(false);
  });
});
