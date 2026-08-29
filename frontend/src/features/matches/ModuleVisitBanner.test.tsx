import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { ModuleVisitDelta } from "./api";
import { ModuleVisitBanner } from "./ModuleVisitBanner";
import { moduleVisitSummary } from "./lib/moduleVisitSummary";

function delta(over: Partial<ModuleVisitDelta>): ModuleVisitDelta {
  return {
    firstVisit: false,
    newFixturesCount: 0,
    newConflictFingerprints: [],
    planningChanged: false,
    referenceTakenAt: "2026-08-24T10:00:00+00:00",
    ...over,
  };
}

describe("moduleVisitSummary (RMM-3 — le gardien : les segments non nuls)", () => {
  it("delta PLEIN → les trois segments, dans l'ordre du geste (matchs → conflits → planning)", () => {
    expect(moduleVisitSummary(delta({ newFixturesCount: 12, newConflictFingerprints: ["a", "b", "c"], planningChanged: true }))).toEqual([
      "12 matchs arrivés",
      "3 nouveaux conflits",
      "le planning de saison a changé",
    ]);
  });

  it("singuliers propres (1 match / 1 conflit)", () => {
    expect(moduleVisitSummary(delta({ newFixturesCount: 1, newConflictFingerprints: ["a"], planningChanged: false }))).toEqual([
      "1 match arrivé",
      "1 nouveau conflit",
    ]);
  });

  it("delta PARTIEL (0 conflit neuf) → le segment conflits est ABSENT (falsification)", () => {
    const segments = moduleVisitSummary(delta({ newFixturesCount: 4, newConflictFingerprints: [], planningChanged: true }));
    expect(segments).toEqual(["4 matchs arrivés", "le planning de saison a changé"]);
    expect(segments.some((s) => s.includes("conflit"))).toBe(false);
  });

  it("firstVisit → AUCUN segment (la première visite est muette)", () => {
    expect(moduleVisitSummary(delta({ firstVisit: true, newFixturesCount: 9, newConflictFingerprints: ["a"], planningChanged: true }))).toEqual([]);
  });

  it("delta VIDE → aucun segment", () => {
    expect(moduleVisitSummary(delta({}))).toEqual([]);
  });
});

describe("ModuleVisitBanner (RMM-3 — bandeau résumé)", () => {
  it("delta PLEIN → rend les trois morceaux", () => {
    render(<ModuleVisitBanner delta={delta({ newFixturesCount: 12, newConflictFingerprints: ["a", "b", "c"], planningChanged: true })} />);
    const banner = screen.getByRole("status");
    expect(banner).toHaveTextContent("Depuis votre dernière visite");
    expect(banner).toHaveTextContent("12 matchs arrivés");
    expect(banner).toHaveTextContent("3 nouveaux conflits");
    expect(banner).toHaveTextContent("le planning de saison a changé");
  });

  it("delta PARTIEL → le morceau conflits n'apparaît pas (falsification dans les deux sens)", () => {
    render(<ModuleVisitBanner delta={delta({ newFixturesCount: 4, newConflictFingerprints: [], planningChanged: true })} />);
    expect(screen.getByRole("status")).toHaveTextContent("4 matchs arrivés");
    expect(screen.getByRole("status")).toHaveTextContent("le planning de saison a changé");
    expect(screen.queryByText(/conflit/)).not.toBeInTheDocument();
  });

  it("firstVisit → RIEN (pas de bandeau)", () => {
    render(<ModuleVisitBanner delta={delta({ firstVisit: true, newFixturesCount: 9, newConflictFingerprints: ["a"], planningChanged: true })} />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("delta VIDE → RIEN", () => {
    render(<ModuleVisitBanner delta={delta({})} />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("delta ABSENT (query pas résolue) → RIEN", () => {
    render(<ModuleVisitBanner delta={undefined} />);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });
});
