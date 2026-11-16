import { beforeEach, describe, expect, it } from "vitest";

import { useSeasonStore } from "./seasonStore";

describe("seasonStore", () => {
  beforeEach(() => {
    useSeasonStore.getState().clear();
    localStorage.clear();
  });

  it("defaults to following the current season (null)", () => {
    expect(useSeasonStore.getState().selectedSeasonId).toBeNull();
  });

  it("persists the selection under cs-season", () => {
    useSeasonStore.getState().setSelectedSeasonId("s2");
    expect(useSeasonStore.getState().selectedSeasonId).toBe("s2");
    expect(localStorage.getItem("cs-season")).toContain("s2");
  });

  it("clear resets to the default state", () => {
    useSeasonStore.getState().setSelectedSeasonId("s2");
    useSeasonStore.getState().clear();
    expect(useSeasonStore.getState().selectedSeasonId).toBeNull();
  });
});
