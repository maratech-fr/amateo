import { describe, expect, it } from "vitest";

import type { Competition, Conflict, ConflictType, Fixture } from "../api";
import { applyFamilyFilter, applyKindFilter, competitionKind, countByFamily, dateOf, DEFAULT_KINDS, familiesPresent, familyOf, hasHomeSide, hiddenBreakdownParts, hiddenWeekBreakdown, KINDS, normalizeKinds, revealPlan, scopeConflictsToWeek } from "./consultFilter";

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
    reviewState: "NEW" as const,
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null,
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
    severity: 1, resolution: null,
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
    const incomplete: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, competitionId: "comp-coupe", teamId: "team-1" };
    expect(applyKindFilter(fixtures, [incomplete], ["coupe"], competitionsById).conflicts).toHaveLength(1);
    expect(applyKindFilter(fixtures, [incomplete], ["championnat"], competitionsById).conflicts).toHaveLength(0);
  });

  it("un conflit orphelin (ni fixture ni compétition) est visible si tout coché, retiré dès qu'on filtre", () => {
    const orphan: Conflict = { type: "AWAY_NO_FOOTPRINT", severity: 7, resolution: null };
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
    severity: 3, resolution: null,
    start: iso,
    left: { fixtureId: "l", teamId: "team-1", homeAway: "HOME", matchDate: iso.slice(0, 10), kickoffTime: "16:00", windowStart: "", windowEnd: "" },
    right: { fixtureId: "r", teamId: "team-2", homeAway: "HOME", matchDate: iso.slice(0, 10), kickoffTime: "16:00", windowStart: "", windowEnd: "" },
  });
  const refByMatchDate = (matchDate: string): Conflict => ({
    type: "VENUE_UNAVAILABLE",
    severity: 1, resolution: null,
    fixture: { fixtureId: "fx", teamId: "team-1", homeAway: "HOME", matchDate, kickoffTime: "16:00", status: "PLACED" },
  });
  const dateless: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, competitionId: "comp-x", teamId: "team-1" };

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
    expect(familyOf({ type: "MATCH_MATCH", severity: 3, resolution: null })).toBe("MATCH_MATCH");
  });

  it("countByFamily compte par famille", () => {
    const conflicts: Conflict[] = [
      { type: "MATCH_MATCH", severity: 3, resolution: null },
      { type: "MATCH_MATCH", severity: 3, resolution: null },
      { type: "LEAGUE_WINDOW_VIOLATION", severity: 6, resolution: null },
    ];
    const counts = countByFamily(conflicts);
    expect(counts.get("MATCH_MATCH")).toBe(2);
    expect(counts.get("LEAGUE_WINDOW_VIOLATION")).toBe(1);
    expect(counts.get("VENUE_OVERLAP")).toBeUndefined();
  });

  it("countByFamily ne compte QUE l'à traiter — un conflit annoté ne pèse pas (P4-207)", () => {
    const conflicts: Conflict[] = [
      { type: "MATCH_MATCH", severity: 3, resolution: null },
      { type: "MATCH_MATCH", severity: 3, resolution: { status: "DEROGATION_REQUESTED", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } },
      { type: "LEAGUE_WINDOW_VIOLATION", severity: 6, resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } },
    ];
    const counts = countByFamily(conflicts);
    // Un seul MATCH_MATCH à traiter ; le LEAGUE_WINDOW_VIOLATION entièrement traité disparaît du compte.
    expect(counts.get("MATCH_MATCH")).toBe(1);
    expect(counts.get("LEAGUE_WINDOW_VIOLATION")).toBeUndefined();
  });

  it("familiesPresent : les familles AYANT au moins un conflit (traité ou non) — pour la visibilité des chips (P4-207)", () => {
    const conflicts: Conflict[] = [
      { type: "MATCH_MATCH", severity: 3, resolution: null },
      // Famille entièrement traitée : présente (chip visible à 0), mais hors compte.
      { type: "LEAGUE_WINDOW_VIOLATION", severity: 6, resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } },
    ];
    const present = familiesPresent(conflicts);
    expect(present.has("MATCH_MATCH")).toBe(true);
    expect(present.has("LEAGUE_WINDOW_VIOLATION")).toBe(true);
    // Une famille sans AUCUN conflit reste absente (chip masquée).
    expect(present.has("VENUE_OVERLAP")).toBe(false);
    expect(familiesPresent([]).size).toBe(0);
  });
});

describe("applyFamilyFilter", () => {
  const conflicts: Conflict[] = [
    { type: "MATCH_MATCH", severity: 3, resolution: null },
    { type: "LEAGUE_WINDOW_VIOLATION", severity: 6, resolution: null },
  ];
  const ALL: ConflictType[] = ["VENUE_OVERLAP", "LEAGUE_WINDOW_VIOLATION", "MATCH_MATCH", "MATCH_TRAINING", "VENUE_UNAVAILABLE", "ACCESS_WINDOW_LOST", "COMPETITION_INCOMPLETE", "AWAY_NO_FOOTPRINT"];

  it("toutes les familles cochées ⇒ pass-through (MÊME référence)", () => {
    expect(applyFamilyFilter(conflicts, ALL)).toBe(conflicts);
  });

  it("filtre par famille", () => {
    const out = applyFamilyFilter(conflicts, ["MATCH_MATCH"]);
    expect(out.map((c) => c.type)).toEqual(["MATCH_MATCH"]);
  });
});

describe("dateOf (exporté pour le pivot par journée)", () => {
  it("prend `start` tronqué à la date quand il est présent", () => {
    expect(dateOf({ type: "VENUE_OVERLAP", severity: 1, resolution: null, start: "2026-10-03T20:45:00" })).toBe("2026-10-03");
  });

  it("à défaut, la matchDate d'un côté référencé (left, puis right, puis fixture)", () => {
    expect(dateOf({ type: "MATCH_MATCH", severity: 3, resolution: null, left: { fixtureId: "f", teamId: "t", homeAway: "HOME", matchDate: "2026-11-07", kickoffTime: null, windowStart: "", windowEnd: "" } })).toBe("2026-11-07");
    expect(dateOf({ type: "VENUE_UNAVAILABLE", severity: 1, resolution: null, fixture: { fixtureId: "f", teamId: "t", homeAway: "HOME", matchDate: "2026-12-05", kickoffTime: null, status: "PLACED" } })).toBe("2026-12-05");
  });

  it("sans date ni côté ⇒ null (conflit « sans date »)", () => {
    expect(dateOf({ type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, teamId: "t" })).toBeNull();
  });
});

describe("DEFAULT_KINDS / normalizeKinds (A1)", () => {
  it("les défauts excluent l'amical", () => {
    expect(DEFAULT_KINDS).toEqual(["championnat", "coupe", "brassage"]);
  });

  it("normalizeKinds : les défauts ⇒ null ; les 4 / un sous-ensemble / vide ⇒ liste explicite", () => {
    expect(normalizeKinds(["championnat", "coupe", "brassage"])).toBeNull();
    expect(normalizeKinds(["brassage", "coupe", "championnat"])).toBeNull(); // ordre indifférent
    expect(normalizeKinds(["amical", "championnat", "coupe", "brassage"])).toEqual(["amical", "championnat", "coupe", "brassage"]);
    expect(normalizeKinds(["coupe"])).toEqual(["coupe"]);
    expect(normalizeKinds([])).toEqual([]);
  });
});

describe("hasHomeSide (B4)", () => {
  const home = { fixtureId: "f", teamId: "t", homeAway: "HOME" as const, matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" };
  const away = { fixtureId: "f", teamId: "t", homeAway: "AWAY" as const, matchDate: "2026-10-03", kickoffTime: null, windowStart: "", windowEnd: "" };

  it("un côté HOME (left/right/fixture) ⇒ true", () => {
    expect(hasHomeSide({ type: "MATCH_MATCH", severity: 3, resolution: null, left: home, right: away })).toBe(true);
    expect(hasHomeSide({ type: "VENUE_UNAVAILABLE", severity: 1, resolution: null, fixture: { ...home, status: "PLACED" } })).toBe(true);
  });

  it("tous les côtés AWAY ⇒ false (AWAY_NO_FOOTPRINT masqué)", () => {
    expect(hasHomeSide({ type: "AWAY_NO_FOOTPRINT", severity: 7, resolution: null, left: away, right: away })).toBe(false);
  });

  it("aucun côté (COMPETITION_INCOMPLETE) ⇒ false (masqué)", () => {
    expect(hasHomeSide({ type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, teamId: "t", competitionId: "c" })).toBe(false);
  });
});

describe("revealPlan (A8)", () => {
  it("un extérieur ⇒ away true ; un type hors sélection ⇒ ajouté", () => {
    const awayCoupe = fixture({ id: "a", competitionId: "comp-coupe", homeAway: "AWAY" });
    const plan = revealPlan([awayCoupe], DEFAULT_KINDS, competitionsById);
    expect(plan.away).toBe(true);
    // coupe est un défaut ⇒ pas d'ajout de type ; seul l'extérieur manque.
    expect(plan.kinds).toEqual([]);
  });

  it("un amical à domicile hors défauts ⇒ ajoute « amical », away false", () => {
    const amicalHome = fixture({ id: "b", competitionId: null, homeAway: "HOME" });
    const plan = revealPlan([amicalHome], DEFAULT_KINDS, competitionsById);
    expect(plan.away).toBe(false);
    expect(plan.kinds).toEqual(["amical"]);
  });
});

describe("hiddenWeekBreakdown / hiddenBreakdownParts (A5)", () => {
  const champHome = fixture({ id: "h", competitionId: "comp-champ", homeAway: "HOME" });
  const amicalHome = fixture({ id: "a", competitionId: null, homeAway: "HOME" });
  const awayChamp = fixture({ id: "w", competitionId: "comp-champ", homeAway: "AWAY" });

  it("masque par TYPE (amical décoché) et par EXTÉRIEUR (interrupteur), le type primant", () => {
    // Défauts (pas d'amical), extérieurs éteints : amical masqué par type, away masqué par interrupteur.
    const b = hiddenWeekBreakdown([champHome, amicalHome, awayChamp], DEFAULT_KINDS, false, competitionsById);
    expect(b.total).toBe(2);
    expect(b.away).toBe(1);
    expect(b.byKind.get("amical")).toBe(1);
    expect(hiddenBreakdownParts(b)).toEqual(["1 extérieur", "1 amical"]);
  });

  it("interrupteur allumé : plus d'extérieur masqué, seul l'amical (type) reste", () => {
    const b = hiddenWeekBreakdown([champHome, amicalHome, awayChamp], DEFAULT_KINDS, true, competitionsById);
    expect(b.total).toBe(1);
    expect(b.away).toBe(0);
    expect(hiddenBreakdownParts(b)).toEqual(["1 amical"]);
  });

  it("un extérieur d'un type décoché compte UNE fois côté type (pas dans away)", () => {
    const awayAmical = fixture({ id: "x", competitionId: null, homeAway: "AWAY" });
    const b = hiddenWeekBreakdown([awayAmical], DEFAULT_KINDS, false, competitionsById);
    expect(b.away).toBe(0);
    expect(b.byKind.get("amical")).toBe(1);
    expect(hiddenBreakdownParts(b)).toEqual(["1 amical"]);
  });

  it("pluriels : 2 extérieurs, 2 amicaux", () => {
    const b = hiddenWeekBreakdown(
      [amicalHome, fixture({ id: "a2", competitionId: null, homeAway: "HOME" }), awayChamp, fixture({ id: "w2", competitionId: "comp-champ", homeAway: "AWAY" })],
      DEFAULT_KINDS,
      false,
      competitionsById,
    );
    expect(hiddenBreakdownParts(b)).toEqual(["2 extérieurs", "2 amicaux"]);
  });
});
