import { describe, expect, it } from "vitest";

import type { Conflict, ConflictFixtureView, ConflictResolution, ConflictSideRole } from "../api";
import { countByTreatment, isOpenConflict, openConflictCount, RESOLUTION_LABEL, RESOLUTION_STATUSES, resolutionChoicesFor, statusNeedsFbiComplement, treatmentChipKeys, TREATMENT_KEYS, TREATMENT_SLUG, treatmentFromSlug, treatmentOf } from "./conflictResolution";

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
  it("TREATMENT_KEYS : à traiter + les 3 historiques, PUIS les 3 propres à une famille (lot N)", () => {
    expect(TREATMENT_KEYS).toEqual(["a_traiter", "DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET", "IMPORT_MISSING_MATCHES", "FBI_ERROR", "MATCH_TO_MOVE"]);
  });

  it("TREATMENT_SLUG : table exhaustive des 7 slugs URL", () => {
    expect(TREATMENT_SLUG).toEqual({
      a_traiter: "a_traiter",
      DEROGATION_REQUESTED: "derogation",
      RESOLVED_INTERNALLY: "regle_interne",
      NO_SOLUTION_YET: "sans_solution",
      IMPORT_MISSING_MATCHES: "import_matchs",
      FBI_ERROR: "erreur_fbi",
      MATCH_TO_MOVE: "match_a_deplacer",
    });
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

describe("vocabulaire par famille (lot N)", () => {
  it("RESOLUTION_LABEL : les 3 nouveaux libellés + glyphes tous distincts sur les 8 statuts", () => {
    expect(RESOLUTION_LABEL.IMPORT_MISSING_MATCHES.label).toBe("Importer les matchs manquants");
    expect(RESOLUTION_LABEL.FBI_ERROR.label).toBe("Erreur FBI");
    expect(RESOLUTION_LABEL.MATCH_TO_MOVE.label).toBe("Match à déplacer");
    const allIcons = (["DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET", "COACHES_NOT_PLAYING", "PLAYS_NOT_COACHING", "IMPORT_MISSING_MATCHES", "FBI_ERROR", "MATCH_TO_MOVE"] as const).map((s) => RESOLUTION_LABEL[s].icon);
    expect(new Set(allIcons).size).toBe(8);
  });

  it("resolutionChoicesFor : collision de gymnase → base + erreur FBI + match à déplacer", () => {
    const venue: Conflict = { type: "VENUE_OVERLAP", severity: 1, resolution: null };
    expect(resolutionChoicesFor(venue)).toEqual(["DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET", "FBI_ERROR", "MATCH_TO_MOVE"]);
  });

  it("resolutionChoicesFor : calendrier incomplet → base + importer les matchs manquants", () => {
    const incomplete: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null };
    expect(resolutionChoicesFor(incomplete)).toEqual(["DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET", "IMPORT_MISSING_MATCHES"]);
  });

  it("resolutionChoicesFor : une famille sans statut propre → les 3 de base, exactement", () => {
    const other: Conflict = { type: "LEAGUE_WINDOW_VIOLATION", severity: 2, resolution: null };
    expect(resolutionChoicesFor(other)).toEqual(["DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET"]);
  });

  it("treatmentOf : les 3 statuts de famille ont leur PROPRE clé de filtre", () => {
    expect(treatmentOf(conflict(resolved("IMPORT_MISSING_MATCHES")))).toBe("IMPORT_MISSING_MATCHES");
    expect(treatmentOf(conflict(resolved("FBI_ERROR")))).toBe("FBI_ERROR");
    expect(treatmentOf(conflict(resolved("MATCH_TO_MOVE")))).toBe("MATCH_TO_MOVE");
  });

  it("treatmentChipKeys : les 4 historiques TOUJOURS, une clé de famille SEULEMENT si présente", () => {
    // Aucun conflit de famille traité → seules les 4 historiques.
    expect(treatmentChipKeys([conflict(null), conflict(resolved("DEROGATION_REQUESTED"))])).toEqual(["a_traiter", "DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET"]);
    // Une erreur FBI présente → sa puce apparaît, dans l'ordre ; les autres conditionnelles restent absentes.
    expect(treatmentChipKeys([conflict(null), conflict(resolved("FBI_ERROR"))])).toEqual(["a_traiter", "DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET", "FBI_ERROR"]);
  });

  it("statusNeedsFbiComplement : FBI_ERROR seul exige le complément", () => {
    expect(statusNeedsFbiComplement("FBI_ERROR")).toBe(true);
    expect(statusNeedsFbiComplement("MATCH_TO_MOVE")).toBe(false);
    expect(statusNeedsFbiComplement("IMPORT_MISSING_MATCHES")).toBe(false);
    expect(statusNeedsFbiComplement("DEROGATION_REQUESTED")).toBe(false);
  });
});
