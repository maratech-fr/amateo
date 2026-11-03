import { describe, expect, it } from "vitest";

import type { Schedule } from "../api";
import cases from "./versionLabel.parity.json";
import { versionLabelOf } from "./versions";

// Miroir déclaré : mêmes cas que `CrossStack/VersionLabelMirrorParityTest` (backend `VersionLabel::of`).
describe("versionLabelOf — parité avec le serveur", () => {
  for (const c of cases.cases) {
    it(c.label, () => {
      const versions = c.versions.map((v) => ({ ...v, name: "P", status: "COMPLETED", score: null, updatedAt: v.createdAt, planType: "SEASON", schedulePlanId: "p" })) as Schedule[];
      expect(versionLabelOf(versions, c.id)).toBe(c.expected);
    });
  }
});
