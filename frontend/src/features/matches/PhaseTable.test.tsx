import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Coach, Conflict, Team, Venue } from "./api";
import { PhaseTable } from "./PhaseTable";

// État « aucune compétition appariée » (phaseCount=0) : la SEULE surface qui porte le bouton
// d'action « Engagements FFBB » (appariement = geste gestionnaire, décision fondateur 2026-10-04).
function renderEmpty(onOpenFfbb?: () => void) {
  return render(
    <PhaseTable
      phaseCount={0}
      groups={[]}
      competition={undefined}
      teams={new Map<string, Team>()}
      venues={new Map<string, Venue>()}
      coaches={new Map<string, Coach>()}
      conflictsByFixture={new Map<string, Conflict[]>()}
      onSelectFixture={vi.fn()}
      onFocusConflict={vi.fn()}
      onOpenFfbb={onOpenFfbb}
    />,
  );
}

describe("PhaseTable — bouton « Engagements FFBB » gardé par l'appariement (2026-10-04)", () => {
  it("gestionnaire (onOpenFfbb fourni) → le bouton est rendu, l'état vide aussi", () => {
    renderEmpty(vi.fn());
    expect(screen.getByText("Aucune compétition appariée")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Engagements FFBB" })).toBeInTheDocument();
  });

  it("membre (onOpenFfbb absent) → l'état vide reste visible, SANS le bouton d'action", () => {
    renderEmpty(undefined);
    expect(screen.getByText("Aucune compétition appariée")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Engagements FFBB" })).not.toBeInTheDocument();
  });
});
