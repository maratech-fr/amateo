import { beforeEach, describe, expect, it } from "vitest";

import { useMatchesStore } from "./store";

beforeEach(() => {
  useMatchesStore.setState({ selectedWeekend: null, selectedFixtureId: null, unplacedReasons: new Map() });
});

describe("useMatchesStore — raisons de non-placement (RMM-1 PR4, L6)", () => {
  it("setUnplacedReasons pose les raisons ; un AUTRE geste ne les efface pas", () => {
    useMatchesStore.getState().setUnplacedReasons(new Map([["fx-1", "Aucune fenêtre d'accès match"]]));
    expect(useMatchesStore.getState().unplacedReasons.get("fx-1")).toBe("Aucune fenêtre d'accès match");

    // Un geste sans rapport (sélectionner une rencontre) ne purge PAS les raisons —
    // elles restent tant que la semaine affichée ne change pas.
    useMatchesStore.getState().setSelectedFixtureId("fx-1");
    expect(useMatchesStore.getState().unplacedReasons.get("fx-1"), "un autre geste conserve les raisons").toBe("Aucune fenêtre d'accès match");
  });

  it("changer de semaine PURGE les raisons (elles sont attachées à la semaine affichée)", () => {
    useMatchesStore.getState().setUnplacedReasons(new Map([["fx-1", "raison"]]));
    expect(useMatchesStore.getState().unplacedReasons.size).toBe(1);

    useMatchesStore.getState().setSelectedWeekend("2026-10-10");
    expect(useMatchesStore.getState().unplacedReasons.size, "changer de semaine vide les raisons").toBe(0);
  });
});

describe("useMatchesStore — filtre de la vue Semaine (PR-1)", () => {
  beforeEach(() => {
    useMatchesStore.setState({ filterMode: "equipe", filterIds: [] });
  });

  it("défaut : mode equipe, aucune sélection", () => {
    expect(useMatchesStore.getState().filterMode).toBe("equipe");
    expect(useMatchesStore.getState().filterIds).toEqual([]);
  });

  it("setFilterMode change l'axe et VIDE la sélection", () => {
    useMatchesStore.setState({ filterIds: ["a", "b"] });
    useMatchesStore.getState().setFilterMode("coach");
    expect(useMatchesStore.getState().filterMode).toBe("coach");
    expect(useMatchesStore.getState().filterIds).toEqual([]);
  });

  it("toggleFilterId ajoute puis retire", () => {
    useMatchesStore.getState().toggleFilterId("t1");
    expect(useMatchesStore.getState().filterIds).toEqual(["t1"]);
    useMatchesStore.getState().toggleFilterId("t2");
    expect(useMatchesStore.getState().filterIds).toEqual(["t1", "t2"]);
    useMatchesStore.getState().toggleFilterId("t1");
    expect(useMatchesStore.getState().filterIds).toEqual(["t2"]);
  });

  it("clearFilter vide la sélection sans changer l'axe", () => {
    useMatchesStore.setState({ filterMode: "gymnase", filterIds: ["v1", "v2"] });
    useMatchesStore.getState().clearFilter();
    expect(useMatchesStore.getState().filterMode).toBe("gymnase");
    expect(useMatchesStore.getState().filterIds).toEqual([]);
  });

  it("changer de semaine ne purge PAS le filtre", () => {
    useMatchesStore.setState({ filterMode: "coach", filterIds: ["c1"] });
    useMatchesStore.getState().setSelectedWeekend("2026-10-10");
    expect(useMatchesStore.getState().filterIds).toEqual(["c1"]);
    expect(useMatchesStore.getState().filterMode).toBe("coach");
  });
});

describe("useMatchesStore — filtres Consulter (PR-2a)", () => {
  beforeEach(() => {
    useMatchesStore.setState({ consultKinds: null, consultFamilies: null, consultTypicalWeek: true });
  });

  it("défauts : kinds/families null (= tout), semaine type affichée", () => {
    expect(useMatchesStore.getState().consultKinds).toBeNull();
    expect(useMatchesStore.getState().consultFamilies).toBeNull();
    expect(useMatchesStore.getState().consultTypicalWeek).toBe(true);
  });

  it("setConsultKinds pose la sélection de types de compétition", () => {
    useMatchesStore.getState().setConsultKinds(["amical", "coupe"]);
    expect(useMatchesStore.getState().consultKinds).toEqual(["amical", "coupe"]);
    useMatchesStore.getState().setConsultKinds(null);
    expect(useMatchesStore.getState().consultKinds).toBeNull();
  });

  it("setConsultFamilies pose la sélection de familles de conflits", () => {
    useMatchesStore.getState().setConsultFamilies(["MATCH_MATCH"]);
    expect(useMatchesStore.getState().consultFamilies).toEqual(["MATCH_MATCH"]);
    useMatchesStore.getState().setConsultFamilies(null);
    expect(useMatchesStore.getState().consultFamilies).toBeNull();
  });

  it("setConsultTypicalWeek bascule la semaine type", () => {
    useMatchesStore.getState().setConsultTypicalWeek(false);
    expect(useMatchesStore.getState().consultTypicalWeek).toBe(false);
  });
});

describe("useMatchesStore — onglet Conflits (PR A)", () => {
  beforeEach(() => {
    useMatchesStore.setState({ conflictsPivot: "coach", conflictsFamilies: null });
  });

  it("défauts : pivot coach, familles null (= tout coché)", () => {
    expect(useMatchesStore.getState().conflictsPivot).toBe("coach");
    expect(useMatchesStore.getState().conflictsFamilies).toBeNull();
  });

  it("setConflictsPivot bascule l'axe de regroupement", () => {
    useMatchesStore.getState().setConflictsPivot("gymnase");
    expect(useMatchesStore.getState().conflictsPivot).toBe("gymnase");
    useMatchesStore.getState().setConflictsPivot("journee");
    expect(useMatchesStore.getState().conflictsPivot).toBe("journee");
  });

  it("setConflictsFamilies pose la sélection, SÉPARÉE de consultFamilies", () => {
    useMatchesStore.setState({ consultFamilies: null });
    useMatchesStore.getState().setConflictsFamilies(["MATCH_MATCH"]);
    expect(useMatchesStore.getState().conflictsFamilies).toEqual(["MATCH_MATCH"]);
    // Décocher ici ne touche pas Consulter.
    expect(useMatchesStore.getState().consultFamilies).toBeNull();
    useMatchesStore.getState().setConflictsFamilies(null);
    expect(useMatchesStore.getState().conflictsFamilies).toBeNull();
  });
});

describe("useMatchesStore — temporalité Consulter (PR-2b)", () => {
  beforeEach(() => {
    useMatchesStore.setState({ consultTemporality: "semaine", consultMonth: null, consultPhaseId: null });
  });

  it("défauts : temporalité semaine, mois/phase null", () => {
    expect(useMatchesStore.getState().consultTemporality).toBe("semaine");
    expect(useMatchesStore.getState().consultMonth).toBeNull();
    expect(useMatchesStore.getState().consultPhaseId).toBeNull();
  });

  it("setConsultTemporality bascule Semaine · Mois · Phase", () => {
    useMatchesStore.getState().setConsultTemporality("mois");
    expect(useMatchesStore.getState().consultTemporality).toBe("mois");
    useMatchesStore.getState().setConsultTemporality("phase");
    expect(useMatchesStore.getState().consultTemporality).toBe("phase");
  });

  it("setConsultMonth / setConsultPhaseId posent la sélection", () => {
    useMatchesStore.getState().setConsultMonth("2026-10");
    expect(useMatchesStore.getState().consultMonth).toBe("2026-10");
    useMatchesStore.getState().setConsultPhaseId("comp-1");
    expect(useMatchesStore.getState().consultPhaseId).toBe("comp-1");
  });
});
