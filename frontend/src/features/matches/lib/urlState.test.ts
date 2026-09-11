import { describe, expect, it } from "vitest";

import { applyConsultToParams, applyFilterToParams, applySectionToParams, decodeConsultParams, decodeFilterParams, decodeSectionParam } from "./urlState";

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
  it("params absents ⇒ kinds/families null (= tout), semaine type affichée, temps semaine, mois/phase null", () => {
    expect(decodeConsultParams(new URLSearchParams(""))).toEqual({ kinds: null, families: null, typicalWeek: true, temps: "semaine", month: null, phaseId: null });
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

  it("temps accepte semaine · mois · phase (PR-2b) ; valeur inconnue ⇒ repli semaine", () => {
    expect(decodeConsultParams(new URLSearchParams("temps=mois")).temps).toBe("mois");
    expect(decodeConsultParams(new URLSearchParams("temps=phase")).temps).toBe("phase");
    expect(decodeConsultParams(new URLSearchParams("temps=ghost")).temps).toBe("semaine");
  });

  it("mois = YYYY-MM valide (sinon null) ; phase = id brut", () => {
    expect(decodeConsultParams(new URLSearchParams("mois=2026-10")).month).toBe("2026-10");
    expect(decodeConsultParams(new URLSearchParams("mois=2026-13")).month).toBeNull();
    expect(decodeConsultParams(new URLSearchParams("mois=nope")).month).toBeNull();
    expect(decodeConsultParams(new URLSearchParams("phase=comp-1")).phaseId).toBe("comp-1");
  });
});

describe("applyConsultToParams (PR-2a/2b)", () => {
  it("défauts (tout coché, semaine type, temps semaine) ⇒ aucun param", () => {
    expect(applyConsultToParams(new URLSearchParams(""), { kinds: null, families: null, typicalWeek: true, temps: "semaine", month: null, phaseId: null }).toString()).toBe("");
  });

  it("sélection partielle de types ⇒ type écrit ; semaine type masquée ⇒ type_semaine=0", () => {
    const out = applyConsultToParams(new URLSearchParams(""), { kinds: ["amical", "coupe"], families: null, typicalWeek: false, temps: "semaine", month: null, phaseId: null });
    expect(out.get("type")).toBe("amical,coupe");
    expect(out.get("type_semaine")).toBe("0");
    expect(out.get("conflits")).toBeNull();
  });

  it("familles partielles ⇒ conflits écrit", () => {
    expect(applyConsultToParams(new URLSearchParams(""), { kinds: null, families: ["MATCH_MATCH"], typicalWeek: true, temps: "semaine", month: null, phaseId: null }).get("conflits")).toBe("MATCH_MATCH");
  });

  it("préserve les params sans rapport", () => {
    expect(applyConsultToParams(new URLSearchParams("autre=1"), { kinds: null, families: null, typicalWeek: true, temps: "semaine", month: null, phaseId: null }).get("autre")).toBe("1");
  });

  it("temps mois + mois écrit temps=mois & mois=YYYY-MM (phase absente)", () => {
    const out = applyConsultToParams(new URLSearchParams(""), { kinds: null, families: null, typicalWeek: true, temps: "mois", month: "2026-10", phaseId: "comp-1" });
    expect(out.get("temps")).toBe("mois");
    expect(out.get("mois")).toBe("2026-10");
    expect(out.get("phase")).toBeNull();
  });

  it("temps phase + phaseId écrit temps=phase & phase=<id> (mois absent)", () => {
    const out = applyConsultToParams(new URLSearchParams(""), { kinds: null, families: null, typicalWeek: true, temps: "phase", month: "2026-10", phaseId: "comp-1" });
    expect(out.get("temps")).toBe("phase");
    expect(out.get("phase")).toBe("comp-1");
    expect(out.get("mois")).toBeNull();
  });

  it("temps semaine ⇒ ni temps ni mois ni phase, même si month/phaseId posés", () => {
    const out = applyConsultToParams(new URLSearchParams(""), { kinds: null, families: null, typicalWeek: true, temps: "semaine", month: "2026-10", phaseId: "comp-1" });
    expect(out.get("temps")).toBeNull();
    expect(out.get("mois")).toBeNull();
    expect(out.get("phase")).toBeNull();
  });
});

describe("decodeSectionParam (P4-185 — accordéon Configuration)", () => {
  it("absent ⇒ gabarit (défaut ouvert)", () => {
    expect(decodeSectionParam(new URLSearchParams(""))).toBe("gabarit");
  });

  it("valeur inconnue ⇒ repli sur gabarit", () => {
    expect(decodeSectionParam(new URLSearchParams("section=xxx"))).toBe("gabarit");
  });

  it("« aucune » ⇒ null (tout replié)", () => {
    expect(decodeSectionParam(new URLSearchParams("section=aucune"))).toBeNull();
  });

  it("chacune des 7 clés est reconnue (P4-196 ajoute « libelles »)", () => {
    for (const key of ["gabarit", "creneaux", "echeances", "durees", "adversaires", "reglages", "libelles"] as const) {
      expect(decodeSectionParam(new URLSearchParams(`section=${key}`))).toBe(key);
    }
  });
});

describe("applySectionToParams (P4-185)", () => {
  it("gabarit (défaut) ⇒ param supprimé", () => {
    expect(applySectionToParams(new URLSearchParams(""), "gabarit").toString()).toBe("");
    expect(applySectionToParams(new URLSearchParams("section=reglages"), "gabarit").toString()).toBe("");
  });

  it("null (tout replié) ⇒ section=aucune", () => {
    expect(applySectionToParams(new URLSearchParams(""), null).get("section")).toBe("aucune");
  });

  it("une autre section ⇒ écrite telle quelle", () => {
    expect(applySectionToParams(new URLSearchParams(""), "durees").get("section")).toBe("durees");
  });

  it("« libelles » (P4-196) ⇒ écrite et relue telle quelle", () => {
    expect(applySectionToParams(new URLSearchParams(""), "libelles").get("section")).toBe("libelles");
    expect(decodeSectionParam(applySectionToParams(new URLSearchParams(""), "libelles"))).toBe("libelles");
  });

  it("préserve les params sans rapport", () => {
    const out = applySectionToParams(new URLSearchParams("autre=1"), "creneaux");
    expect(out.get("autre")).toBe("1");
    expect(out.get("section")).toBe("creneaux");
  });

  it("aller-retour cohérent : encode(gabarit) se relit gabarit, encode(null) se relit null", () => {
    expect(decodeSectionParam(applySectionToParams(new URLSearchParams(""), "gabarit"))).toBe("gabarit");
    expect(decodeSectionParam(applySectionToParams(new URLSearchParams(""), null))).toBeNull();
    expect(decodeSectionParam(applySectionToParams(new URLSearchParams(""), "echeances"))).toBe("echeances");
  });
});
