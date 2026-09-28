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
 *  startMin=0 pour que gridRowStart = 3 + round(bornes/15). matchMinutesOf = 105,
 *  warmupMinutesOf = 30 (compté avant le départ extérieur, P4-240 ③). */
const layout = (awayFixtures: Fixture[]): AwayLayout => ({
  awayFixtures,
  teams,
  habits: [],
  matchMinutesOf: () => 105,
  warmupMinutesOf: () => 30,
  columnIndex: new Map([["2026-10-03:away", 0]]),
  startMin: 0,
  stepMin: 15,
  bandRows: 0,
});

describe("buildAwayCells — bornes DESSINÉES du bloc extérieur (correctif 10 + échauffement P4-240 ③)", () => {
  it("trajet connu : le bloc couvre départ (échauffement + aller) → retour, pas seulement le match", () => {
    const intervals: { startMin: number; endMin: number; cell: WeekendCell }[] = [];
    // 18:00 = 1080, match 105 → fin 19:45 = 1185 ; échauffement 30 + aller 45 → départ 16:45 = 1005,
    // retour 20:30 = 1230.
    const cells = buildAwayCells(layout([away({ kickoffTime: "18:00", awayTravel: travel() })]), intervals);
    const cell = cells[0];
    // gridRowStart = 3 + round(1005/15) = 3 + 67 = 70 ; span = round((1230-1005)/15) = 15.
    expect(cell.gridRowStart).toBe(70);
    expect(cell.gridRowSpan).toBe(15);
    expect(cell.kickoffLabel).toBe("18:00");
    expect(cell.hasTravel).toBe(true);
    expect(cell.departureLabel).toBe("16:45");
    expect(cell.returnLabel).toBe("20:30");
    expect(cell.travelOneWayMin).toBe(45);
    expect(cell.matchSpanMin).toBe(105);
    // Le couloir se calcule sur l'étendue COMPLÈTE (départ → retour), pas sur le match seul.
    expect(intervals).toHaveLength(1);
    expect(intervals[0]).toMatchObject({ startMin: 1005, endMin: 1230 });
  });

  it("trajet inconnu : le bloc s'étend de l'échauffement (départ = coup d'envoi − échauffement), pas de retour trajet", () => {
    const intervals: { startMin: number; endMin: number; cell: WeekendCell }[] = [];
    const cells = buildAwayCells(layout([away({ kickoffTime: "18:00", awayTravel: null })]), intervals);
    const cell = cells[0];
    // 18:00 = 1080, échauffement 30 → départ 17:30 = 1050 ; retour = fin du match 1185.
    // gridRowStart = 3 + round(1050/15) = 3 + 70 = 73 ; span = round((1185-1050)/15) = 9.
    expect(cell.gridRowStart).toBe(73);
    expect(cell.gridRowSpan).toBe(9);
    expect(cell.hasTravel).toBe(false);
    expect(cell.departureLabel ?? null).toBeNull();
    expect(cell.returnLabel ?? null).toBeNull();
    expect(intervals[0]).toMatchObject({ startMin: 1050, endMin: 1185 });
  });
});
