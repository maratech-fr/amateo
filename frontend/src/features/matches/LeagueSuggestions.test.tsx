import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { LeagueWindowSuggestions } from "./api";
import { LeagueSuggestions } from "./LeagueSuggestions";

const applyMutate = vi.fn();
const suggestionsState: { data: LeagueWindowSuggestions | undefined } = { data: undefined };

// On pilote les hooks (ce que le SERVEUR sert), jamais le réseau. Le calcul (seuil,
// majorité, masquage) vit côté serveur : l'écran l'affiche, il ne le redérive pas.
vi.mock("./queries", () => ({
  useLeagueWindowSuggestions: () => suggestionsState,
  useApplyLeagueWindowSuggestions: () => ({ mutate: applyMutate, isPending: false }),
}));

const suggestions = (over: Partial<LeagueWindowSuggestions> = {}): LeagueWindowSuggestions => ({
  instance: { ligue: "ARA", comite: "0069" },
  items: [],
  ...over,
});

beforeEach(() => {
  applyMutate.mockClear();
  suggestionsState.data = undefined;
});

describe("LeagueSuggestions — bloc « Plages suggérées » (P4-272 ②)", () => {
  it("n'affiche RIEN quand l'instance est illisible", () => {
    suggestionsState.data = suggestions({ instance: null, items: [{ category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6, windows: [{ kickoffMin: "13:00", kickoffMax: "18:00" }], clubCount: 4, source: "clubs", scope: "comite" }] });
    const { container } = renderWithProviders(<LeagueSuggestions />);
    expect(container).toBeEmptyDOMElement();
  });

  it("n'affiche RIEN quand aucun item n'est servi", () => {
    suggestionsState.data = suggestions({ items: [] });
    const { container } = renderWithProviders(<LeagueSuggestions />);
    expect(container).toBeEmptyDOMElement();
  });

  it("affiche une tendance de clubs avec son compte N et sa portée (jamais QUELS clubs)", () => {
    suggestionsState.data = suggestions({
      items: [{ category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6, windows: [{ kickoffMin: "13:00", kickoffMax: "18:00" }], clubCount: 4, source: "clubs", scope: "comite" }],
    });
    renderWithProviders(<LeagueSuggestions />);
    expect(screen.getByText("Plages suggérées (estimation)")).toBeInTheDocument();
    expect(screen.getByText(/Estimation à partir de 4 clubs de votre comité — à vérifier auprès de votre ligue et de votre comité/)).toBeInTheDocument();
    expect(screen.getByText(/U13 · Tous · samedi 13:00–18:00/)).toBeInTheDocument();
  });

  it("étiquette une ligne de repli fédéral « données fédérales »", () => {
    suggestionsState.data = suggestions({
      items: [{ category: "U15", level: "REGIONAL", gender: "M", dayOfWeek: 7, windows: [{ kickoffMin: "10:00", kickoffMax: "16:30" }], clubCount: null, source: "federation", scope: "federation" }],
    });
    renderWithProviders(<LeagueSuggestions />);
    expect(screen.getByText("données fédérales")).toBeInTheDocument();
  });

  it("« Appliquer » envoie la SEULE combinaison de la ligne (jamais les plages)", async () => {
    const user = userEvent.setup();
    suggestionsState.data = suggestions({
      items: [{ category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6, windows: [{ kickoffMin: "13:00", kickoffMax: "18:00" }], clubCount: 4, source: "clubs", scope: "comite" }],
    });
    renderWithProviders(<LeagueSuggestions />);

    await user.click(screen.getByRole("button", { name: "Appliquer" }));
    expect(applyMutate).toHaveBeenCalledWith([{ category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6 }]);
  });

  it("« Tout appliquer » envoie toutes les combinaisons servies", async () => {
    const user = userEvent.setup();
    suggestionsState.data = suggestions({
      items: [
        { category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6, windows: [{ kickoffMin: "13:00", kickoffMax: "18:00" }], clubCount: 4, source: "clubs", scope: "comite" },
        { category: "U15", level: "REGIONAL", gender: "M", dayOfWeek: 7, windows: [{ kickoffMin: "10:00", kickoffMax: "16:30" }], clubCount: null, source: "federation", scope: "federation" },
      ],
    });
    renderWithProviders(<LeagueSuggestions />);

    await user.click(screen.getByRole("button", { name: "Tout appliquer" }));
    expect(applyMutate).toHaveBeenCalledWith([
      { category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6 },
      { category: "U15", level: "REGIONAL", gender: "M", dayOfWeek: 7 },
    ]);
  });
});
