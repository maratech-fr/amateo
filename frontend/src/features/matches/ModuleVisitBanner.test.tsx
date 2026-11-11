import { render as rtlRender, screen, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter } from "react-router";
import { describe, expect, it } from "vitest";

import type { ModuleVisitDelta } from "./api";
import { ModuleVisitBanner } from "./ModuleVisitBanner";
import { moduleVisitSummary } from "./lib/moduleVisitSummary";
import { MATCHES_TEXTS } from "./texts";

/** Le bandeau porte des liens (B1) : il se rend dans un routeur, comme dans le module. */
function render(ui: ReactElement) {
  return rtlRender(<MemoryRouter>{ui}</MemoryRouter>);
}

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
    expect(banner).toHaveTextContent(MATCHES_TEXTS.sinceLastVisit);
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

  // FRT-37 — un delta MALFORMÉ (`{}` : payload partiel) abattait la route (`.length` sur
  // `undefined`). La garde de forme de `visitDeltaSegments` rend une liste vide → le bandeau
  // ne paraît pas, et rien ne casse.
  it("delta MALFORMÉ (`{}`) → RIEN, aucune exception", () => {
    expect(() => render(<ModuleVisitBanner delta={{} as never} />)).not.toThrow();
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });
});

// B1 — chaque morceau mène là où agir : les matchs arrivés à l'Importer, les conflits neufs aux
// seuls conflits nouveaux. « Le planning a changé » reste un texte (aucun écran unique à ouvrir).
describe("ModuleVisitBanner — bandeau cliquable (B1)", () => {
  it("delta PLEIN → deux liens et un texte", () => {
    render(<ModuleVisitBanner delta={delta({ newFixturesCount: 12, newConflictFingerprints: ["a", "b", "c"], planningChanged: true })} />);
    const banner = screen.getByRole("status");
    expect(within(banner).getByRole("link", { name: "12 matchs arrivés" })).toHaveAttribute("href", "/matchs/importer");
    expect(within(banner).getByRole("link", { name: "3 nouveaux conflits" })).toHaveAttribute("href", "/matchs/conflits?nouveaux=1");
    expect(within(banner).getAllByRole("link")).toHaveLength(2);
    expect(banner).toHaveTextContent("le planning de saison a changé");
  });

  it("planning changé SEUL → le texte, aucun lien", () => {
    render(<ModuleVisitBanner delta={delta({ planningChanged: true })} />);
    const banner = screen.getByRole("status");
    expect(banner).toHaveTextContent("le planning de saison a changé");
    expect(within(banner).queryByRole("link")).not.toBeInTheDocument();
  });
});
