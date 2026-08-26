import { describe, expect, it } from "vitest";

import type { Fixture, Team, Venue } from "../api";
import { buildWeekendGrid, isPlacedOnGrid, listWeekends, weekendKeyOf, weekLabel } from "./weekendGrid";

const fixture = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-1",
  teamId: "team-1",
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
  unplacedReason: null,  ...over,
});

const venues = new Map<string, Venue>([["venue-1", { id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }]]);
const teams = new Map<string, Team>([["team-1", { id: "team-1", name: "U13", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 3, tierOrder: 0 }]]);

describe("weekendKeyOf", () => {
  it("buckets Saturday and its Sunday into the same weekend (the Saturday)", () => {
    expect(weekendKeyOf("2026-10-03")).toBe("2026-10-03"); // Saturday
    expect(weekendKeyOf("2026-10-04")).toBe("2026-10-03"); // Sunday → same weekend
  });
});

describe("isPlacedOnGrid", () => {
  it("is true only for home fixtures with venue + kickoff", () => {
    expect(isPlacedOnGrid(fixture())).toBe(true);
    expect(isPlacedOnGrid(fixture({ kickoffTime: null }))).toBe(false);
    expect(isPlacedOnGrid(fixture({ venueId: null }))).toBe(false);
    expect(isPlacedOnGrid(fixture({ homeAway: "AWAY" }))).toBe(false);
  });
});

describe("listWeekends", () => {
  it("returns sorted distinct weekend buckets", () => {
    const list = listWeekends([fixture(), fixture({ id: "fx-2", matchDate: "2026-10-04" }), fixture({ id: "fx-3", matchDate: "2026-10-10" })]);
    expect(list).toEqual(["2026-10-03", "2026-10-10"]);
  });
});

describe("buildWeekendGrid", () => {
  it("is empty when no home fixture is placed", () => {
    const grid = buildWeekendGrid([fixture({ kickoffTime: null })], venues, teams);
    expect(grid.empty).toBe(true);
    expect(grid.cells).toHaveLength(0);
  });

  it("lays a placed match as a 2h15 footprint block in its date×venue column", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams);
    expect(grid.empty).toBe(false);
    expect(grid.columns).toHaveLength(1);
    expect(grid.dateGroups[0].dateKey).toBe("2026-10-03");
    expect(grid.cells).toHaveLength(1);
    const cell = grid.cells[0];
    // 16:00 kickoff → footprint 15:30–17:45 = 135 min = 9 steps of 15 min.
    expect(cell.kickoffLabel).toBe("16:00");
    expect(cell.footprintLabel).toBe("15:30–17:45");
    expect(cell.gridRowSpan).toBe(9);
    expect(cell.outOfEnvelope).toBe(false);
  });

  it("marks a fixture flagged out of envelope", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams, new Set(["fx-1"]));
    expect(grid.cells[0].outOfEnvelope).toBe(true);
  });

  it("puts two overlapping matches of the same venue in separate lanes", () => {
    const grid = buildWeekendGrid([fixture(), fixture({ id: "fx-2", kickoffTime: "16:30", opponentLabel: "Adv2" })], venues, teams);
    expect(grid.cells).toHaveLength(2);
    expect(grid.cells.map((c) => c.laneCount)).toEqual([2, 2]);
    expect(new Set(grid.cells.map((c) => c.lane))).toEqual(new Set([0, 1]));
  });
});

describe("lock badge (P1-4 PR E1)", () => {
  it("marks MANUAL and legacy-null placements as locked anchors, SOLVER as free", () => {
    const grid = buildWeekendGrid(
      [fixture({ id: "m", placementSource: "MANUAL" }), fixture({ id: "n", kickoffTime: "18:00", placementSource: null }), fixture({ id: "s", kickoffTime: "20:00", placementSource: "SOLVER" })],
      venues,
      teams,
    );
    const byId = new Map(grid.cells.map((c) => [c.fixtureId, c.locked]));
    expect(byId.get("m")).toBe(true);
    expect(byId.get("n")).toBe(true); // null legacy = manual: never move what we cannot attribute
    expect(byId.get("s")).toBe(false);
  });
});

describe("habit ghosts (P1-4 PR C)", () => {
  const habit = (over: Partial<import("../api").TeamMatchHabit> = {}): import("../api").TeamMatchHabit => ({
    id: "h-1",
    teamId: "team-1",
    dayOfWeek: 6, // Saturday
    kickoffTime: "15:30",
    venueId: "venue-1",
    ...over,
  });

  it("renders a translucent ghost on the habit's weekday when the team has no fixture", () => {
    const grid = buildWeekendGrid([], venues, teams, new Set(), [habit()], "2026-10-03");
    expect(grid.empty).toBe(false);
    expect(grid.cells).toHaveLength(1);
    expect(grid.cells[0].ghost).toBe(true);
    expect(grid.cells[0].teamLabel).toBe("U13");
    expect(grid.cells[0].kickoffLabel).toBe("15:30");
    // The ghost creates its date/venue column.
    expect(grid.columns).toHaveLength(1);
    expect(grid.columns[0].dateKey).toBe("2026-10-03");
  });

  it("dissolves the ghost when ANY fixture of the team sits on that date — away frees the slot", () => {
    const away = fixture({ id: "fx-away", homeAway: "AWAY", venueId: null, kickoffTime: null, matchDate: "2026-10-03" });
    const grid = buildWeekendGrid([away], venues, teams, new Set(), [habit()], "2026-10-03");
    expect(grid.cells.filter((c) => c.ghost)).toHaveLength(0);
  });

  it("skips venue-less habits (the grid is venue-columned) and never blocks real cells", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams, new Set(), [habit({ teamId: "team-ghost", venueId: null })], "2026-10-03");
    expect(grid.cells.filter((c) => c.ghost)).toHaveLength(0);
    expect(grid.cells).toHaveLength(1);
  });

  it("lays a ghost and a real match of the same venue side by side (lanes)", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams, new Set(), [habit({ teamId: "team-ghost", kickoffTime: "16:00" })], "2026-10-03");
    const ghost = grid.cells.find((c) => c.ghost);
    const real = grid.cells.find((c) => !c.ghost);
    expect(ghost?.laneCount).toBe(2);
    expect(real?.laneCount).toBe(2);
    expect(ghost?.lane).not.toBe(real?.lane);
  });

  it("RMM-1 PR3 — la cellule PORTE le n° de rencontre (externalRef), null pour un ghost", () => {
    const grid = buildWeekendGrid([fixture({ externalRef: "26" })], venues, teams, new Set(), [habit({ teamId: "team-ghost", kickoffTime: "16:00" })], "2026-10-03");
    expect(grid.cells.find((c) => !c.ghost)?.externalRef).toBe("26");
    expect(grid.cells.find((c) => c.ghost)?.externalRef).toBeNull();
  });
});

describe("weekLabel — l'axe SEMAINE (L7)", () => {
  it("étiquette lundi→dimanche de la semaine du samedi bucket", () => {
    // 2026-10-03 est un samedi → semaine du lundi 28 sept. au dimanche 4 oct.
    expect(weekLabel("2026-10-03")).toBe("Semaine du 28 sept. au 4 oct.");
  });
});
