import { describe, expect, it } from "vitest";

import { applyConsultToParams, applyFilterToParams, decodeConsultParams, decodeFilterParams } from "./urlState";

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

describe("decodeConsultParams (PR-2a)", () => {
  it("params absents ⇒ kinds/families null (= tout), semaine type affichée, temps semaine", () => {
    expect(decodeConsultParams(new URLSearchParams(""))).toEqual({ kinds: null, families: null, typicalWeek: true, temps: "semaine" });
  });

  it("type ⇒ liste filtrée sur les valeurs connues, dédoublonnée", () => {
    expect(decodeConsultParams(new URLSearchParams("type=coupe,amical,coupe,ghost")).kinds).toEqual(["coupe", "amical"]);
  });

  it("conflits ⇒ liste de familles filtrée sur les valeurs connues", () => {
    expect(decodeConsultParams(new URLSearchParams("conflits=MATCH_MATCH,GHOST,TEAM_LINK_OVERLAP")).families).toEqual(["MATCH_MATCH", "TEAM_LINK_OVERLAP"]);
  });

  it("type_semaine=0 ⇒ semaine type masquée ; 1/absent ⇒ affichée", () => {
    expect(decodeConsultParams(new URLSearchParams("type_semaine=0")).typicalWeek).toBe(false);
    expect(decodeConsultParams(new URLSearchParams("type_semaine=1")).typicalWeek).toBe(true);
  });

  it("temps n'accepte que semaine (valeur inconnue ⇒ repli semaine)", () => {
    expect(decodeConsultParams(new URLSearchParams("temps=mois")).temps).toBe("semaine");
  });
});

describe("applyConsultToParams (PR-2a)", () => {
  it("défauts (tout coché, semaine type) ⇒ aucun param", () => {
    expect(applyConsultToParams(new URLSearchParams(""), { kinds: null, families: null, typicalWeek: true, temps: "semaine" }).toString()).toBe("");
  });

  it("sélection partielle de types ⇒ type écrit ; semaine type masquée ⇒ type_semaine=0", () => {
    const out = applyConsultToParams(new URLSearchParams(""), { kinds: ["amical", "coupe"], families: null, typicalWeek: false, temps: "semaine" });
    expect(out.get("type")).toBe("amical,coupe");
    expect(out.get("type_semaine")).toBe("0");
    expect(out.get("conflits")).toBeNull();
  });

  it("familles partielles ⇒ conflits écrit", () => {
    expect(applyConsultToParams(new URLSearchParams(""), { kinds: null, families: ["MATCH_MATCH"], typicalWeek: true, temps: "semaine" }).get("conflits")).toBe("MATCH_MATCH");
  });

  it("préserve les params sans rapport", () => {
    expect(applyConsultToParams(new URLSearchParams("autre=1"), { kinds: null, families: null, typicalWeek: true, temps: "semaine" }).get("autre")).toBe("1");
  });
});
