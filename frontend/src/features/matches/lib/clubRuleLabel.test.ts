import { describe, expect, it } from "vitest";

import { clockLabel, clubRuleLabel } from "./clubRuleLabel";

describe("clockLabel (format horaire unique « 21:00 », N3)", () => {
  it("heure pile → « 21:00 » (zéro de tête conservé)", () => {
    expect(clockLabel("21:00")).toBe("21:00");
    expect(clockLabel("09:00")).toBe("09:00");
  });

  it("heure avec minutes → « 21:30 » / « 09:05 »", () => {
    expect(clockLabel("21:30")).toBe("21:30");
    expect(clockLabel("09:05")).toBe("09:05");
  });

  it("borne portant les secondes → normalisée en « HH:MM »", () => {
    expect(clockLabel("18:30:00")).toBe("18:30");
  });
});

describe("clubRuleLabel", () => {
  it("borne haute seule → « pas après … »", () => {
    expect(clubRuleLabel({ kickoffMin: null, kickoffMax: "21:00" })).toBe("pas après 21:00");
  });

  it("borne basse seule → « pas avant … »", () => {
    expect(clubRuleLabel({ kickoffMin: "09:00", kickoffMax: null })).toBe("pas avant 09:00");
  });

  it("les deux bornes → « entre … et … »", () => {
    expect(clubRuleLabel({ kickoffMin: "09:00", kickoffMax: "21:30" })).toBe("entre 09:00 et 21:30");
  });

  it("aucune borne (défensif) → un libellé non vide", () => {
    expect(clubRuleLabel({ kickoffMin: null, kickoffMax: null })).toBe("règle horaire");
  });
});
