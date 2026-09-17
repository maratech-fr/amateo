import { describe, expect, it } from "vitest";

import type { Conflict, ConflictResolution } from "../api";
import { countByTreatment, isOpenConflict, openConflictCount, RESOLUTION_LABEL, RESOLUTION_STATUSES, TREATMENT_KEYS, TREATMENT_SLUG, treatmentFromSlug, treatmentOf } from "./conflictResolution";

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

describe("Traitement (B — clés, slugs, treatmentOf, countByTreatment)", () => {
  it("TREATMENT_KEYS : à traiter en tête, puis les 3 statuts", () => {
    expect(TREATMENT_KEYS).toEqual(["a_traiter", "DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET"]);
  });

  it("TREATMENT_SLUG : table exhaustive des 4 slugs URL", () => {
    expect(TREATMENT_SLUG).toEqual({ a_traiter: "a_traiter", DEROGATION_REQUESTED: "derogation", RESOLVED_INTERNALLY: "regle_interne", NO_SOLUTION_YET: "sans_solution" });
  });

  it("treatmentFromSlug : aller-retour sur chaque clé ; slug inconnu ⇒ null", () => {
    for (const key of TREATMENT_KEYS) {
      expect(treatmentFromSlug(TREATMENT_SLUG[key])).toBe(key);
    }
    expect(treatmentFromSlug("ghost")).toBeNull();
  });

  it("treatmentOf : null ⇒ a_traiter ; résolu ⇒ son statut", () => {
    expect(treatmentOf(conflict(null))).toBe("a_traiter");
    expect(treatmentOf(conflict(resolved("DEROGATION_REQUESTED")))).toBe("DEROGATION_REQUESTED");
    expect(treatmentOf(conflict(resolved("RESOLVED_INTERNALLY")))).toBe("RESOLVED_INTERNALLY");
  });

  it("countByTreatment : compte les 4 états (fixes saison)", () => {
    const counts = countByTreatment([conflict(null), conflict(null), conflict(resolved("DEROGATION_REQUESTED")), conflict(resolved("NO_SOLUTION_YET"))]);
    expect(counts.get("a_traiter")).toBe(2);
    expect(counts.get("DEROGATION_REQUESTED")).toBe(1);
    expect(counts.get("NO_SOLUTION_YET")).toBe(1);
    expect(counts.get("RESOLVED_INTERNALLY")).toBeUndefined();
  });
});
