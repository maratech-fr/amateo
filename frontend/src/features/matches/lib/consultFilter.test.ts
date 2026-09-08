import { describe, expect, it } from "vitest";

import type { Competition, Conflict, ConflictType, Fixture } from "../api";
import { applyFamilyFilter, applyKindFilter, competitionKind, countByFamily, familyOf, KINDS, scopeConflictsToWeek } from "./consultFilter";

function fixture(over: Partial<Fixture> = {}): Fixture {
  return {
    id: "fx",
    teamId: "team-1",
    seasonId: "s",
    competitionId: null,
    matchDate: "2026-10-03",
    homeAway: "HOME",
    opponentLabel: "Voisins",
    status: "PLACED",
    venueId: "venue-1",
    kickoffTime: "16:00",
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: "MANUAL",
    unplacedReason: null,
    ...over,
  };
}

function competition(id: string, competitionType: string): Competition {
  return { id, teamId: "team-1", name: id, competitionType };
}

const competitionsById = new Map<string, Competition>([
  ["comp-champ", competition("comp-champ", "CHAMPIONSHIP")],
  ["comp-coupe", competition("comp-coupe", "CUP")],
  ["comp-brassage", competition("comp-brassage", "BRASSAGE")],
]);

function conflictRefFixture(fixtureId: string): Conflict {
  return {
    type: "VENUE_OVERLAP",
    severity: 1,
    fixture: { fixtureId, teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
  };
}

describe("competitionKind", () => {
  it("competitionId null ⇒ amical", () => {
    expect(competitionKind(fixture({ competitionId: null }), competitionsById)).toBe("amical");
  });

  it("mappe CHAMPIONSHIP/CUP/BRASSAGE ⇒ championnat/coupe/brassage", () => {
    expect(competitionKind(fixture({ competitionId: "comp-champ" }), competitionsById)).toBe("championnat");
    expect(competitionKind(fixture({ competitionId: "comp-coupe" }), competitionsById)).toBe("coupe");
    expect(competitionKind(fixture({ competitionId: "comp-brassage" }), competitionsById)).toBe("brassage");
  });

  it("compétition inconnue ⇒ repli sur championnat (jamais un vide)", () => {
    expect(competitionKind(fixture({ competitionId: "ghost" }), competitionsById)).toBe("championnat");
  });
});

describe("applyKindFilter", () => {
  const fxAmical = fixture({ id: "fx-amical", competitionId: null });
  const fxCoupe = fixture({ id: "fx-coupe", competitionId: "comp-coupe" });
  const fixtures = [fxAmical, fxCoupe];

  it("tout coché ⇒ pass-through (MÊMES références de tableaux)", () => {
    const conflicts = [conflictRefFixture("fx-coupe")];
    const out = applyKindFilter(fixtures, conflicts, KINDS, competitionsById);
    expect(out.fixtures).toBe(fixtures);
    expect(out.conflicts).toBe(conflicts);
  });

  it("filtre les fixtures par type de compétition", () => {
    const out = applyKindFilter(fixtures, [], ["amical"], competitionsById);
    expect(out.fixtures.map((f) => f.id)).toEqual(["fx-amical"]);
  });

  it("un conflit SUIT sa fixture référencée", () => {
    const conflicts = [conflictRefFixture("fx-coupe")];
    expect(applyKindFilter(fixtures, conflicts, ["coupe"], competitionsById).conflicts).toHaveLength(1);
    expect(applyKindFilter(fixtures, conflicts, ["amical"], competitionsById).conflicts).toHaveLength(0);
  });

  it("COMPETITION_INCOMPLETE suit son competitionId", () => {
    const incomplete: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, competitionId: "comp-coupe", teamId: "team-1" };
    expect(applyKindFilter(fixtures, [incomplete], ["coupe"], competitionsById).conflicts).toHaveLength(1);
    expect(applyKindFilter(fixtures, [incomplete], ["championnat"], competitionsById).conflicts).toHaveLength(0);
  });

  it("un conflit orphelin (ni fixture ni compétition) est visible si tout coché, retiré dès qu'on filtre", () => {
    const orphan: Conflict = { type: "AWAY_NO_FOOTPRINT", severity: 7 };
    // tout coché ⇒ pass-through, orphelin conservé
    expect(applyKindFilter(fixtures, [orphan], KINDS, competitionsById).conflicts).toContain(orphan);
    // un type décoché ⇒ on filtre, l'orphelin n'a aucun type à faire matcher
    expect(applyKindFilter(fixtures, [orphan], ["amical"], competitionsById).conflicts).toHaveLength(0);
  });
});

describe("scopeConflictsToWeek", () => {
  // Semaine du bucket samedi 2026-10-03 = lundi 2026-09-28 → dimanche 2026-10-04.
  const WEEKEND = "2026-10-03";
  const datedStart = (iso: string): Conflict => ({
    type: "MATCH_MATCH",
    severity: 3,
    start: iso,
    left: { fixtureId: "l", teamId: "team-1", homeAway: "HOME", matchDate: iso.slice(0, 10), kickoffTime: "16:00", windowStart: "", windowEnd: "" },
    right: { fixtureId: "r", teamId: "team-2", homeAway: "HOME", matchDate: iso.slice(0, 10), kickoffTime: "16:00", windowStart: "", windowEnd: "" },
  });
  const refByMatchDate = (matchDate: string): Conflict => ({
    type: "VENUE_UNAVAILABLE",
    severity: 1,
    fixture: { fixtureId: "fx", teamId: "team-1", homeAway: "HOME", matchDate, kickoffTime: "16:00", status: "PLACED" },
  });
  const dateless: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, competitionId: "comp-x", teamId: "team-1" };

  it("weekendKey null ⇒ pass-through (MÊME référence)", () => {
    const conflicts = [dateless];
    expect(scopeConflictsToWeek(conflicts, null)).toBe(conflicts);
  });

  it("un conflit daté DANS la semaine est gardé, HORS semaine est exclu", () => {
    const inWeek = datedStart("2026-10-03T15:30:00+00:00");
    const outWeek = datedStart("2026-10-10T15:30:00+00:00");
    const out = scopeConflictsToWeek([inWeek, outWeek], WEEKEND);
    expect(out).toContain(inWeek);
    expect(out).not.toContain(outWeek);
  });

  it("un conflit daté par la matchDate d'une fixture référencée (sans start) suit cette date", () => {
    const inWeek = refByMatchDate("2026-10-04"); // dimanche de la semaine affichée
    const outWeek = refByMatchDate("2026-11-01");
    const out = scopeConflictsToWeek([inWeek, outWeek], WEEKEND);
    expect(out).toContain(inWeek);
    expect(out).not.toContain(outWeek);
  });

  it("un conflit dateless (ni date ni fixture) est TOUJOURS gardé", () => {
    expect(scopeConflictsToWeek([dateless], WEEKEND)).toContain(dateless);
    // même en semaine différente : il ne dépend d'aucune date.
    expect(scopeConflictsToWeek([dateless], "2026-11-07")).toContain(dateless);
  });
});

describe("familyOf / countByFamily", () => {
  it("familyOf renvoie le type du conflit", () => {
    expect(familyOf({ type: "MATCH_MATCH", severity: 3 })).toBe("MATCH_MATCH");
  });

  it("countByFamily compte par famille", () => {
    const conflicts: Conflict[] = [
      { type: "MATCH_MATCH", severity: 3 },
      { type: "MATCH_MATCH", severity: 3 },
      { type: "TEAM_LINK_OVERLAP", severity: 6 },
    ];
    const counts = countByFamily(conflicts);
    expect(counts.get("MATCH_MATCH")).toBe(2);
    expect(counts.get("TEAM_LINK_OVERLAP")).toBe(1);
    expect(counts.get("VENUE_OVERLAP")).toBeUndefined();
  });
});

describe("applyFamilyFilter", () => {
  const conflicts: Conflict[] = [
    { type: "MATCH_MATCH", severity: 3 },
    { type: "TEAM_LINK_OVERLAP", severity: 6 },
  ];
  const ALL: ConflictType[] = ["VENUE_OVERLAP", "LEAGUE_WINDOW_VIOLATION", "MATCH_MATCH", "MATCH_TRAINING", "VENUE_UNAVAILABLE", "ACCESS_WINDOW_LOST", "TEAM_LINK_OVERLAP", "COMPETITION_INCOMPLETE", "AWAY_NO_FOOTPRINT"];

  it("toutes les familles cochées ⇒ pass-through (MÊME référence)", () => {
    expect(applyFamilyFilter(conflicts, ALL)).toBe(conflicts);
  });

  it("filtre par famille", () => {
    const out = applyFamilyFilter(conflicts, ["MATCH_MATCH"]);
    expect(out.map((c) => c.type)).toEqual(["MATCH_MATCH"]);
  });
});
