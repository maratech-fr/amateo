import { describe, expect, it } from "vitest";

import type { Competition, Conflict, Fixture, Team } from "../api";
import { listPhases, phaseCompleteness, phaseFixtures, scopeConflictsToPhase } from "./phaseView";

function comp(partial: Partial<Competition> & Pick<Competition, "id" | "teamId" | "name">): Competition {
  return { competitionType: "CHAMPIONSHIP", ...partial };
}
function team(id: string, name: string): Team {
  return { id, name, sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 };
}
function fx(partial: Partial<Fixture> & Pick<Fixture, "id" | "matchDate" | "competitionId">): Fixture {
  return {
    teamId: "t",
    seasonId: "s",
    homeAway: "HOME",
    opponentLabel: "Adv",
    status: "PLACED",
    venueId: null,
    kickoffTime: null,
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: null,
    unplacedReason: null,
    reviewState: "NEW" as const,
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null,
    ...partial,
  };
}

describe("listPhases", () => {
  it("ne garde que les compétitions APPARIÉES (ffbbCompetitionId), libellé « nom — équipe », trié", () => {
    const comps = [
      comp({ id: "c2", teamId: "tb", name: "Championnat R2", ffbbCompetitionId: "ffbb-2" }),
      comp({ id: "c1", teamId: "ta", name: "Championnat D1", ffbbCompetitionId: "ffbb-1" }),
      comp({ id: "c-unpaired", teamId: "ta", name: "Amical interne" }), // non appariée → exclue
    ];
    const teams = [team("ta", "SM1"), team("tb", "SF2")];
    expect(listPhases(comps, teams)).toEqual([
      { competitionId: "c1", label: "Championnat D1 — SM1" },
      { competitionId: "c2", label: "Championnat R2 — SF2" },
    ]);
  });

  it("aucune compétition appariée ⇒ []", () => {
    expect(listPhases([comp({ id: "c", teamId: "t", name: "X" })], [team("t", "T")])).toEqual([]);
  });
});

describe("phaseFixtures", () => {
  it("ne garde que la compétition, groupe par week-end, trié", () => {
    const fixtures = [
      fx({ id: "w2", matchDate: "2026-10-10", competitionId: "c1", kickoffTime: "16:00" }),
      fx({ id: "w1", matchDate: "2026-10-03", competitionId: "c1", kickoffTime: "16:00" }),
      fx({ id: "other", matchDate: "2026-10-03", competitionId: "c2", kickoffTime: "16:00" }),
    ];
    const groups = phaseFixtures(fixtures, "c1");
    expect(groups.map((g) => g.weekend)).toEqual(["2026-10-03", "2026-10-10"]);
    expect(groups[0].fixtures.map((f) => f.id)).toEqual(["w1"]);
  });
});

describe("phaseCompleteness", () => {
  const competition = comp({ id: "c1", teamId: "t", name: "D1", ffbbCompetitionId: "f1", expectedMatchdays: 10 });
  it("préfère le conflit COMPETITION_INCOMPLETE de la compétition", () => {
    const incomplete: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, competitionId: "c1", imported: 4, expected: 10 };
    expect(phaseCompleteness(competition, [fx({ id: "a", matchDate: "2026-10-03", competitionId: "c1" })], [incomplete])).toEqual({ imported: 4, expected: 10 });
  });
  it("sinon compte les fixtures / expectedMatchdays", () => {
    const fixtures = [fx({ id: "a", matchDate: "2026-10-03", competitionId: "c1" }), fx({ id: "b", matchDate: "2026-10-10", competitionId: "c1" })];
    expect(phaseCompleteness(competition, fixtures, [])).toEqual({ imported: 2, expected: 10 });
  });
});

describe("scopeConflictsToPhase", () => {
  const overlap: Conflict = {
    type: "VENUE_OVERLAP",
    severity: 1,
    left: { fixtureId: "fa", teamId: "t", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
  };
  const incomplete: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, competitionId: "c1", imported: 4, expected: 10 };
  const foreign: Conflict = {
    type: "VENUE_OVERLAP",
    severity: 1,
    left: { fixtureId: "zz", teamId: "t", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
  };

  it("garde les conflits référençant une fixture de la phase + le COMPETITION_INCOMPLETE de la compétition", () => {
    const kept = scopeConflictsToPhase([overlap, incomplete, foreign], "c1", ["fa", "fb"]);
    expect(kept).toContain(overlap);
    expect(kept).toContain(incomplete);
    expect(kept).not.toContain(foreign);
  });

  it("competitionId null ⇒ pass-through (MÊME référence)", () => {
    const all = [overlap, incomplete];
    expect(scopeConflictsToPhase(all, null, [])).toBe(all);
  });
});
