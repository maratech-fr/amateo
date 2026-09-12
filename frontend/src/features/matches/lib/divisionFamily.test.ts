import { describe, expect, it } from "vitest";

import { classifyDivision, FAMILY_ORDER, type DivisionFamily } from "./divisionFamily";

describe("classifyDivision", () => {
  // Le brassage l'emporte sur régional/départemental : c'est la 1re règle.
  it("range un brassage dans brassage, jamais dans régional", () => {
    expect(classifyDivision("RMU13 Brassage")).toBe<DivisionFamily>("brassage");
    // espace finale : la normalisation la trime.
    expect(classifyDivision("RFU13 Brassage ")).toBe<DivisionFamily>("brassage");
  });

  it("range une coupe ARA (ordre des mots indifférent) dans coupeAra", () => {
    expect(classifyDivision("ARA COUPE SM")).toBe<DivisionFamily>("coupeAra");
    expect(classifyDivision("COUPE ARA U18M")).toBe<DivisionFamily>("coupeAra");
    expect(classifyDivision("COUPE ARA U18F")).toBe<DivisionFamily>("coupeAra");
  });

  it("range les coupes CRM, coquille fédérale CMRL comprise", () => {
    expect(classifyDivision("CMRLU13M")).toBe<DivisionFamily>("coupesCrm"); // coquille fédérale
    expect(classifyDivision("CRMLSM")).toBe<DivisionFamily>("coupesCrm");
    expect(classifyDivision("CRMLU21M")).toBe<DivisionFamily>("coupesCrm");
  });

  it("range les amicaux dans amicaux", () => {
    expect(classifyDivision("Amical PNM")).toBe<DivisionFamily>("amicaux");
    expect(classifyDivision("AMICAL SF")).toBe<DivisionFamily>("amicaux");
    expect(classifyDivision("amical NF3")).toBe<DivisionFamily>("amicaux");
  });

  it("range le départemental (D… / PR…)", () => {
    for (const name of ["PRM", "DF2", "DMVE", "DFLOI", "DMU21", "DFU13-3", "DMU9-2"]) {
      expect(classifyDivision(name)).toBe<DivisionFamily>("departemental");
    }
  });

  it("range le régional (R… / PN…), sans confondre avec le brassage", () => {
    for (const name of ["PNF", "PNM", "RMU21", "RF3", "RM2"]) {
      expect(classifyDivision(name)).toBe<DivisionFamily>("regional");
    }
  });

  it("range l'inconnu dans autres — une division ne disparaît jamais", () => {
    expect(classifyDivision("TROPHEE JEUNES")).toBe<DivisionFamily>("autres");
    expect(classifyDivision("TROPHEE X")).toBe<DivisionFamily>("autres");
  });

  it("retourne TOUJOURS une famille valide, quelle que soit la chaîne", () => {
    const samples = ["", "   ", "!!!", "123", "@#$%", "é", "ZZZ", "coupe", "ara", "crm", "pn", "pr", "d", "r", "brassage", "🏀", "COUPE", "x-y_z"];
    for (const sample of samples) {
      expect(FAMILY_ORDER).toContain(classifyDivision(sample));
    }
  });
});
