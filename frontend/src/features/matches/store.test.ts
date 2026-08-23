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
