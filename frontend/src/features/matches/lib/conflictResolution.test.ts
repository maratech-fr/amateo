import { describe, expect, it } from "vitest";

import type { Conflict, ConflictFixtureView, ConflictResolution, ConflictSideRole } from "../api";
import { countByTreatment, isOpenConflict, openConflictCount, RESOLUTION_LABEL, RESOLUTION_STATUSES, resolutionChoicesFor, TREATMENT_KEYS, TREATMENT_SLUG, treatmentFromSlug, treatmentOf } from "./conflictResolution";

function conflict(resolution: ConflictResolution | null): Conflict {
  return { type: "VENUE_OVERLAP", severity: 1, resolution };
}

function sideWithRole(role: ConflictSideRole): ConflictFixtureView {
  return { fixtureId: "f", teamId: "t", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "", role };
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

describe("statuts « joue/coache » (réservés aux conflits où la personne joue)", () => {
  it("RESOLUTION_LABEL : les 2 nouveaux libellés, variante accent, glyphes distincts", () => {
    expect(RESOLUTION_LABEL.COACHES_NOT_PLAYING.label).toBe("Coache, ne joue pas");
    expect(RESOLUTION_LABEL.PLAYS_NOT_COACHING.label).toBe("Joue, ne coache pas");
    expect(RESOLUTION_LABEL.COACHES_NOT_PLAYING.variant).toBe("accent");
    expect(RESOLUTION_LABEL.PLAYS_NOT_COACHING.variant).toBe("accent");
    const allIcons = [RESOLUTION_LABEL.DEROGATION_REQUESTED.icon, RESOLUTION_LABEL.RESOLVED_INTERNALLY.icon, RESOLUTION_LABEL.NO_SOLUTION_YET.icon, RESOLUTION_LABEL.COACHES_NOT_PLAYING.icon, RESOLUTION_LABEL.PLAYS_NOT_COACHING.icon];
    expect(new Set(allIcons).size).toBe(5);
  });

  it("resolutionChoicesFor : les 2 statuts APPARAISSENT quand un côté servi porte PLAYER", () => {
    const withPlayer: Conflict = { type: "MATCH_MATCH", severity: 3, resolution: null, left: sideWithRole("MAIN"), right: sideWithRole("PLAYER") };
    expect(resolutionChoicesFor(withPlayer)).toEqual(["DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET", "COACHES_NOT_PLAYING", "PLAYS_NOT_COACHING"]);
  });

  it("resolutionChoicesFor : ABSENTS quand aucun côté ne joue (que des coachs)", () => {
    const noPlayer: Conflict = { type: "MATCH_MATCH", severity: 3, resolution: null, left: sideWithRole("MAIN"), right: sideWithRole("ASSISTANT") };
    expect(resolutionChoicesFor(noPlayer)).toEqual(["DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET"]);
  });

  it("treatmentOf : les 2 statuts se rangent sous « Réglé en interne » (aucun chip propre)", () => {
    expect(treatmentOf(conflict(resolved("COACHES_NOT_PLAYING")))).toBe("RESOLVED_INTERNALLY");
    expect(treatmentOf(conflict(resolved("PLAYS_NOT_COACHING")))).toBe("RESOLVED_INTERNALLY");
  });
});
