import { describe, expect, it } from "vitest";

import type { Conflict, Fixture, MatchSlotRotation, TeamMatchHabit } from "../api";
import { datelessConflicts, deriveWeekCounters, isOffModel, offModelCount, sameWeekendRotationCount, weekConflictCount } from "./loopSteps";

function rotation(over: Partial<MatchSlotRotation> = {}): MatchSlotRotation {
  return { id: over.id ?? "rot", venueId: over.venueId ?? "venue-1", dayOfWeek: over.dayOfWeek ?? 6, kickoffTime: over.kickoffTime ?? "16:00", teamIds: over.teamIds ?? ["team-1", "team-2"] };
}

/** A HOME fixture builder — everything placed by default, overridable. */
function fx(over: Partial<Fixture> = {}): Fixture {
  return {
    id: over.id ?? "fx",
    teamId: over.teamId ?? "team-1",
    seasonId: "s",
    // Compétition par défaut : « Placés au modèle » compte ces domiciles. Un amical
    // (competitionId null) en est exclu (P4-193) — testé à part.
    competitionId: "comp",
    matchDate: over.matchDate ?? "2026-10-03", // a Saturday
    homeAway: over.homeAway ?? "HOME",
    opponentLabel: "Adv",
    status: over.status ?? "PLACED",
    venueId: over.venueId ?? "venue-1",
    kickoffTime: over.kickoffTime ?? "16:00",
    externalRef: over.externalRef ?? null,
    fbiVenueLabel: null,
    placementSource: over.placementSource ?? "MANUAL",
    unplacedReason: over.unplacedReason ?? null,
    reviewState: over.reviewState ?? "NEW",
    reviewedAt: over.reviewedAt ?? null,
    pendingDeviations: over.pendingDeviations ?? [],
    ffbbRencontreId: over.ffbbRencontreId ?? null,
    suggestedVenueId: over.suggestedVenueId ?? null,
    opponentOrganismeCode: over.opponentOrganismeCode ?? null,
    opponentTeamKey: over.opponentTeamKey ?? null,
    ...over,
  };
}

/** ISO weekday of 2026-10-03 is Saturday = 6. */
function habit(over: Partial<TeamMatchHabit> = {}): TeamMatchHabit {
  return { id: over.id ?? "h", teamId: over.teamId ?? "team-1", dayOfWeek: over.dayOfWeek ?? 6, kickoffTime: over.kickoffTime ?? "16:00", venueId: over.venueId ?? null };
}

function conflictOn(fixtureId: string): Conflict {
  return { type: "MATCH_MATCH", severity: 3, resolution: null, left: { fixtureId, teamId: "t", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" } };
}

describe("deriveWeekCounters — les 3 compteurs DÉRIVÉS de la semaine (PR 3b, ex-rail)", () => {
  it("semaine vide ⇒ 0 · 0 · 0", () => {
    expect(deriveWeekCounters([], [])).toEqual({ unplaced: 0, conflicts: 0 });
  });

  it("« à placer » = domiciles UNPLACED (habitude ou non — le compteur ne filtre pas par modèle)", () => {
    // Contrairement à l'ancienne étape « Placés au modèle », le compteur « à placer » compte
    // TOUS les domiciles UNPLACED, avec ou sans habitude (P4-197 : la liste couvre tout).
    const weekFixtures = [fx({ id: "u", status: "UNPLACED", venueId: null, kickoffTime: null }), fx({ id: "p", status: "PLACED" })];
    expect(deriveWeekCounters(weekFixtures, []).unplaced).toBe(1);
    // Un amical non placé compte aussi (le compteur ne fait pas la distinction P4-193 de l'ancien rail).
    const amical = [fx({ id: "a", competitionId: null, status: "UNPLACED", venueId: null, kickoffTime: null })];
    expect(deriveWeekCounters(amical, []).unplaced).toBe(1);
  });

  it("« conflits » = conflits À TRAITER rattachés à la semaine (pas les sans-date, pas une autre semaine, pas les annotés)", () => {
    const weekFixtures = [fx({ id: "w1" })];
    // Conflit sur w1 (dans W) → compte.
    expect(deriveWeekCounters(weekFixtures, [conflictOn("w1")]).conflicts).toBe(1);
    // Conflit SANS fixture (COMPETITION_INCOMPLETE) → hors compte hebdo.
    const dateless: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, competitionId: "c", teamId: "team-1", imported: 3, expected: 6 };
    expect(deriveWeekCounters(weekFixtures, [dateless]).conflicts).toBe(0);
    // Conflit sur un fixture d'une AUTRE semaine → pas dans le compte de W.
    expect(deriveWeekCounters(weekFixtures, [conflictOn("not-in-w")]).conflicts).toBe(0);
    // Conflit ANNOTÉ (P4-207) → ne compte plus.
    const treated: Conflict = { ...conflictOn("w1"), resolution: { status: "DEROGATION_REQUESTED", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } };
    expect(deriveWeekCounters(weekFixtures, [treated]).conflicts).toBe(0);
  });

  // « à saisir dans FBI » a QUITTÉ deriveWeekCounters (compteur GLOBAL `fbiTodo` désormais).
});

describe("weekConflictCount — conflits À TRAITER rattachés à la semaine (exporté PR 3b)", () => {
  it("compte les OUVERTS référençant un fixture de la semaine ; ignore annotés/hors-semaine", () => {
    const ids = new Set(["w1"]);
    expect(weekConflictCount([conflictOn("w1")], ids)).toBe(1);
    expect(weekConflictCount([conflictOn("other")], ids)).toBe(0);
    const treated: Conflict = { ...conflictOn("w1"), resolution: { status: "NO_SOLUTION_YET", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } };
    expect(weekConflictCount([treated], ids)).toBe(0);
  });
});

describe("datelessConflicts — conflits sans fixture (bannière hors-semaine)", () => {
  it("garde les conflits sans fixture référencé, écarte ceux datés", () => {
    const dateless: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, competitionId: "c", teamId: "team-1", imported: 3, expected: 6 };
    expect(datelessConflicts([dateless, conflictOn("w1")])).toEqual([dateless]);
  });
});

describe("isOffModel — divergence d'un domicile placé vs son habitude", () => {
  it("pas d'habitude sur l'équipe ⇒ jamais un écart (pas de modèle de référence)", () => {
    expect(isOffModel(fx({ kickoffTime: "20:00" }), [])).toBe(false);
  });
  it("UNPLACED ⇒ jamais un écart (rien de placé à comparer)", () => {
    expect(isOffModel(fx({ status: "UNPLACED", venueId: null, kickoffTime: null }), [habit()])).toBe(false);
  });
  it("jour non habituel ⇒ écart", () => {
    // Placé un dimanche (7) alors que l'habitude est le samedi (6).
    expect(isOffModel(fx({ matchDate: "2026-10-04" }), [habit({ dayOfWeek: 6 })])).toBe(true);
  });
  it("même jour, gymnase habituel divergent ⇒ écart", () => {
    expect(isOffModel(fx({ venueId: "venue-2" }), [habit({ venueId: "venue-1" })])).toBe(true);
  });
  it("même jour, heure et gymnase conformes ⇒ pas d'écart", () => {
    expect(isOffModel(fx({ kickoffTime: "16:00", venueId: "venue-1" }), [habit({ kickoffTime: "16:00", venueId: "venue-1" })])).toBe(false);
  });
});

describe("isOffModel — le créneau de ROTATION est la référence du jour (RMM-5 PR-4)", () => {
  it("membre d'un créneau partagé placé HORS de son créneau (heure) ⇒ écart", () => {
    // La rotation samedi 20:30 est la référence ; placé à 18:30 → écart.
    const fixture = fx({ teamId: "team-1", matchDate: "2026-10-03", kickoffTime: "18:30", venueId: "venue-1" });
    expect(isOffModel(fixture, [], [rotation({ dayOfWeek: 6, kickoffTime: "20:30", venueId: "venue-1" })])).toBe(true);
  });
  it("membre placé HORS de son créneau (gymnase) ⇒ écart", () => {
    const fixture = fx({ teamId: "team-1", matchDate: "2026-10-03", kickoffTime: "20:30", venueId: "venue-2" });
    expect(isOffModel(fixture, [], [rotation({ dayOfWeek: 6, kickoffTime: "20:30", venueId: "venue-1" })])).toBe(true);
  });
  it("membre placé SUR son créneau (heure + gymnase) ⇒ pas d'écart", () => {
    const fixture = fx({ teamId: "team-1", matchDate: "2026-10-03", kickoffTime: "20:30", venueId: "venue-1" });
    expect(isOffModel(fixture, [], [rotation({ dayOfWeek: 6, kickoffTime: "20:30", venueId: "venue-1" })])).toBe(false);
  });
  it("la rotation du jour PRIME sur l'habitude (suppléance) : conforme au créneau ⇒ pas d'écart même si l'habitude divergeait", () => {
    // Habitude 16:00 mais rotation 20:30 le même jour ; placé 20:30 → conforme (rotation prime).
    const fixture = fx({ teamId: "team-1", matchDate: "2026-10-03", kickoffTime: "20:30", venueId: "venue-1" });
    expect(isOffModel(fixture, [habit({ teamId: "team-1", dayOfWeek: 6, kickoffTime: "16:00", venueId: "venue-1" })], [rotation({ dayOfWeek: 6, kickoffTime: "20:30", venueId: "venue-1" })])).toBe(false);
  });
  it("offModelCount tient compte des rotations", () => {
    const off = fx({ id: "o", teamId: "team-1", matchDate: "2026-10-03", kickoffTime: "18:30", venueId: "venue-1" });
    expect(offModelCount([off], [], [rotation({ dayOfWeek: 6, kickoffTime: "20:30", venueId: "venue-1" })])).toBe(1);
  });
});

describe("sameWeekendRotationCount — deux membres reçoivent le même week-end", () => {
  it("deux membres distincts d'une même rotation à domicile le même week-end ⇒ 1", () => {
    const home1 = fx({ id: "a", teamId: "team-1", homeAway: "HOME" });
    const home2 = fx({ id: "b", teamId: "team-2", homeAway: "HOME" });
    expect(sameWeekendRotationCount([home1, home2], [rotation({ teamIds: ["team-1", "team-2"] })])).toBe(1);
  });
  it("un seul membre à domicile ⇒ 0 (l'alternance est respectée)", () => {
    const home1 = fx({ id: "a", teamId: "team-1", homeAway: "HOME" });
    const away2 = fx({ id: "b", teamId: "team-2", homeAway: "AWAY" });
    expect(sameWeekendRotationCount([home1, away2], [rotation({ teamIds: ["team-1", "team-2"] })])).toBe(0);
  });
  it("le MÊME membre deux fois à domicile ne compte pas (il faut deux membres DISTINCTS)", () => {
    const home1 = fx({ id: "a", teamId: "team-1", homeAway: "HOME" });
    const home1bis = fx({ id: "b", teamId: "team-1", homeAway: "HOME" });
    expect(sameWeekendRotationCount([home1, home1bis], [rotation({ teamIds: ["team-1", "team-2"] })])).toBe(0);
  });
});

describe("le SIGNAL ne pèse JAMAIS dans les compteurs (les rotations n'y entrent nulle part)", () => {
  it("écart au modèle + même-week-end pleins : les 3 compteurs restent INCHANGÉS", () => {
    // Deux membres d'une rotation, tous deux placés HORS créneau ET recevant le même week-end.
    const home1 = fx({ id: "a", teamId: "team-1", status: "SUBMITTED", matchDate: "2026-10-03", kickoffTime: "18:30", venueId: "venue-1" });
    const home2 = fx({ id: "b", teamId: "team-2", status: "SUBMITTED", matchDate: "2026-10-03", kickoffTime: "18:30", venueId: "venue-1" });
    const rots = [rotation({ dayOfWeek: 6, kickoffTime: "20:30", venueId: "venue-1", teamIds: ["team-1", "team-2"] })];
    // Signal PLEIN…
    expect(offModelCount([home1, home2], [], rots)).toBe(2);
    expect(sameWeekendRotationCount([home1, home2], rots)).toBe(1);
    // …et pourtant les compteurs sont intacts : tout est SUBMITTED, aucun conflit.
    expect(deriveWeekCounters([home1, home2], [])).toEqual({ unplaced: 0, conflicts: 0 });
  });
});
