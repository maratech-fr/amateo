import { describe, expect, it } from "vitest";

import type { FixtureStatus } from "../api";
import { FIXTURE_STATUS_LABEL } from "./fixtureStatusLabel";

describe("FIXTURE_STATUS_LABEL (RMM-1 PR 1)", () => {
  it("renders each of the four statuses in French", () => {
    expect(FIXTURE_STATUS_LABEL.UNPLACED).toBe("Importé");
    expect(FIXTURE_STATUS_LABEL.PLACED).toBe("Placé");
    expect(FIXTURE_STATUS_LABEL.SUBMITTED).toBe("Saisi dans FBI");
    expect(FIXTURE_STATUS_LABEL.VALIDATED).toBe("Validé ligue");
  });

  it("covers every status value — no English code can leak", () => {
    const statuses: FixtureStatus[] = ["UNPLACED", "PLACED", "SUBMITTED", "VALIDATED"];
    for (const status of statuses) {
      expect(FIXTURE_STATUS_LABEL[status]).toBeTruthy();
      expect(FIXTURE_STATUS_LABEL[status]).not.toBe(status);
    }
  });
});
