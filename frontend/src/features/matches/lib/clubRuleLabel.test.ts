import { describe, expect, it } from "vitest";

import { clubRuleLabel, frClock } from "./clubRuleLabel";

describe("frClock", () => {
  it("heure pile → « 21h », sans « 00 »", () => {
    expect(frClock("21:00")).toBe("21h");
    expect(frClock("09:00")).toBe("9h");
  });

  it("heure avec minutes → « 21h30 », sans zéro de tête sur l'heure", () => {
    expect(frClock("21:30")).toBe("21h30");
    expect(frClock("09:05")).toBe("9h05");
  });
});

describe("clubRuleLabel", () => {
  it("borne haute seule → « pas après … »", () => {
    expect(clubRuleLabel({ kickoffMin: null, kickoffMax: "21:00" })).toBe("pas après 21h");
  });

  it("borne basse seule → « pas avant … »", () => {
    expect(clubRuleLabel({ kickoffMin: "09:00", kickoffMax: null })).toBe("pas avant 9h");
  });

  it("les deux bornes → « entre … et … »", () => {
    expect(clubRuleLabel({ kickoffMin: "09:00", kickoffMax: "21:30" })).toBe("entre 9h et 21h30");
  });

  it("aucune borne (défensif) → un libellé non vide", () => {
    expect(clubRuleLabel({ kickoffMin: null, kickoffMax: null })).toBe("règle horaire");
  });
});
