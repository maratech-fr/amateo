import { describe, expect, it } from "vitest";

import type { Conflict, Fixture } from "../api";
import { PIVOT_AXES, pivotConflicts } from "./conflictPivot";

function fixture(over: Partial<Fixture> & Pick<Fixture, "id" | "teamId">): Fixture {
  return {
    seasonId: "s",
    competitionId: null,
    matchDate: "2026-10-03",
    homeAway: "HOME",
    opponentLabel: "Adv",
    status: "PLACED",
    venueId: "venue-1",
    kickoffTime: "16:00",
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: null,
    unplacedReason: null,
    reviewState: "NEW",
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null,
    suggestedVenueId: null,
    ...over,
  };
}

function side(fixtureId: string, teamId: string, matchDate = "2026-10-03") {
  return { fixtureId, teamId, homeAway: "HOME" as const, matchDate, kickoffTime: "16:00", windowStart: "", windowEnd: "" };
}

// Une rencontre par côté référencé, posée au gymnase indiqué.
const fixturesById = new Map<string, Fixture>([
  ["fx-1", fixture({ id: "fx-1", teamId: "team-1", venueId: "venue-1" })],
  ["fx-2", fixture({ id: "fx-2", teamId: "team-2", venueId: "venue-2" })],
  ["fx-away", fixture({ id: "fx-away", teamId: "team-1", homeAway: "AWAY", venueId: null })],
]);

const coachDouble: Conflict = { type: "MATCH_MATCH", severity: 3, coachId: "coach-1", start: "2026-10-03T20:00:00", end: "2026-10-03T22:00:00", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") };
const coachTraining: Conflict = { type: "MATCH_TRAINING", severity: 5, coachId: "coach-1", fixture: side("fx-1", "team-1"), training: { slotTemplateId: "t", scheduleId: "sc", teamId: "team-3", venueId: "venue-3", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, windowStart: "", windowEnd: "" } };
const venueOverlap: Conflict = { type: "VENUE_OVERLAP", severity: 1, venueId: "venue-1", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") };
const awayNoFootprint: Conflict = { type: "AWAY_NO_FOOTPRINT", severity: 7, fixture: side("fx-away", "team-1", "2026-10-04") };
const incomplete: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, teamId: "team-2", competitionId: "comp-1" };

describe("pivotConflicts — axes", () => {
  it("PIVOT_AXES = coach, equipe, gymnase, journee (dans l'ordre)", () => {
    expect(PIVOT_AXES).toEqual(["coach", "equipe", "gymnase", "journee"]);
  });
});

describe("pivotConflicts — coach", () => {
  it("regroupe par coachId ; les conflits sans coach vont dans la sentinelle « sansCoach », en dernier", () => {
    const entries = pivotConflicts([coachDouble, coachTraining, venueOverlap, incomplete], "coach", fixturesById);
    const coach = entries.find((e) => "coach-1" === e.key);
    expect(coach?.kind).toBe("resource");
    expect(coach?.conflicts).toHaveLength(2);
    // Deux conflits sans coach (venueOverlap, incomplete) → une seule sentinelle, en DERNIER.
    const last = entries[entries.length - 1];
    expect(last.kind).toBe("sansCoach");
    expect(last.conflicts).toHaveLength(2);
  });
});

describe("pivotConflicts — equipe", () => {
  it("un conflit à 2 équipes apparaît sous CHAQUE équipe (somme > total, assumé)", () => {
    const entries = pivotConflicts([coachDouble], "equipe", fixturesById);
    const keys = entries.map((e) => e.key).sort();
    expect(keys).toEqual(["team-1", "team-2"]);
    expect(entries.every((e) => 1 === e.conflicts.length)).toBe(true);
  });
});

describe("pivotConflicts — gymnase", () => {
  it("résout le gymnase par venueId direct, training.venueId et via fixturesById", () => {
    // venueOverlap : venueId venue-1. coachTraining : training venue-3 + fixture fx-1 (venue-1).
    const entries = pivotConflicts([venueOverlap, coachTraining], "gymnase", fixturesById);
    const byKey = new Map(entries.map((e) => [e.key, e]));
    expect(byKey.get("venue-1")?.conflicts).toHaveLength(2); // venueOverlap + coachTraining (fx-1)
    expect(byKey.get("venue-3")?.conflicts).toHaveLength(1); // coachTraining (training)
    expect(byKey.has("__exterieur__")).toBe(false);
  });

  it("un conflit sans gymnase résolu (extérieur) tombe dans la sentinelle « Extérieur », en dernier", () => {
    const entries = pivotConflicts([awayNoFootprint], "gymnase", fixturesById);
    expect(entries).toHaveLength(1);
    expect(entries[0].kind).toBe("exterieur");
    expect(entries[0].conflicts).toHaveLength(1);
  });
});

describe("pivotConflicts — journee", () => {
  it("regroupe par week-end (clé = samedi) ; les conflits sans date vont dans « sansDate », en dernier", () => {
    const entries = pivotConflicts([coachDouble, awayNoFootprint, incomplete], "journee", fixturesById);
    // coachDouble (2026-10-03) et awayNoFootprint (2026-10-04) tombent dans le même week-end (samedi 03).
    const weekend = entries.find((e) => "weekend" === e.kind);
    expect(weekend?.key).toBe("2026-10-03");
    expect(weekend?.conflicts).toHaveLength(2);
    const last = entries[entries.length - 1];
    expect(last.kind).toBe("sansDate");
    expect(last.conflicts).toHaveLength(1); // incomplete
  });
});

describe("pivotConflicts — tri interne par gravité", () => {
  it("les conflits d'une entrée sont ordonnés du pire (1) au plus doux", () => {
    const entries = pivotConflicts([venueOverlap, coachTraining], "gymnase", fixturesById);
    const venue1 = entries.find((e) => "venue-1" === e.key);
    // venueOverlap severity 1 avant coachTraining severity 5.
    expect(venue1?.conflicts.map((c) => c.severity)).toEqual([1, 5]);
  });
});
