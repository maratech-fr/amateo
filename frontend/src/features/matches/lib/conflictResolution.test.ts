import { describe, expect, it } from "vitest";

import type { Conflict, ConflictResolution } from "../api";
import { isOpenConflict, openConflictCount, RESOLUTION_LABEL, RESOLUTION_STATUSES } from "./conflictResolution";

function conflict(resolution: ConflictResolution | null): Conflict {
  return { type: "VENUE_OVERLAP", severity: 1, resolution };
}

const resolved = (status: ConflictResolution["status"]): ConflictResolution => ({ status, note: null, updatedAt: "2026-10-03T20:45:00+02:00" });

describe("RESOLUTION_LABEL", () => {
  it("porte un libellé, une variante et un glyphe pour CHAQUE statut (jamais un switch)", () => {
    for (const status of RESOLUTION_STATUSES) {
      const meta = RESOLUTION_LABEL[status];
      expect(meta.label).not.toBe("");
      expect(["warning", "accent", "neutral"]).toContain(meta.variant);
      expect(meta.icon).toBeDefined();
    }
  });

  it("un glyphe DISTINCT par statut (jamais la couleur seule)", () => {
    const icons = RESOLUTION_STATUSES.map((s) => RESOLUTION_LABEL[s].icon);
    expect(new Set(icons).size).toBe(RESOLUTION_STATUSES.length);
  });

  it("les libellés attendus", () => {
    expect(RESOLUTION_LABEL.DEROGATION_REQUESTED.label).toBe("Dérogation demandée");
    expect(RESOLUTION_LABEL.RESOLVED_INTERNALLY.label).toBe("Réglé en interne");
    expect(RESOLUTION_LABEL.NO_SOLUTION_YET.label).toBe("Sans solution pour l'instant");
  });
});

describe("isOpenConflict", () => {
  it("« à traiter » quand la résolution est null", () => {
    expect(isOpenConflict(conflict(null))).toBe(true);
  });

  it("annoté (résolution présente) ⇒ PAS à traiter", () => {
    expect(isOpenConflict(conflict(resolved("DEROGATION_REQUESTED")))).toBe(false);
    expect(isOpenConflict(conflict(resolved("RESOLVED_INTERNALLY")))).toBe(false);
  });

  it("champ absent (undefined) ⇒ traité comme à traiter (robustesse)", () => {
    expect(isOpenConflict({ type: "VENUE_OVERLAP", severity: 1 } as Conflict)).toBe(true);
  });
});

describe("openConflictCount", () => {
  it("ne compte QUE l'à traiter", () => {
    const conflicts = [conflict(null), conflict(resolved("NO_SOLUTION_YET")), conflict(null), conflict(resolved("RESOLVED_INTERNALLY"))];
    expect(openConflictCount(conflicts)).toBe(2);
  });

  it("données absentes ⇒ 0 (jamais un « · 0 » fabriqué)", () => {
    expect(openConflictCount(undefined)).toBe(0);
    expect(openConflictCount([])).toBe(0);
  });
});
