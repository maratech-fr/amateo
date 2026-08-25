import { describe, expect, it } from "vitest";

import { visitDeltaSegments } from "./visitDeltaSegments";

// RMM-6 — la formulation PARTAGÉE du « delta de visite » (matchs arrivés · conflits
// neufs · planning changé), extraite de `moduleVisitSummary` pour être consommée par
// le bandeau du module ET la tuile cockpit. Ces cas re-gardent le comportement des
// segments SANS le voile `firstVisit` (propre au bandeau du gardien).
describe("visitDeltaSegments (RMM-6 — segments partagés du delta de visite)", () => {
  it("delta PLEIN → les trois segments dans l'ordre du geste (matchs → conflits → planning)", () => {
    expect(visitDeltaSegments({ newFixturesCount: 12, newConflictFingerprints: ["a", "b", "c"], planningChanged: true })).toEqual([
      "12 matchs arrivés",
      "3 nouveaux conflits",
      "le planning de saison a changé",
    ]);
  });

  it("singuliers propres (1 match / 1 conflit)", () => {
    expect(visitDeltaSegments({ newFixturesCount: 1, newConflictFingerprints: ["a"], planningChanged: false })).toEqual([
      "1 match arrivé",
      "1 nouveau conflit",
    ]);
  });

  it("delta PARTIEL (0 conflit neuf) → le segment conflits est ABSENT (falsification)", () => {
    const segments = visitDeltaSegments({ newFixturesCount: 4, newConflictFingerprints: [], planningChanged: true });
    expect(segments).toEqual(["4 matchs arrivés", "le planning de saison a changé"]);
    expect(segments.some((s) => s.includes("conflit"))).toBe(false);
  });

  it("delta VIDE → aucun segment", () => {
    expect(visitDeltaSegments({ newFixturesCount: 0, newConflictFingerprints: [], planningChanged: false })).toEqual([]);
  });
});
