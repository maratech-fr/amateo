import { beforeEach, describe, expect, it } from "vitest";

import { useMatchesStore } from "./store";

beforeEach(() => {
  useMatchesStore.setState({ selectedWeekend: null, railStep: null, unplacedReasons: new Map() });
});

describe("useMatchesStore — railStep (RMM-1 PR3)", () => {
  it("setRailStep pose la vue", () => {
    useMatchesStore.getState().setRailStep("disputes");
    expect(useMatchesStore.getState().railStep).toBe("disputes");
  });

  it("changer de semaine remet railStep à null (l'auto recalcule le premier trou)", () => {
    useMatchesStore.getState().setRailStep("homeSlots");
    expect(useMatchesStore.getState().railStep).toBe("homeSlots");

    useMatchesStore.getState().setSelectedWeekend("2026-10-03");
    expect(useMatchesStore.getState().selectedWeekend).toBe("2026-10-03");
    expect(useMatchesStore.getState().railStep, "changer de semaine reset la vue").toBeNull();
  });
});

describe("useMatchesStore — raisons de non-placement (RMM-1 PR4, L6)", () => {
  it("setUnplacedReasons pose les raisons ; un AUTRE geste ne les efface pas", () => {
    useMatchesStore.getState().setUnplacedReasons(new Map([["fx-1", "Aucune fenêtre d'accès match"]]));
    expect(useMatchesStore.getState().unplacedReasons.get("fx-1")).toBe("Aucune fenêtre d'accès match");

    // Un geste sans rapport (poser la vue du rail) ne purge PAS les raisons —
    // elles restent tant que la semaine affichée ne change pas.
    useMatchesStore.getState().setRailStep("homeSlots");
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
    useMatchesStore.setState({ filterMode: "equipe", filterIds: [], railStep: null });
  });

  it("défaut : mode equipe, aucune sélection", () => {
    expect(useMatchesStore.getState().filterMode).toBe("equipe");
    expect(useMatchesStore.getState().filterIds).toEqual([]);
  });

  it("setFilterMode change l'axe, VIDE la sélection et remet railStep à null", () => {
    useMatchesStore.setState({ filterIds: ["a", "b"], railStep: "disputes" });
    useMatchesStore.getState().setFilterMode("coach");
    expect(useMatchesStore.getState().filterMode).toBe("coach");
    expect(useMatchesStore.getState().filterIds).toEqual([]);
    expect(useMatchesStore.getState().railStep).toBeNull();
  });

  it("toggleFilterId ajoute puis retire, et remet railStep à null", () => {
    useMatchesStore.getState().toggleFilterId("t1");
    expect(useMatchesStore.getState().filterIds).toEqual(["t1"]);
    useMatchesStore.setState({ railStep: "model" });
    useMatchesStore.getState().toggleFilterId("t2");
    expect(useMatchesStore.getState().filterIds).toEqual(["t1", "t2"]);
    expect(useMatchesStore.getState().railStep).toBeNull();
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
