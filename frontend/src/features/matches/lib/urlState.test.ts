import { describe, expect, it } from "vitest";

import { applyFilterToParams, decodeFilterParams } from "./urlState";

describe("decodeFilterParams", () => {
  it("params absents ⇒ equipe / aucune sélection", () => {
    expect(decodeFilterParams(new URLSearchParams(""))).toEqual({ mode: "equipe", ids: [] });
  });

  it("lit vue + filtre (virgules), dédoublonne en préservant l'ordre", () => {
    expect(decodeFilterParams(new URLSearchParams("vue=coach&filtre=b,a,b"))).toEqual({ mode: "coach", ids: ["b", "a"] });
  });

  it("vue inconnue ⇒ repli sur equipe", () => {
    expect(decodeFilterParams(new URLSearchParams("vue=xxx&filtre=a")).mode).toBe("equipe");
  });

  it("ignore silencieusement les ids inconnus quand validIds est fourni", () => {
    expect(decodeFilterParams(new URLSearchParams("vue=gymnase&filtre=v1,ghost,v2"), new Set(["v1", "v2"]))).toEqual({ mode: "gymnase", ids: ["v1", "v2"] });
  });
});

describe("applyFilterToParams", () => {
  it("equipe + aucune sélection ⇒ ni vue ni filtre", () => {
    expect(applyFilterToParams(new URLSearchParams(""), "equipe", []).toString()).toBe("");
  });

  it("coach sans sélection ⇒ vue conservée, filtre absent", () => {
    expect(applyFilterToParams(new URLSearchParams(""), "coach", []).toString()).toBe("vue=coach");
  });

  it("gymnase + ids ⇒ vue et filtre écrits", () => {
    expect(applyFilterToParams(new URLSearchParams(""), "gymnase", ["v1", "v2"]).toString()).toBe("vue=gymnase&filtre=v1%2Cv2");
  });

  it("préserve les params sans rapport", () => {
    const out = applyFilterToParams(new URLSearchParams("autre=1"), "coach", ["c1"]);
    expect(out.get("autre")).toBe("1");
    expect(out.get("vue")).toBe("coach");
    expect(out.get("filtre")).toBe("c1");
  });
});
