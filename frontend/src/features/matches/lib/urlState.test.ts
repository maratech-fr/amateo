import { describe, expect, it } from "vitest";

import { applyConflictsToParams, applyConsultToParams, applyFbiToParams, applyFilterToParams, applyMatchToParams, applyOpponentFilterToParams, applySectionToParams, applyWeekendToParams, decodeConflictsParams, decodeConsultParams, decodeFbiParam, decodeFilterParams, decodeMatchParam, decodeOpponentFilter, decodeSectionParam, decodeWeekendParam, hasConsultParams } from "./urlState";

describe("decodeMatchParam / applyMatchToParams (deep-link match=)", () => {
  it("absent ou vide ⇒ null", () => {
    expect(decodeMatchParam(new URLSearchParams(""))).toBeNull();
    expect(decodeMatchParam(new URLSearchParams("match="))).toBeNull();
  });

  it("un fixtureId ⇒ conservé", () => {
    expect(decodeMatchParam(new URLSearchParams("match=fx-42"))).toBe("fx-42");
  });

  it("null ⇒ match absent ; un id ⇒ écrit ; autres params préservés", () => {
    expect(applyMatchToParams(new URLSearchParams("match=fx-42"), null).toString()).toBe("");
    const out = applyMatchToParams(new URLSearchParams("semaine=2026-10-03"), "fx-7");
    expect(out.get("semaine")).toBe("2026-10-03");
    expect(out.get("match")).toBe("fx-7");
  });
});

describe("decodeWeekendParam / applyWeekendToParams (PR 3b — semaine=)", () => {
  it("absent ⇒ null (auto)", () => {
    expect(decodeWeekendParam(new URLSearchParams(""))).toBeNull();
  });

  it("date ISO valide ⇒ conservée", () => {
    expect(decodeWeekendParam(new URLSearchParams("semaine=2026-10-03"))).toBe("2026-10-03");
  });

  it("valeur mal formée ⇒ null (retombe sur l'auto)", () => {
    expect(decodeWeekendParam(new URLSearchParams("semaine=lundi"))).toBeNull();
    expect(decodeWeekendParam(new URLSearchParams("semaine=2026-13"))).toBeNull();
  });

  it("null ⇒ semaine absente ; une clé ⇒ écrite ; autres params préservés", () => {
    expect(applyWeekendToParams(new URLSearchParams("semaine=2026-10-03"), null).toString()).toBe("");
    const out = applyWeekendToParams(new URLSearchParams("vue=coach"), "2026-10-03");
    expect(out.get("vue")).toBe("coach");
    expect(out.get("semaine")).toBe("2026-10-03");
  });
});

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

describe("decodeConsultParams (A — défauts + extérieurs)", () => {
  it("params absents ⇒ kinds/families null, semaine type MASQUÉE, extérieurs MASQUÉS, temps semaine, mois/phase null", () => {
    expect(decodeConsultParams(new URLSearchParams(""))).toEqual({ kinds: null, families: null, typicalWeek: false, away: false, temps: "semaine", month: null, phaseId: null });
  });

  it("type ⇒ liste filtrée sur les valeurs connues, dédoublonnée ; type= vide ⇒ [] (zéro coché)", () => {
    expect(decodeConsultParams(new URLSearchParams("type=coupe,amical,coupe,ghost")).kinds).toEqual(["coupe", "amical"]);
    expect(decodeConsultParams(new URLSearchParams("type=")).kinds).toEqual([]);
  });

  it("conflits ⇒ liste de familles filtrée sur les valeurs connues (une famille fictive ET l'ancienne Passerelle retirée sont ignorées)", () => {
    // GHOST prouve le mécanisme générique ; TEAM_LINK_OVERLAP est un ANCIEN lien profond
    // dont la famille a disparu (backend + contrat) — il doit s'ignorer proprement, comme GHOST.
    expect(decodeConsultParams(new URLSearchParams("conflits=MATCH_MATCH,GHOST,TEAM_LINK_OVERLAP,VENUE_OVERLAP")).families).toEqual(["MATCH_MATCH", "VENUE_OVERLAP"]);
  });

  it("type_semaine INVERSÉ : 1 ⇒ affichée ; absent/0 ⇒ masquée", () => {
    expect(decodeConsultParams(new URLSearchParams("type_semaine=1")).typicalWeek).toBe(true);
    expect(decodeConsultParams(new URLSearchParams("type_semaine=0")).typicalWeek).toBe(false);
    expect(decodeConsultParams(new URLSearchParams("")).typicalWeek).toBe(false);
  });

  it("exterieurs=1 ⇒ affichés ; absent ⇒ masqués", () => {
    expect(decodeConsultParams(new URLSearchParams("exterieurs=1")).away).toBe(true);
    expect(decodeConsultParams(new URLSearchParams("")).away).toBe(false);
  });

  it("temps accepte semaine · mois · phase ; valeur inconnue ⇒ repli semaine", () => {
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

describe("applyConsultToParams (A — type défauts, type_semaine/exterieurs inversés)", () => {
  const base = { kinds: null, families: null, typicalWeek: false, away: false, temps: "semaine" as const, month: null, phaseId: null };

  it("défauts (kinds null, semaine type éteinte, extérieurs éteints, temps semaine) ⇒ aucun param", () => {
    expect(applyConsultToParams(new URLSearchParams(""), base).toString()).toBe("");
  });

  it("kinds = les DÉFAUTS (3) ⇒ type absent ; kinds explicites (dont les 4) ⇒ type écrit", () => {
    expect(applyConsultToParams(new URLSearchParams(""), { ...base, kinds: ["championnat", "coupe", "brassage"] }).has("type")).toBe(false);
    expect(applyConsultToParams(new URLSearchParams(""), { ...base, kinds: ["amical", "championnat", "coupe", "brassage"] }).get("type")).toBe("amical,championnat,coupe,brassage");
    expect(applyConsultToParams(new URLSearchParams(""), { ...base, kinds: ["amical", "coupe"] }).get("type")).toBe("amical,coupe");
  });

  it("zéro coché ⇒ type= vide", () => {
    expect(applyConsultToParams(new URLSearchParams(""), { ...base, kinds: [] }).toString()).toBe("type=");
  });

  it("semaine type affichée ⇒ type_semaine=1 ; extérieurs affichés ⇒ exterieurs=1", () => {
    const out = applyConsultToParams(new URLSearchParams(""), { ...base, typicalWeek: true, away: true });
    expect(out.get("type_semaine")).toBe("1");
    expect(out.get("exterieurs")).toBe("1");
  });

  it("un ancien type_semaine=0 se réécrit en absence", () => {
    expect(applyConsultToParams(new URLSearchParams("type_semaine=0"), base).has("type_semaine")).toBe(false);
  });

  it("familles partielles ⇒ conflits écrit ; préserve les params sans rapport", () => {
    expect(applyConsultToParams(new URLSearchParams(""), { ...base, families: ["MATCH_MATCH"] }).get("conflits")).toBe("MATCH_MATCH");
    expect(applyConsultToParams(new URLSearchParams("autre=1"), base).get("autre")).toBe("1");
  });

  it("temps mois + mois écrit temps=mois & mois=YYYY-MM (phase absente)", () => {
    const out = applyConsultToParams(new URLSearchParams(""), { ...base, temps: "mois", month: "2026-10", phaseId: "comp-1" });
    expect(out.get("temps")).toBe("mois");
    expect(out.get("mois")).toBe("2026-10");
    expect(out.get("phase")).toBeNull();
  });

  it("temps semaine ⇒ ni temps ni mois ni phase, même si month/phaseId posés", () => {
    const out = applyConsultToParams(new URLSearchParams(""), { ...base, month: "2026-10", phaseId: "comp-1" });
    expect(out.get("temps")).toBeNull();
    expect(out.get("mois")).toBeNull();
    expect(out.get("phase")).toBeNull();
  });

  it("aller-retour : legacy type_semaine=0 décode false et se réécrit en absence", () => {
    const decoded = decodeConsultParams(new URLSearchParams("type_semaine=0"));
    expect(decoded.typicalWeek).toBe(false);
    expect(applyConsultToParams(new URLSearchParams("type_semaine=0"), decoded).has("type_semaine")).toBe(false);
  });
});

describe("hasConsultParams (mémoire de session — l'URL porte-t-elle une clé Consulter ?)", () => {
  it("chacune des 7 clés Consulter ⇒ true", () => {
    for (const key of ["type", "conflits", "type_semaine", "exterieurs", "temps", "mois", "phase"]) {
      expect(hasConsultParams(new URLSearchParams(`${key}=x`))).toBe(true);
    }
  });

  it("aucune clé ⇒ false", () => {
    expect(hasConsultParams(new URLSearchParams(""))).toBe(false);
  });

  it("clés étrangères seules (semaine · vue/mode · filtre/ids) ⇒ false", () => {
    expect(hasConsultParams(new URLSearchParams("semaine=2027-03-13"))).toBe(false);
    expect(hasConsultParams(new URLSearchParams("vue=coach&filtre=a,b"))).toBe(false);
    expect(hasConsultParams(new URLSearchParams("mode=x&ids=1"))).toBe(false);
    expect(hasConsultParams(new URLSearchParams("semaine=2027-03-13&vue=coach"))).toBe(false);
  });

  it("une clé Consulter au milieu de clés étrangères ⇒ true", () => {
    expect(hasConsultParams(new URLSearchParams("semaine=2027-03-13&exterieurs=1"))).toBe(true);
  });
});

describe("decodeSectionParam (PR 2a — accordéon Configuration, défaut tout replié)", () => {
  it("absent ⇒ null (rien d'ouvert par défaut)", () => {
    expect(decodeSectionParam(new URLSearchParams(""))).toBeNull();
  });

  it("valeur inconnue ⇒ null", () => {
    expect(decodeSectionParam(new URLSearchParams("section=xxx"))).toBeNull();
  });

  it("« aucune » (ancien encodage) ⇒ null (toléré)", () => {
    expect(decodeSectionParam(new URLSearchParams("section=aucune"))).toBeNull();
  });

  it("les clés déplacées « gabarit »/« creneaux »/« adversaires » ⇒ null (la page redirige — Semaine type / onglet Adversaires, C8)", () => {
    expect(decodeSectionParam(new URLSearchParams("section=gabarit"))).toBeNull();
    expect(decodeSectionParam(new URLSearchParams("section=creneaux"))).toBeNull();
    expect(decodeSectionParam(new URLSearchParams("section=adversaires"))).toBeNull();
  });

  it("chacune des 4 clés RÉGLAGE est reconnue", () => {
    for (const key of ["echeances", "durees", "reglages", "libelles"] as const) {
      expect(decodeSectionParam(new URLSearchParams(`section=${key}`))).toBe(key);
    }
  });
});

describe("applySectionToParams (PR 2a)", () => {
  it("null (tout replié = défaut) ⇒ param supprimé", () => {
    expect(applySectionToParams(new URLSearchParams(""), null).toString()).toBe("");
    expect(applySectionToParams(new URLSearchParams("section=reglages"), null).toString()).toBe("");
  });

  it("une section ⇒ écrite telle quelle", () => {
    expect(applySectionToParams(new URLSearchParams(""), "durees").get("section")).toBe("durees");
  });

  it("« libelles » ⇒ écrite et relue telle quelle", () => {
    expect(applySectionToParams(new URLSearchParams(""), "libelles").get("section")).toBe("libelles");
    expect(decodeSectionParam(applySectionToParams(new URLSearchParams(""), "libelles"))).toBe("libelles");
  });

  it("préserve les params sans rapport", () => {
    const out = applySectionToParams(new URLSearchParams("autre=1"), "reglages");
    expect(out.get("autre")).toBe("1");
    expect(out.get("section")).toBe("reglages");
  });

  it("aller-retour cohérent : encode(null) se relit null, encode(echeances) se relit echeances", () => {
    expect(decodeSectionParam(applySectionToParams(new URLSearchParams(""), null))).toBeNull();
    expect(decodeSectionParam(applySectionToParams(new URLSearchParams(""), "echeances"))).toBe("echeances");
  });
});

describe("decodeConflictsParams (onglet Conflits, B)", () => {
  it("params absents ⇒ pivot coach, familles null, treatments null, domicile false", () => {
    expect(decodeConflictsParams(new URLSearchParams(""))).toEqual({ pivot: "coach", families: null, treatments: null, homeOnly: false });
  });

  it("lit pivot + conflits (familles), pivot inconnu ⇒ repli coach", () => {
    expect(decodeConflictsParams(new URLSearchParams("pivot=gymnase")).pivot).toBe("gymnase");
    expect(decodeConflictsParams(new URLSearchParams("pivot=xxx")).pivot).toBe("coach");
    expect(decodeConflictsParams(new URLSearchParams("conflits=MATCH_MATCH,VENUE_OVERLAP")).families).toEqual(["MATCH_MATCH", "VENUE_OVERLAP"]);
  });

  it("traitement = liste de slugs → clés (slug inconnu ignoré, dédoublonné)", () => {
    expect(decodeConflictsParams(new URLSearchParams("traitement=a_traiter,derogation,ghost,derogation")).treatments).toEqual(["a_traiter", "DEROGATION_REQUESTED"]);
    expect(decodeConflictsParams(new URLSearchParams("traitement=regle_interne,sans_solution")).treatments).toEqual(["RESOLVED_INTERNALLY", "NO_SOLUTION_YET"]);
  });

  it("traitement : les 3 slugs propres à une famille décodent (lot N) ; un slug inconnu reste ignoré", () => {
    expect(decodeConflictsParams(new URLSearchParams("traitement=import_matchs,erreur_fbi,match_a_deplacer")).treatments).toEqual(["IMPORT_MISSING_MATCHES", "FBI_ERROR", "MATCH_TO_MOVE"]);
    expect(decodeConflictsParams(new URLSearchParams("traitement=erreur_fbi,inconnu")).treatments).toEqual(["FBI_ERROR"]);
  });

  it("domicile=1 ⇒ homeOnly", () => {
    expect(decodeConflictsParams(new URLSearchParams("domicile=1")).homeOnly).toBe(true);
    expect(decodeConflictsParams(new URLSearchParams("")).homeOnly).toBe(false);
  });

  it("rétro-compat traites=masques ⇒ treatments=[a_traiter] ; traitement gagne si les deux coexistent", () => {
    expect(decodeConflictsParams(new URLSearchParams("traites=masques")).treatments).toEqual(["a_traiter"]);
    expect(decodeConflictsParams(new URLSearchParams("traites=masques&traitement=derogation")).treatments).toEqual(["DEROGATION_REQUESTED"]);
  });
});

describe("applyConflictsToParams (onglet Conflits, B)", () => {
  const base = { pivot: "coach" as const, families: null, treatments: null, homeOnly: false };

  it("défauts ⇒ rien dans l'URL", () => {
    expect(applyConflictsToParams(new URLSearchParams(""), base).toString()).toBe("");
  });

  it("pivot ≠ coach ⇒ écrit ; familles partielles ⇒ conflits écrit", () => {
    const out = applyConflictsToParams(new URLSearchParams(""), { ...base, pivot: "journee", families: ["MATCH_MATCH"] });
    expect(out.get("pivot")).toBe("journee");
    expect(out.get("conflits")).toBe("MATCH_MATCH");
  });

  it("treatments partiels ⇒ traitement=slugs ; les 7 clés ⇒ absent (défaut) ; toujours purge le legacy traites", () => {
    expect(applyConflictsToParams(new URLSearchParams(""), { ...base, treatments: ["a_traiter", "DEROGATION_REQUESTED"] }).get("traitement")).toBe("a_traiter,derogation");
    // Les 7 clés (historiques + propres à une famille) = le défaut ⇒ rien dans l'URL.
    expect(applyConflictsToParams(new URLSearchParams(""), { ...base, treatments: ["a_traiter", "DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET", "IMPORT_MISSING_MATCHES", "FBI_ERROR", "MATCH_TO_MOVE"] }).has("traitement")).toBe(false);
    // Les 4 historiques SEULES (les 3 propres à une famille décochées) = un sous-ensemble explicite, donc écrit.
    expect(applyConflictsToParams(new URLSearchParams(""), { ...base, treatments: ["a_traiter", "DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET"] }).get("traitement")).toBe("a_traiter,derogation,regle_interne,sans_solution");
    expect(applyConflictsToParams(new URLSearchParams("traites=masques"), base).has("traites")).toBe(false);
  });

  it("domicile ⇒ domicile=1 ; false ⇒ absent", () => {
    expect(applyConflictsToParams(new URLSearchParams(""), { ...base, homeOnly: true }).get("domicile")).toBe("1");
    expect(applyConflictsToParams(new URLSearchParams("domicile=1"), base).has("domicile")).toBe(false);
  });

  it("préserve les params sans rapport (ex. ?ouvert)", () => {
    const out = applyConflictsToParams(new URLSearchParams("ouvert=coach-1"), { ...base, pivot: "equipe" });
    expect(out.get("ouvert")).toBe("coach-1");
    expect(out.get("pivot")).toBe("equipe");
    expect(out.has("conflits")).toBe(false);
  });

  it("aller-retour legacy : traites=masques décode a_traiter et se réécrit traitement=a_traiter (traites purgé)", () => {
    const decoded = decodeConflictsParams(new URLSearchParams("traites=masques"));
    const out = applyConflictsToParams(new URLSearchParams("traites=masques"), decoded);
    expect(out.get("traitement")).toBe("a_traiter");
    expect(out.has("traites")).toBe(false);
  });
});

describe("decodeFbiParam / applyFbiToParams (deep-link « FBI — à faire »)", () => {
  it("absent ⇒ fermée ; fbi=1 ⇒ ouverte ; toute autre valeur ⇒ fermée", () => {
    expect(decodeFbiParam(new URLSearchParams(""))).toBe(false);
    expect(decodeFbiParam(new URLSearchParams("fbi=1"))).toBe(true);
    expect(decodeFbiParam(new URLSearchParams("fbi=0"))).toBe(false);
  });

  it("ouverte ⇒ fbi=1 ; fermée ⇒ absent ; préserve les autres params", () => {
    expect(applyFbiToParams(new URLSearchParams("semaine=2026-10-03"), true).get("fbi")).toBe("1");
    const closed = applyFbiToParams(new URLSearchParams("fbi=1&semaine=2026-10-03"), false);
    expect(closed.has("fbi")).toBe(false);
    expect(closed.get("semaine")).toBe("2026-10-03");
  });
});

describe("decodeOpponentFilter / applyOpponentFilterToParams (C8 — filtre de l'onglet Adversaires)", () => {
  it("absent ⇒ null (« Tous » par défaut)", () => {
    expect(decodeOpponentFilter(new URLSearchParams(""))).toBeNull();
  });

  it("« tous », une valeur inconnue, ou l'ancien « ville » ⇒ null", () => {
    expect(decodeOpponentFilter(new URLSearchParams("filtre=tous"))).toBeNull();
    expect(decodeOpponentFilter(new URLSearchParams("filtre=xxx"))).toBeNull();
    // L'ancien filtre `ville` (retiré) ne plante pas : décode en null (aucun segment pressé).
    expect(decodeOpponentFilter(new URLSearchParams("filtre=ville"))).toBeNull();
    expect(decodeOpponentFilter(new URLSearchParams("filtre=a-localiser"))).toBeNull();
  });

  it("chacune des deux clés est reconnue", () => {
    expect(decodeOpponentFilter(new URLSearchParams("filtre=sans-gymnase"))).toBe("sans-gymnase");
    expect(decodeOpponentFilter(new URLSearchParams("filtre=a-apparier"))).toBe("a-apparier");
  });

  it("null (défaut « Tous ») ⇒ param supprimé", () => {
    expect(applyOpponentFilterToParams(new URLSearchParams("filtre=a-apparier"), null).toString()).toBe("");
  });

  it("une clé ⇒ écrite et relue telle quelle, préserve les params sans rapport", () => {
    const out = applyOpponentFilterToParams(new URLSearchParams("autre=1"), "sans-gymnase");
    expect(out.get("filtre")).toBe("sans-gymnase");
    expect(out.get("autre")).toBe("1");
    expect(decodeOpponentFilter(out)).toBe("sans-gymnase");
  });
});
