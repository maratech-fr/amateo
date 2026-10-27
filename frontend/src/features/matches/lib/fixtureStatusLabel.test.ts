import { describe, expect, it } from "vitest";

import type { FixtureStatus } from "../api";
import { FIXTURE_STATUS_LABEL } from "./fixtureStatusLabel";

describe("FIXTURE_STATUS_LABEL (RMM-1 PR 1)", () => {
  // Retours Nico (BCCL, 2026-10-27) — wording VALIDÉ fondateur : la table d'états se dit
  // en mots de gestionnaire, pas en jargon fédéral. « Sans créneau » → « Sans date ni
  // gymnase » (ce qui manque, pas l'absence de case), « Placé » → « Placé — à saisir dans
  // FBI » (l'action qui reste), « Attesté FBI » → « Déjà dans FBI » (plus parlant que
  // « Attesté »). « Saisi dans FBI » reste.
  it("renders each of the four statuses in French (wording BCCL 2026-10-27)", () => {
    expect(FIXTURE_STATUS_LABEL.UNPLACED).toBe("Sans date ni gymnase");
    expect(FIXTURE_STATUS_LABEL.PLACED).toBe("Placé — à saisir dans FBI");
    expect(FIXTURE_STATUS_LABEL.SUBMITTED).toBe("Saisi dans FBI");
    expect(FIXTURE_STATUS_LABEL.VALIDATED).toBe("Déjà dans FBI");
  });

  // M3 — plus aucun « Attesté » lu par un gestionnaire : le mot fédéral a disparu de la table.
  it("aucun statut ne porte plus le mot « Attesté »", () => {
    for (const label of Object.values(FIXTURE_STATUS_LABEL)) {
      expect(label).not.toMatch(/Attest/);
    }
  });

  it("covers every status value — no English code can leak", () => {
    const statuses: FixtureStatus[] = ["UNPLACED", "PLACED", "SUBMITTED", "VALIDATED"];
    for (const status of statuses) {
      expect(FIXTURE_STATUS_LABEL[status]).toBeTruthy();
      expect(FIXTURE_STATUS_LABEL[status]).not.toBe(status);
    }
  });
});
