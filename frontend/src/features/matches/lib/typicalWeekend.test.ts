import { describe, expect, it } from "vitest";

import type { MatchWeek, Team, TeamMatchHabit } from "../api";
import { buildTypicalWeekend, hasAlternatingWeeks } from "./typicalWeekend";

const habit = (over: Partial<TeamMatchHabit> = {}): TeamMatchHabit => ({
  id: "h-1",
  teamId: "team-1",
  dayOfWeek: 6,
  kickoffTime: "15:30",
  venueId: "venue-1",
  week: "ALL",
  ...over,
});

const team = (id: string, sportCategoryId: string): Team => ({
  id,
  name: id,
  sportCategoryId,
  level: null,
  gender: null,
  priorityTierId: 1,
  tierOrder: 0,
});

// Deux catégories aux durées de match SERVIES (résolues côté serveur) différentes — elles
// prouvent que la grille dessine la durée PAR ÉQUIPE, jamais une empreinte fixe.
const TEAMS = new Map<string, Team>([
  ["team-1", team("team-1", "cat-u11")],
  ["team-2", team("team-2", "cat-senior")],
  ["team-3", team("team-3", "cat-senior")],
  ["t-a", team("t-a", "cat-senior")],
  ["t-b", team("t-b", "cat-senior")],
  ["t-all", team("t-all", "cat-senior")],
]);
const DURATIONS = new Map<string, number>([
  ["cat-u11", 75],
  ["cat-senior", 105],
]);

const build = (habits: TeamMatchHabit[], week?: MatchWeek) => buildTypicalWeekend(habits, TEAMS, DURATIONS, week);

describe("buildTypicalWeekend (P1-4 PR E2)", () => {
  // P4-206 — le « week-end type » dessine chaque créneau du COUP D'ENVOI à coup d'envoi + la
  // durée réelle de la catégorie (servie par le serveur), SANS échauffement, comme la grille
  // datée. team-1 est en U11 (durée servie 75) : début = coup d'envoi, fin = coup d'envoi + 75.
  it("dessine un créneau du coup d'envoi à coup d'envoi + durée servie, sans échauffement", () => {
    const model = build([habit()]);
    const kickoffMin = 15 * 60 + 30;
    expect(model.empty).toBe(false);
    expect(model.columns).toEqual([{ key: "6:venue-1", dayOfWeek: 6, venueId: "venue-1" }]);
    expect(model.blocks[0]).toMatchObject({ startMin: kickoffMin, endMin: kickoffMin + 75, kickoff: "15:30" });
  });

  // La durée est celle de la CATÉGORIE de l'équipe : deux équipes au même coup d'envoi mais de
  // durées servies différentes (U11 75 / senior 105) reçoivent deux empreintes différentes.
  it("dessine la durée PAR CATÉGORIE (deux durées servies → deux empreintes)", () => {
    const kickoff = 13 * 60;
    const model = build([
      habit({ id: "u11", teamId: "team-1", kickoffTime: "13:00", venueId: "venue-1" }),
      habit({ id: "sen", teamId: "team-2", kickoffTime: "13:00", venueId: "venue-2" }),
    ]);
    expect(model.blocks.find((b) => "u11" === b.key)).toMatchObject({ startMin: kickoff, endMin: kickoff + 75 });
    expect(model.blocks.find((b) => "sen" === b.key)).toMatchObject({ startMin: kickoff, endMin: kickoff + 105 });
  });

  // Catégorie inconnue du front (durée absente de la table servie) → repli explicite 105, jamais
  // une redérivation de la règle de famille (🔴 `.claude/rules/frontend.md`).
  it("catégorie sans durée servie → repli 105", () => {
    const model = buildTypicalWeekend([habit({ teamId: "team-1" })], TEAMS, new Map());
    const kickoffMin = 15 * 60 + 30;
    expect(model.blocks[0]).toMatchObject({ startMin: kickoffMin, endMin: kickoffMin + 105 });
  });

  it("keeps only weekend ideal slots and lists venue-less ones apart", () => {
    const model = build([
      habit(),
      habit({ id: "h-2", teamId: "team-2", dayOfWeek: 3 }), // Wednesday: not a weekend slot
      habit({ id: "h-3", teamId: "team-3", dayOfWeek: 7, venueId: null }),
    ]);
    expect(model.blocks).toHaveLength(1);
    expect(model.venueless.map((h) => h.id)).toEqual(["h-3"]);
  });

  it("lanes overlapping ideal slots of the same column side by side (a template collision must be SEEN)", () => {
    const model = build([habit(), habit({ id: "h-2", teamId: "team-2", kickoffTime: "16:00" })]);
    const lanes = model.blocks.map((b) => b.lane).sort();
    expect(lanes).toEqual([0, 1]);
    expect(model.blocks.every((b) => 2 === b.laneCount)).toBe(true);
  });

  it("is empty only when NO weekend ideal slot exists at all", () => {
    expect(build([]).empty).toBe(true);
    expect(build([habit({ venueId: null })]).empty).toBe(false); // venue-less still worth showing
  });
});

describe("hasAlternatingWeeks — le club alterne (P4-271)", () => {
  it("aucun tag A/B (tout « toutes ») → pas d'alternance", () => {
    expect(hasAlternatingWeeks([habit(), habit({ id: "h-2", week: "ALL" })])).toBe(false);
  });
  it("au moins un tag A ou B → alternance", () => {
    expect(hasAlternatingWeeks([habit({ week: "A" })])).toBe(true);
    expect(hasAlternatingWeeks([habit(), habit({ id: "h-2", week: "B" })])).toBe(true);
  });
});

describe("buildTypicalWeekend — filtrage par semaine A/B (P4-271)", () => {
  it("la semaine A garde les créneaux tagués A ou « toutes », jamais ceux tagués B", () => {
    const habits = [
      habit({ id: "a", teamId: "t-a", week: "A", venueId: "v", kickoffTime: "13:00" }),
      habit({ id: "b", teamId: "t-b", week: "B", venueId: "v", kickoffTime: "15:00" }),
      habit({ id: "all", teamId: "t-all", week: "ALL", venueId: "v", kickoffTime: "17:00" }),
    ];
    const weekA = build(habits, "A");
    const shownA = weekA.blocks.map((blk) => blk.key).sort();
    expect(shownA).toEqual(["a", "all"]);

    const weekB = build(habits, "B");
    const shownB = weekB.blocks.map((blk) => blk.key).sort();
    expect(shownB).toEqual(["all", "b"]);
  });

  it("sans semaine (vue unique) tous les créneaux sont rendus", () => {
    const habits = [
      habit({ id: "a", teamId: "t-a", week: "A", venueId: "v", kickoffTime: "13:00" }),
      habit({ id: "b", teamId: "t-b", week: "B", venueId: "v", kickoffTime: "15:00" }),
    ];
    expect(build(habits).blocks).toHaveLength(2);
  });
});
