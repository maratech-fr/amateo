import { describe, expect, it } from "vitest";

import { visitDeltaLinkedSegments, visitDeltaSegments } from "./visitDeltaSegments";

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

  // FRT-37 — garde de forme (maison unique). Un delta MALFORMÉ (payload API partiel, `{}`) lisait
  // `.newConflictFingerprints.length` sur `undefined` et abattait la route (le bandeau et la tuile
  // cockpit consomment cette fonction). Un compteur non-number / des fingerprints non-array / un
  // booléen absent rendent une liste VIDE → bandeau et tuile absents, page indemne.
  it("delta `{}` (payload malformé) → aucun segment, aucune exception", () => {
    expect(() => visitDeltaSegments({} as never)).not.toThrow();
    expect(visitDeltaSegments({} as never)).toEqual([]);
  });

  it("variantes PARTIELLES (champ manquant / type faux) → aucun segment", () => {
    expect(visitDeltaSegments({ newFixturesCount: 3 } as never)).toEqual([]); // fingerprints/planningChanged absents
    expect(visitDeltaSegments({ newConflictFingerprints: ["a"], planningChanged: true } as never)).toEqual([]); // compteur absent
    expect(visitDeltaSegments({ newFixturesCount: 3, newConflictFingerprints: "x", planningChanged: true } as never)).toEqual([]); // fingerprints non-array
    expect(visitDeltaSegments({ newFixturesCount: 3, newConflictFingerprints: [], planningChanged: 1 } as never)).toEqual([]); // planningChanged non-booléen
  });
});

// B1 — le bandeau devient cliquable : chaque segment dit OÙ aller. Les matchs arrivés mènent à
// l'Importer, les conflits neufs aux seuls conflits nouveaux (`?nouveaux=1`) ; « le planning a
// changé » n'a pas d'écran unique où mener : pas de lien. Mêmes libellés que `visitDeltaSegments`.
describe("visitDeltaLinkedSegments (B1 — bandeau cliquable)", () => {
  it("delta PLEIN → trois segments, chacun avec sa destination (ou aucune)", () => {
    expect(visitDeltaLinkedSegments({ newFixturesCount: 12, newConflictFingerprints: ["a", "b", "c"], planningChanged: true })).toEqual([
      { label: "12 matchs arrivés", to: "/matchs/importer" },
      { label: "3 nouveaux conflits", to: "/matchs/conflits?nouveaux=1" },
      { label: "le planning de saison a changé", to: null },
    ]);
  });

  it("les libellés sont EXACTEMENT ceux de visitDeltaSegments (une seule maison de la tournure)", () => {
    const delta = { newFixturesCount: 1, newConflictFingerprints: ["a"], planningChanged: false };
    expect(visitDeltaLinkedSegments(delta).map((s) => s.label)).toEqual(visitDeltaSegments(delta));
  });

  it("delta MALFORMÉ → aucun segment, aucune exception", () => {
    expect(visitDeltaLinkedSegments({} as never)).toEqual([]);
  });
});
