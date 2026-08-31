import { describe, expect, it } from "vitest";

import type { Team, TeamPeriodOverride } from "../api";
import { blockCommonSessionOptions } from "./sharedTrainingBlock";

const team = (over: Partial<Team> & Pick<Team, "id" | "name">): Team => ({
  sportCategoryId: "cat",
  priorityTierId: 1,
  tierOrder: 0,
  gender: null,
  level: null,
  sessionsPerWeek: 2,
  isActive: true,
  ...over,
});

const override = (teamId: string, sessionsPerWeek: number | null): TeamPeriodOverride =>
  ({ teamId, sessionsPerWeek }) as TeamPeriodOverride;

describe("blockCommonSessionOptions — les valeurs de la LISTE DÉROULANTE, bornées 1..min(séances effectives)", () => {
  it("va de 1 au plus petit sessionsPerWeek effectif de la sélection", () => {
    const teams = [team({ id: "a", name: "U9F1", sessionsPerWeek: 3 }), team({ id: "b", name: "U9F2", sessionsPerWeek: 2 })];
    // min(3, 2) = 2 → options [1, 2], jamais 3 (le serveur refuserait 3 pour b).
    expect(blockCommonSessionOptions(teams, new Map())).toEqual([1, 2]);
  });

  it("suit l'override de PÉRIODE quand il RÉDUIT les séances d'un membre", () => {
    const teams = [team({ id: "a", name: "U9F1", sessionsPerWeek: 3 }), team({ id: "b", name: "U9F2", sessionsPerWeek: 3 })];
    const overrides = new Map<string, TeamPeriodOverride>([["b", override("b", 1)]]);
    // b tombe à 1 pour la période → borne = 1 → une seule option.
    expect(blockCommonSessionOptions(teams, overrides)).toEqual([1]);
  });

  it("rend une liste VIDE pour une sélection vide (aucune borne signifiante)", () => {
    expect(blockCommonSessionOptions([], new Map())).toEqual([]);
  });

  it("rend une liste VIDE si un membre a zéro séance effective (rien de posable)", () => {
    const teams = [team({ id: "a", name: "U9F1", sessionsPerWeek: 2 }), team({ id: "b", name: "U9F2", sessionsPerWeek: 0 })];
    expect(blockCommonSessionOptions(teams, new Map())).toEqual([]);
  });
});
