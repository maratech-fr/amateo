import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { afterEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";
import type { DeadlineOutlook, FbiTodo } from "@/features/matches/api";

import { FbiDeadlineCard } from "./FbiDeadlineCard";

// La tuile ne consomme QUE l'outlook (règle J-7 + `fbiTodo` + bloc gardien = BACKEND).
let outlook: DeadlineOutlook | undefined;
vi.mock("@/features/matches/queries", () => ({
  useDeadlineOutlook: () => ({ data: outlook }),
}));

const NONE: FbiTodo = { toEnter: 0, toCorrect: 0 };

function renderCard() {
  return render(
    <MemoryRouter>
      <FbiDeadlineCard />
    </MemoryRouter>,
  );
}

describe("FbiDeadlineCard — la carte « Saisie FBI » du cockpit", () => {
  afterEach(() => {
    outlook = undefined;
    setTodayOverride(null);
  });

  it("aucune fenêtre ET rien à faire → AUCUN rendu (le cockpit reste muet)", () => {
    setTodayOverride("2026-09-01");
    outlook = { windows: [{ deadline: "2026-10-20", source: "club", competitionNames: ["DF2"], toEnterCount: 3, withinWindow: false }], fbiTodo: NONE };
    const { container } = renderCard();
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(container).toBeEmptyDOMElement();
  });

  it("réponse sans fenêtre et fbiTodo vide → AUCUN rendu", () => {
    outlook = { windows: [], fbiTodo: NONE };
    expect(renderCard().container).toBeEmptyDOMElement();
  });

  it("data pas résolue → AUCUN rendu", () => {
    outlook = undefined;
    expect(renderCard().container).toBeEmptyDOMElement();
  });

  it("hors fenêtre mais du travail → carte NEUTRE, ligne globale, lien → /matchs?fbi=1", () => {
    setTodayOverride("2026-09-01");
    outlook = { windows: [], fbiTodo: { toEnter: 5, toCorrect: 2 } };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("Saisie FBI");
    expect(card).toHaveTextContent("7 FBI à faire, dont 2 à corriger");
    expect(card).not.toHaveTextContent("à saisir avant le");
    expect(card.className).toContain("border-border");
    expect(card.className).not.toContain("warning");
    expect(screen.getByRole("link")).toHaveAttribute("href", "/matchs?fbi=1");
  });

  it("hors fenêtre, rien à corriger → la ligne globale SANS « dont »", () => {
    setTodayOverride("2026-09-01");
    outlook = { windows: [], fbiTodo: { toEnter: 4, toCorrect: 0 } };
    renderCard();
    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("4 FBI à faire");
    expect(card).not.toHaveTextContent("dont");
  });

  it("en fenêtre → la ligne globale, les échéances, les compétitions ; lien → /matchs?fbi=1", () => {
    setTodayOverride("2026-09-07");
    outlook = {
      windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["Départemental F2", "Régional M18"], toEnterCount: 6, withinWindow: true }],
      fbiTodo: { toEnter: 6, toCorrect: 0 },
    };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("Saisie FBI");
    expect(card).toHaveTextContent("6 FBI à faire");
    expect(card).toHaveTextContent("6 matchs à saisir avant le");
    expect(card).toHaveTextContent("Départemental F2");
    expect(card).not.toHaveTextContent("proposée");
    expect(screen.getByRole("link")).toHaveAttribute("href", "/matchs?fbi=1");
  });

  it("source communautaire → « · proposée »", () => {
    setTodayOverride("2026-09-07");
    outlook = { windows: [{ deadline: "2026-09-10", source: "community", competitionNames: ["DF2"], toEnterCount: 2, withinWindow: true }], fbiTodo: { toEnter: 2, toCorrect: 0 } };
    renderCard();
    expect(screen.getByRole("status")).toHaveTextContent("proposée");
  });

  it("dépassée avec du reste → la carte RESTE, ton warning (jamais destructive)", () => {
    setTodayOverride("2026-09-12");
    outlook = { windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["DF2"], toEnterCount: 2, withinWindow: true }], fbiTodo: { toEnter: 2, toCorrect: 0 } };
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
      fbiTodo: { toEnter: 6, toCorrect: 0 },
      guardianDelta: { newFixturesCount: 12, newConflictFingerprints: ["a", "b", "c"], planningChanged: true },
    };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("Depuis votre dernière visite");
    expect(card).toHaveTextContent("12 matchs arrivés");
    expect(card).toHaveTextContent("3 nouveaux conflits");
    expect(card).toHaveTextContent("le planning de saison a changé");
  });
});
