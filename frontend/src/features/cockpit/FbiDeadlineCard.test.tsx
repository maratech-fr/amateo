import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { afterEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";
import type { DeadlineOutlook, FbiTodo, LeagueValidationOutlook } from "@/features/matches/api";

import { FbiDeadlineCard } from "./FbiDeadlineCard";

// La tuile ne consomme QUE l'outlook (règle J-7 + `fbiTodo` + `toConfirmCount` + gardien =
// BACKEND) et le rôle (le bouton « validé ligue » est réservé au gestionnaire).
let outlook: DeadlineOutlook | undefined;
let leagueOutlook: LeagueValidationOutlook | undefined;
let meRole: string | undefined;
vi.mock("@/features/matches/queries", () => ({
  useDeadlineOutlook: () => ({ data: outlook }),
  useLeagueValidationOutlook: () => ({ data: leagueOutlook }),
  useConfirmLeagueValidatedFixtures: () => ({ mutate: vi.fn(), isPending: false }),
}));
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { role: meRole } }),
}));

const NONE: FbiTodo = { toEnter: 0, toCorrect: 0 };
const LEAGUE: LeagueValidationOutlook = {
  matured: [{ competitionId: "c1", name: "PNM", deadline: "2026-11-10", deadlineSource: "club", maturedBy: "deadline", firstMatchDate: null, validatableCount: 5 }],
  toTreat: [],
  missingDeadline: [],
  totalValidatable: 5,
};

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
    leagueOutlook = undefined;
    meRole = undefined;
    setTodayOverride(null);
  });

  it("aucune fenêtre ET rien à faire → AUCUN rendu (le cockpit reste muet)", () => {
    setTodayOverride("2026-09-01");
    outlook = { windows: [{ deadline: "2026-10-20", source: "club", competitionNames: ["DF2"], toPlaceCount: 0, toEnterCount: 3, withinWindow: false }], fbiTodo: NONE };
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

  it("en fenêtre, tout PLACÉ → « à saisir dans FBI », lien FBI → /matchs?fbi=1", () => {
    setTodayOverride("2026-09-07");
    outlook = {
      windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["Départemental F2", "Régional M18"], toPlaceCount: 0, toEnterCount: 6, withinWindow: true }],
      fbiTodo: { toEnter: 6, toCorrect: 0 },
    };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("Saisie FBI");
    expect(card).toHaveTextContent("6 FBI à faire");
    expect(card).toHaveTextContent("6 à saisir dans FBI avant le");
    expect(card).not.toHaveTextContent("à placer");
    expect(card).toHaveTextContent("Départemental F2");
    expect(card).not.toHaveTextContent("proposée");
    expect(screen.getByRole("link")).toHaveAttribute("href", "/matchs?fbi=1");
    expect(screen.getByRole("link")).toHaveTextContent("Ouvrir la liste FBI");
  });

  it("source communautaire → « · proposée »", () => {
    setTodayOverride("2026-09-07");
    outlook = { windows: [{ deadline: "2026-09-10", source: "community", competitionNames: ["DF2"], toPlaceCount: 0, toEnterCount: 2, withinWindow: true }], fbiTodo: { toEnter: 2, toCorrect: 0 } };
    renderCard();
    expect(screen.getByRole("status")).toHaveTextContent("proposée");
  });

  it("dépassée avec du reste → la carte RESTE, ton warning (jamais destructive)", () => {
    setTodayOverride("2026-09-12");
    outlook = { windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["DF2"], toPlaceCount: 0, toEnterCount: 2, withinWindow: true }], fbiTodo: { toEnter: 2, toCorrect: 0 } };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("échéance dépassée");
    expect(card).toHaveTextContent("2 à saisir dans FBI");
    expect(card.className).toContain("border-warning");
    expect(card.className).not.toContain("destructive");
  });

  // Le retour fondateur (2026-09-27) : tout est encore À PLACER → la carte doit dire « à placer »
  // (jamais « non saisi », un mensonge) et NE PAS mener à la liste FBI (qui serait vide).
  it("tout UNPLACED → « à placer », bouton « Placer les matchs » → /matchs, jamais la liste FBI", () => {
    setTodayOverride("2026-09-12");
    outlook = {
      windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["DF2"], toPlaceCount: 106, toEnterCount: 0, withinWindow: true }],
      fbiTodo: { toEnter: 0, toCorrect: 0 },
    };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("échéance dépassée — 106 matchs à placer");
    expect(card).not.toHaveTextContent("à saisir dans FBI");
    expect(card).not.toHaveTextContent("non saisi");

    const link = screen.getByRole("link");
    expect(link).toHaveTextContent("Placer les matchs");
    expect(link).toHaveAttribute("href", "/matchs");
    expect(card).not.toHaveTextContent("Ouvrir la liste FBI");
  });

  it("mixte à placer + à saisir → les deux segments, lien FBI (la liste a du contenu)", () => {
    setTodayOverride("2026-09-07");
    outlook = {
      windows: [{ deadline: "2026-10-15", source: "club", competitionNames: ["DF2"], toPlaceCount: 12, toEnterCount: 3, withinWindow: true }],
      fbiTodo: { toEnter: 3, toCorrect: 0 },
    };
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("12 matchs à placer");
    expect(card).toHaveTextContent("3 à saisir dans FBI avant le");
    expect(screen.getByRole("link")).toHaveAttribute("href", "/matchs?fbi=1");
  });

  it("gestionnaire + des matchs à confirmer → ligne « à confirmer » + bouton « validé ligue »", () => {
    meRole = "admin";
    outlook = { windows: [], fbiTodo: NONE, toConfirmCount: 5 };
    leagueOutlook = LEAGUE;
    renderCard();

    const card = screen.getByRole("status");
    expect(card).toHaveTextContent("5 matchs à confirmer « validé ligue »");
    expect(screen.getByRole("button", { name: /Marquer « validé ligue »/ })).toBeInTheDocument();
  });

  it("Membre → jamais la ligne « à confirmer », même avec toConfirmCount > 0 (aucune écriture proposée)", () => {
    meRole = "member";
    outlook = { windows: [], fbiTodo: NONE, toConfirmCount: 5 };
    const { container } = renderCard();
    // Aucune fenêtre, rien à faire, et le Membre ne peut pas confirmer → carte muette.
    expect(container).toBeEmptyDOMElement();
  });

  it("gestionnaire mais 0 à confirmer → pas de ligne « à confirmer »", () => {
    meRole = "admin";
    outlook = { windows: [], fbiTodo: NONE, toConfirmCount: 0 };
    const { container } = renderCard();
    expect(container).toBeEmptyDOMElement();
  });

  it("guardianDelta joint → les segments s'affichent dans la carte", () => {
    setTodayOverride("2026-09-07");
    outlook = {
      windows: [{ deadline: "2026-09-10", source: "club", competitionNames: ["DF2"], toPlaceCount: 0, toEnterCount: 6, withinWindow: true }],
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
