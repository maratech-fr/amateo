import { beforeEach, describe, expect, it } from "vitest";

import { useMatchesStore } from "./store";

beforeEach(() => {
  useMatchesStore.setState({ selectedWeekend: null, railStep: null });
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
