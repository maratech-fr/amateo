import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { afterEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";
import type { DeadlineOutlook } from "@/features/matches/api";

import { FbiDeadlineCard } from "./FbiDeadlineCard";

// La tuile ne consomme QUE l'outlook (règle J-7 + bloc gardien calculés BACKEND).
let outlook: DeadlineOutlook | undefined;
vi.mock("@/features/matches/queries", () => ({
  useDeadlineOutlook: () => ({ data: outlook }),
}));

function renderCard() {
  return render(
    <MemoryRouter>
      <FbiDeadlineCard />
    </MemoryRouter>,
  );
}

describe("FbiDeadlineCard (RMM-6 PR-3 — la première incursion des matchs au cockpit)", () => {
  afterEach(() => {
    outlook = undefined;
    setTodayOverride(null);
  });

  it("hors fenêtre (aucune withinWindow) → AUCUN rendu (le cockpit reste muet sur les matchs)", () => {
    setTodayOverride("2026-09-01");
    outlook = { windows: [{ deadline: "2026-10-20", source: "club", competitionNames: ["DF2"], toEnterCount: 3, withinWindow: false }] };
    const { container } = renderCard();
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(container).toBeEmptyDOMElement();
  });

  it("réponse sans fenêtre (windows vide) → AUCUN rendu", () => {
    outlook = { windows: [] };
    expect(renderCard().container).toBeEmptyDOMElement();
  });

  it("data pas résolue → AUCUN rendu", () => {
    outlook = undefined;
    expect(renderCard().container).toBeEmptyDOMElement();
  });

  it("en fenêtre → la carte, le compte, la date, les compétitions ; lien → /matchs", () => {
    setTodayOverride("2026-09-07");
    outlook = { windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["Départemental F2", "Régional M18"], toEnterCount: 6, withinWindow: true }] };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("Saisie FBI");
    expect(card).toHaveTextContent("6 matchs à saisir avant le");
    expect(card).toHaveTextContent("Départemental F2");
    expect(card).toHaveTextContent("Régional M18");
    expect(card).not.toHaveTextContent("proposée");
    expect(screen.getByRole("link")).toHaveAttribute("href", "/matchs");
  });

  it("source communautaire → « · proposée »", () => {
    setTodayOverride("2026-09-07");
    outlook = { windows: [{ deadline: "2026-09-10", source: "community", competitionNames: ["DF2"], toEnterCount: 2, withinWindow: true }] };
    renderCard();
    expect(screen.getByRole("status")).toHaveTextContent("proposée");
  });

  it("dépassée avec du reste → la carte RESTE, ton warning (jamais destructive)", () => {
    setTodayOverride("2026-09-12");
    outlook = { windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["DF2"], toEnterCount: 2, withinWindow: true }] };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("échéance dépassée");
    expect(card).toHaveTextContent("2 matchs toujours non saisis");
    expect(card.className).toContain("border-warning");
    expect(card.className).not.toContain("destructive");
  });

  it("guardianDelta joint → les segments s'affichent dans la carte", () => {
    setTodayOverride("2026-09-07");
    outlook = {
      windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["DF2"], toEnterCount: 6, withinWindow: true }],
      guardianDelta: { newFixturesCount: 12, newConflictFingerprints: ["a", "b", "c"], planningChanged: true },
    };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("Depuis votre dernière visite");
    expect(card).toHaveTextContent("12 matchs arrivés");
    expect(card).toHaveTextContent("3 nouveaux conflits");
    expect(card).toHaveTextContent("le planning de saison a changé");
  });

  it("guardianDelta absent → la carte sans le bloc visite (pas de trou)", () => {
    setTodayOverride("2026-09-07");
    outlook = { windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["DF2"], toEnterCount: 6, withinWindow: true }] };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("6 matchs à saisir avant le");
    expect(card).not.toHaveTextContent("Depuis votre dernière visite");
  });
});
