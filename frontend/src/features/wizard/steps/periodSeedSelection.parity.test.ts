import { describe, expect, it } from "vitest";

import casesJson from "./periodSeedSelection.parity.json";
import { periodSeedKeptTeamIds } from "./periodSeedSelection";

/**
 * P2-63 PR 5 (D-e) — CÔTÉ FRONT de la parité mécanique du tri d'équipes par défaut. Les MÊMES
 * cas (`periodSeedSelection.parity.json`) traversent le front (`periodSeedKeptTeamIds`) ET le
 * backend (`PlanTeamSelectionSeeder::keptTeamIds`, via `PeriodSeedSelectionMirrorParityTest`).
 * Changer la règle d'un seul côté rougit ce côté-là.
 */
interface ParityCase {
  name: string;
  tiers: number[];
  teams: { id: string; tierId: number }[];
  isClosure: boolean;
  kept: string[];
}

const cases = (casesJson as { cases: ParityCase[] }).cases;

describe("periodSeedKeptTeamIds — parité avec le backend", () => {
  it("porte au moins un cas", () => {
    expect(cases.length).toBeGreaterThan(0);
  });

  for (const c of cases) {
    it(c.name, () => {
      const kept = periodSeedKeptTeamIds(
        c.teams.map((t) => ({ id: t.id, priorityTierId: t.tierId })),
        c.tiers.map((id) => ({ id })),
        c.isClosure,
      );
      expect([...kept].sort()).toEqual([...c.kept].sort());
    });
  }
});
