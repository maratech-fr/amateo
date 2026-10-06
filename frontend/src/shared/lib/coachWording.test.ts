import { describe, expect, it } from "vitest";

import {
  capitalizeWord,
  COACH_GENDER_OPTIONS,
  coachPlayerWord,
  playerWord,
  retiredWord,
  salariedWord,
  vehicledWord,
} from "./coachWording";

describe("coachWording", () => {
  it("accorde le mot « joueur » selon le genre", () => {
    expect(playerWord("FEMALE")).toBe("joueuse");
    expect(playerWord("MALE")).toBe("joueur");
    expect(playerWord("UNSPECIFIED")).toBe("joueur·euse");
  });

  it("rend la double forme au point médian pour chaque mot quand le genre n'est pas précisé", () => {
    expect(salariedWord("UNSPECIFIED")).toBe("salarié·e");
    expect(coachPlayerWord("UNSPECIFIED")).toBe("coach-joueur·euse");
    expect(retiredWord("UNSPECIFIED")).toBe("retiré·e");
    expect(vehicledWord("UNSPECIFIED")).toBe("véhiculé·e");
  });

  it("accorde au féminin et au masculin", () => {
    expect(salariedWord("FEMALE")).toBe("salariée");
    expect(salariedWord("MALE")).toBe("salarié");
    expect(coachPlayerWord("FEMALE")).toBe("coach-joueuse");
    expect(retiredWord("FEMALE")).toBe("retirée");
    expect(vehicledWord("FEMALE")).toBe("véhiculée");
  });

  it("met la première lettre en capitale sans casser le point médian", () => {
    expect(capitalizeWord(salariedWord("UNSPECIFIED"))).toBe("Salarié·e");
    expect(capitalizeWord(vehicledWord("FEMALE"))).toBe("Véhiculée");
    expect(capitalizeWord("")).toBe("");
  });

  it("offre Non précisé / Femme / Homme dans cet ordre", () => {
    expect(COACH_GENDER_OPTIONS.map((o) => o.value)).toEqual(["UNSPECIFIED", "FEMALE", "MALE"]);
    expect(COACH_GENDER_OPTIONS.map((o) => o.label)).toEqual(["Non précisé", "Femme", "Homme"]);
  });
});
