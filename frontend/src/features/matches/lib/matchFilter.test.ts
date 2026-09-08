import { describe, expect, it } from "vitest";

import type { CoachPlayerMembership, TeamCoach } from "@/features/planning/api";

import type { Conflict, Fixture } from "../api";
import { applyMatchFilter, expandCoachTeams } from "./matchFilter";

function fixture(over: Partial<Fixture> & Pick<Fixture, "id" | "teamId">): Fixture {
  return {
    seasonId: "s",
    competitionId: null,
    matchDate: "2026-10-04",
    homeAway: "HOME",
    opponentLabel: "Adv",
    status: "PLACED",
    venueId: "venue-1",
    kickoffTime: "16:00",
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: null,
    unplacedReason: null,
    reviewState: "NEW" as const,
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null,
    ...over,
  };
}

const teamCoaches: TeamCoach[] = [
  { id: "tc1", teamId: "sm1", coachId: "thomas", role: "ASSISTANT" },
  { id: "tc2", teamId: "u15m1", coachId: "thomas", role: "MAIN" },
  { id: "tc3", teamId: "u13", coachId: "jean", role: "MAIN" },
];
const coachPlayers: CoachPlayerMembership[] = [{ id: "cp1", teamId: "u18", coachId: "thomas", isActive: true }, { id: "cp2", teamId: "u20", coachId: "thomas", isActive: false }];

describe("expandCoachTeams", () => {
  it("map une équipe par rôle : principal, assistant, joueur ACTIF ; ignore les inactifs", () => {
    const roles = expandCoachTeams(["thomas"], teamCoaches, coachPlayers);
    expect(roles.get("u15m1")).toBe("principal");
    expect(roles.get("sm1")).toBe("assistant");
    expect(roles.get("u18")).toBe("joueur");
    expect(roles.has("u20")).toBe(false); // isActive false
    expect(roles.has("u13")).toBe(false); // coach jean, pas thomas
  });

  it("garde le MEILLEUR rôle quand un coach cumule (principal > assistant > joueur)", () => {
    const also: TeamCoach[] = [...teamCoaches, { id: "tc4", teamId: "u18", coachId: "thomas", role: "ASSISTANT" }];
    const roles = expandCoachTeams(["thomas"], also, coachPlayers);
    // u18 est joueur (coachPlayers) ET assistant (teamCoaches) → assistant l'emporte.
    expect(roles.get("u18")).toBe("assistant");
  });
});

describe("applyMatchFilter — pass-through", () => {
  it("filtre vide ⇒ MÊMES références de tableaux, coachTeamRoles null", () => {
    const fixtures = [fixture({ id: "f1", teamId: "sm1" })];
    const conflicts: Conflict[] = [];
    const out = applyMatchFilter({ mode: "equipe", ids: [], fixtures, conflicts, teamCoaches, coachPlayers });
    expect(out.fixtures).toBe(fixtures);
    expect(out.conflicts).toBe(conflicts);
    expect(out.coachTeamRoles).toBeNull();
  });
});

describe("applyMatchFilter — équipe", () => {
  const fixtures = [fixture({ id: "f-sm1", teamId: "sm1" }), fixture({ id: "f-u13", teamId: "u13" })];
  it("ne garde que les matchs des équipes cochées", () => {
    const out = applyMatchFilter({ mode: "equipe", ids: ["sm1"], fixtures, conflicts: [], teamCoaches, coachPlayers });
    expect(out.fixtures.map((f) => f.id)).toEqual(["f-sm1"]);
  });

  it("garde un conflit dont un acteur (left/right/fixture/training) OU l'agrégat teamId est coché", () => {
    const conflicts: Conflict[] = [
      { type: "MATCH_MATCH", severity: 3, left: { fixtureId: "f-sm1", teamId: "sm1", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: null, windowStart: "", windowEnd: "" }, right: { fixtureId: "f-u13", teamId: "u13", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: null, windowStart: "", windowEnd: "" } },
      { type: "COMPETITION_INCOMPLETE", severity: 6, teamId: "u13" },
      { type: "VENUE_OVERLAP", severity: 2, venueId: "venue-9" },
    ];
    const out = applyMatchFilter({ mode: "equipe", ids: ["sm1"], fixtures, conflicts, teamCoaches, coachPlayers });
    expect(out.conflicts.map((c) => c.type)).toEqual(["MATCH_MATCH"]);
  });
});

describe("applyMatchFilter — coach", () => {
  const fixtures = [fixture({ id: "f-sm1", teamId: "sm1" }), fixture({ id: "f-u15", teamId: "u15m1" }), fixture({ id: "f-u13", teamId: "u13" })];
  it("étend au périmètre du coach (T(c)) et expose les rôles", () => {
    const out = applyMatchFilter({ mode: "coach", ids: ["thomas"], fixtures, conflicts: [], teamCoaches, coachPlayers });
    expect(out.fixtures.map((f) => f.id).sort()).toEqual(["f-sm1", "f-u15"]);
    expect(out.coachTeamRoles?.get("u15m1")).toBe("principal");
    expect(out.coachTeamRoles?.get("sm1")).toBe("assistant");
  });

  it("garde un conflit par coachId même sans équipe dans le périmètre ; exclut le sans-lien", () => {
    const conflicts: Conflict[] = [
      { type: "MATCH_MATCH", severity: 3, coachId: "thomas", left: { fixtureId: "f-sm1", teamId: "sm1", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: null, windowStart: "", windowEnd: "" }, right: { fixtureId: "f-u15", teamId: "u15m1", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: null, windowStart: "", windowEnd: "" } },
      { type: "VENUE_OVERLAP", severity: 2, venueId: "venue-9" },
    ];
    const out = applyMatchFilter({ mode: "coach", ids: ["thomas"], fixtures, conflicts, teamCoaches, coachPlayers });
    expect(out.conflicts.map((c) => c.type)).toEqual(["MATCH_MATCH"]);
  });
});

describe("applyMatchFilter — gymnase", () => {
  const fixtures = [
    fixture({ id: "f-home", teamId: "sm1", venueId: "venue-1" }),
    fixture({ id: "f-away", teamId: "sm1", homeAway: "AWAY", venueId: null }),
    fixture({ id: "f-other", teamId: "u13", venueId: "venue-2" }),
  ];
  it("ne garde que les matchs posés au gymnase coché ; les extérieurs (venueId null) sont exclus", () => {
    const out = applyMatchFilter({ mode: "gymnase", ids: ["venue-1"], fixtures, conflicts: [], teamCoaches, coachPlayers });
    expect(out.fixtures.map((f) => f.id)).toEqual(["f-home"]);
  });

  it("garde un conflit par venueId direct, par training.venueId, ou par la fixture référencée posée au gymnase", () => {
    const conflicts: Conflict[] = [
      { type: "VENUE_OVERLAP", severity: 2, venueId: "venue-1" },
      { type: "MATCH_TRAINING", severity: 4, training: { slotTemplateId: "t", scheduleId: "sc", teamId: "u13", venueId: "venue-1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, windowStart: "", windowEnd: "" } },
      { type: "MATCH_MATCH", severity: 3, left: { fixtureId: "f-home", teamId: "sm1", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "16:00", windowStart: "", windowEnd: "" }, right: { fixtureId: "f-other", teamId: "u13", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "16:00", windowStart: "", windowEnd: "" } },
      { type: "VENUE_OVERLAP", severity: 2, venueId: "venue-9" },
    ];
    const out = applyMatchFilter({ mode: "gymnase", ids: ["venue-1"], fixtures, conflicts, teamCoaches, coachPlayers });
    // 3 gardés (venueId direct, training.venueId, fixture f-home posée à venue-1) ; le dernier (venue-9) exclu.
    expect(out.conflicts.map((c) => c.type)).toEqual(["VENUE_OVERLAP", "MATCH_TRAINING", "MATCH_MATCH"]);
  });
});
