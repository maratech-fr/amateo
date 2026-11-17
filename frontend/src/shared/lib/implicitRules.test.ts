import { describe, expect, it } from "vitest";

import { NEVER_MANDATORY_RULES, isNeverMandatory } from "./implicitRules";

/** Une règle qui ne peut jamais être obligatoire n'offre pas le cran « Obligatoire » : les autres le gardent. */
describe("isNeverMandatory", () => {
  it("désigne la règle des salariés, seule", () => {
    expect([...NEVER_MANDATORY_RULES]).toEqual(["salarieDistribution"]);
    expect(isNeverMandatory("salarieDistribution")).toBe(true);
    expect(isNeverMandatory("coachRestDay")).toBe(false);
  });

  it("un diagnostic sans règle n'est pas une règle sans cran obligatoire", () => {
    expect(isNeverMandatory(null)).toBe(false);
  });
});
