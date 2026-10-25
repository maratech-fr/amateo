import { describe, expect, it } from "vitest";

import { composeActiveFilters, composeDisplayedRange } from "./feedbackContext";

/**
 * P4-356 — la COMPOSITION pure des repères de contexte du module Matchs, joints au
 * signalement (porte contextuelle) : les FILTRES actifs et la PLAGE affichée. Le front
 * n'invente aucune règle — il résume l'état d'affichage du store en deux chaînes bornées
 * que le support lit dans la console.
 *
 * NEO implémente (option A — ROUGE tant que `./feedbackContext` n'existe pas) deux
 * fonctions PURES ; signatures proposées (NEO peut les adapter mécaniquement) :
 *   composeActiveFilters({ filterMode, filterIds, consultKinds, consultFamilies, consultAway }): string
 *     — liste « · » des filtres NON-DÉFAUT (≤ 300) : l'axe + le nombre de ressources
 *       cochées (« axe équipe (3) »), les types cochés, les familles cochées, et
 *       « extérieurs masqués » quand les extérieurs sont cachés (consultAway=false). Tout
 *       à son défaut (axe équipe sans sélection, types/familles = null, extérieurs
 *       affichés) → chaîne VIDE.
 *   composeDisplayedRange({ temporality, weekendKey, month, phaseLabel }): string
 *     — la plage affichée (≤ 80) selon la temporalité : « week-end du JJ/MM » (semaine),
 *       le mois en clair (mois), « Phase : <libellé> » (phase) — OMISE (chaîne vide) si
 *       le libellé de phase est absent.
 *
 * Le CÂBLAGE (ces deux chaînes atteignent les deux `PageHeader` du module) est prouvé à
 * part : {@see ../MatchesLayout.test.tsx}.
 */
describe("composeActiveFilters — les filtres actifs du module Matchs", () => {
  it("rend une chaîne VIDE quand tout est au défaut (rien ne narre l'affichage)", () => {
    expect(
      composeActiveFilters({ filterMode: "equipe", filterIds: [], consultKinds: null, consultFamilies: null, consultAway: true }),
    ).toBe("");
  });

  it("nomme l'axe et le NOMBRE de ressources cochées (« axe équipe (3) »)", () => {
    const out = composeActiveFilters({ filterMode: "equipe", filterIds: ["a", "b", "c"], consultKinds: null, consultFamilies: null, consultAway: true });
    expect(out).toContain("équipe");
    expect(out).toContain("3");
  });

  it("reporte « extérieurs masqués » quand les extérieurs sont cachés", () => {
    const out = composeActiveFilters({ filterMode: "equipe", filterIds: [], consultKinds: null, consultFamilies: null, consultAway: false });
    expect(out).toMatch(/extérieurs?\s+masqués?/i);
  });

  it("reporte les types et les familles cochés", () => {
    const out = composeActiveFilters({ filterMode: "equipe", filterIds: [], consultKinds: ["amical"], consultFamilies: ["MATCH_MATCH"], consultAway: true });
    expect(out).toMatch(/type/i);
    expect(out).toMatch(/famille/i);
  });

  it("reste borné à 300 caractères même sur un état sur-filtré", () => {
    const out = composeActiveFilters({
      filterMode: "gymnase",
      filterIds: Array.from({ length: 200 }, (_, i) => `venue-${i}`),
      consultKinds: ["amical", "championnat", "coupe", "brassage"],
      consultFamilies: ["MATCH_MATCH", "MATCH_TRAINING", "VENUE_OVERLAP"],
      consultAway: false,
    });
    expect(out.length).toBeLessThanOrEqual(300);
  });
});

describe("composeDisplayedRange — la plage affichée du module Matchs", () => {
  it("rend un libellé de week-end daté en temporalité semaine", () => {
    const out = composeDisplayedRange({ temporality: "semaine", weekendKey: "2026-11-07", month: null, phaseLabel: null });
    expect(out).toMatch(/week-?end/i);
    expect(out).toMatch(/\d{2}\/\d{2}/);
  });

  it("rend le mois en clair en temporalité mois", () => {
    const out = composeDisplayedRange({ temporality: "mois", weekendKey: null, month: "2026-11", phaseLabel: null });
    expect(out).toMatch(/novembre/i);
    expect(out).toContain("2026");
  });

  it("rend « Phase : <libellé> » en temporalité phase", () => {
    const out = composeDisplayedRange({ temporality: "phase", weekendKey: null, month: null, phaseLabel: "Championnat U15 M" });
    expect(out).toMatch(/phase/i);
    expect(out).toContain("Championnat U15 M");
  });

  it("OMET la plage (chaîne vide) en phase sans libellé", () => {
    expect(composeDisplayedRange({ temporality: "phase", weekendKey: null, month: null, phaseLabel: null })).toBe("");
  });

  it("reste borné à 80 caractères", () => {
    const out = composeDisplayedRange({ temporality: "phase", weekendKey: null, month: null, phaseLabel: "x".repeat(200) });
    expect(out.length).toBeLessThanOrEqual(80);
  });
});
