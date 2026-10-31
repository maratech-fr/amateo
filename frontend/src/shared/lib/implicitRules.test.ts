import { describe, expect, it } from "vitest";

import { ALWAYS_OBJECTIVE_RULES, isAlwaysObjective } from "./implicitRules";

/** Une règle que le moteur traite toujours en objectif n'offre aucun réglage à l'écran : les autres gardent le leur. */
describe("isAlwaysObjective", () => {
  it("désigne la règle des salariés, seule", () => {
    expect([...ALWAYS_OBJECTIVE_RULES]).toEqual(["salarieDistribution"]);
    expect(isAlwaysObjective("salarieDistribution")).toBe(true);
    expect(isAlwaysObjective("coachRestDay")).toBe(false);
  });

  it("un diagnostic sans règle n'est pas une règle sans réglage", () => {
    expect(isAlwaysObjective(null)).toBe(false);
  });
});
