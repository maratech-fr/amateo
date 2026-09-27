import { describe, expect, it } from "vitest";

import type { AwayTravel, Fixture, Team } from "../api";
import { type AwayLayout, buildAwayCells } from "./awayColumn";
import type { WeekendCell } from "./weekendGrid";

const teams = new Map<string, Team>([["team-1", { id: "team-1", name: "U13", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 3, tierOrder: 0 }]]);

const away = (over: Partial<Fixture> = {}): Fixture => ({
  id: "ax",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03",
  homeAway: "AWAY",
  opponentLabel: "Épinouze",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null,
  reviewState: "NEW",
  reviewedAt: null,
  pendingDeviations: [],
  ffbbRencontreId: null,
  suggestedVenueId: null,
  opponentOrganismeCode: null,
  opponentTeamKey: null,
  fbiEcho: null,
  awayTravel: null,
  ...over,
});

const travel = (over: Partial<AwayTravel> = {}): AwayTravel => ({
  venueLabel: "Halle Y",
  city: null,
  precision: "VENUE",
  oneWayMinutes: 45,
  approximated: false,
  basis: "linked",
  ...over,
});

/** Layout minimal : une seule colonne « Extérieur » (`2026-10-03:away` → index 0),
 *  startMin=0 pour que gridRowStart = 3 + round(bornes/15). matchMinutesOf = 105. */
const layout = (awayFixtures: Fixture[]): AwayLayout => ({
  awayFixtures,
  teams,
  habits: [],
  matchMinutesOf: () => 105,
  columnIndex: new Map([["2026-10-03:away", 0]]),
  startMin: 0,
  stepMin: 15,
  bandRows: 0,
});

describe("buildAwayCells — bornes DESSINÉES du bloc extérieur (correctif 10)", () => {
  it("trajet connu : le bloc couvre départ → retour, pas seulement le match", () => {
    const intervals: { startMin: number; endMin: number; cell: WeekendCell }[] = [];
    // 18:00 = 1080, match 105 → fin 19:45 = 1185 ; aller 45 → départ 17:15 = 1035, retour 20:30 = 1230.
    const cells = buildAwayCells(layout([away({ kickoffTime: "18:00", awayTravel: travel() })]), intervals);
    const cell = cells[0];
    // gridRowStart = 3 + round(1035/15) = 3 + 69 = 72 ; span = round((1230-1035)/15) = 13.
    expect(cell.gridRowStart).toBe(72);
    expect(cell.gridRowSpan).toBe(13);
    expect(cell.kickoffLabel).toBe("18:00");
    expect(cell.hasTravel).toBe(true);
    expect(cell.departureLabel).toBe("17:15");
    expect(cell.returnLabel).toBe("20:30");
    expect(cell.travelOneWayMin).toBe(45);
    expect(cell.matchSpanMin).toBe(105);
    // Le couloir se calcule sur l'étendue COMPLÈTE (départ → retour), pas sur le match seul.
    expect(intervals).toHaveLength(1);
    expect(intervals[0]).toMatchObject({ startMin: 1035, endMin: 1230 });
  });

  it("trajet inconnu : le bloc se réduit au match, pas de départ/retour", () => {
    const intervals: { startMin: number; endMin: number; cell: WeekendCell }[] = [];
    const cells = buildAwayCells(layout([away({ kickoffTime: "18:00", awayTravel: null })]), intervals);
    const cell = cells[0];
    // 18:00 = 1080 → gridRowStart = 3 + 72 = 75 ; span = round(105/15) = 7.
    expect(cell.gridRowStart).toBe(75);
    expect(cell.gridRowSpan).toBe(7);
    expect(cell.hasTravel).toBe(false);
    expect(cell.departureLabel ?? null).toBeNull();
    expect(cell.returnLabel ?? null).toBeNull();
    expect(intervals[0]).toMatchObject({ startMin: 1080, endMin: 1185 });
  });
});
