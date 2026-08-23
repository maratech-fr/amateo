import { describe, expect, it } from "vitest";

import type { PlaceMatchesResult } from "../api";
import { placementToastMessage } from "./placementToast";

const result = (placed: number, unplaced: number): PlaceMatchesResult => ({
  placed,
  skipped: 0,
  unplaced: Array.from({ length: unplaced }, (_, i) => ({ matchId: `m${i}`, reason: "no_access_window" as const, message: "" })),
  diagnostics: [],
});

describe("placementToastMessage", () => {
  it("accorde le singulier et n'ajoute rien quand tout est placé", () => {
    expect(placementToastMessage(result(1, 0))).toBe("1 match placé");
  });

  it("accorde le pluriel et annonce les non plaçables", () => {
    expect(placementToastMessage(result(3, 2))).toBe("3 matchs placés · 2 non plaçables");
  });

  it("accorde le singulier d'un seul non plaçable", () => {
    expect(placementToastMessage(result(0, 1))).toBe("0 match placé · 1 non plaçable");
  });
});
