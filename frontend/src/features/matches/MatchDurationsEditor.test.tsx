import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { SportCategoryDuration } from "./api";
import { MatchDurationsEditor } from "./MatchDurationsEditor";

const updateMutate = vi.fn();

// On pilote le hook PROD, jamais le réseau (patron EntryDeadlinesEditor / TravelMatrix).
vi.mock("./queries", () => ({
  useUpdateSportCategoryDuration: () => ({ mutate: updateMutate, isPending: false }),
}));

const cat = (over: Partial<SportCategoryDuration> & { id: string; name: string }): SportCategoryDuration => ({
  sportId: "sport-1",
  matchMinutes: null,
  warmupMinutes: null,
  defaultMatchMinutes: 105,
  defaultWarmupMinutes: 30,
  ...over,
});

// Deux familles servies par le serveur : U13 (défaut 90/30, héritée) et Seniors
// (défaut 105/30, override sur le match).
const CATEGORIES: SportCategoryDuration[] = [
  cat({ id: "c-u13", name: "U13", defaultMatchMinutes: 90, defaultWarmupMinutes: 30, matchMinutes: null, warmupMinutes: null }),
  cat({ id: "c-sen", name: "Senior", defaultMatchMinutes: 105, defaultWarmupMinutes: 30, matchMinutes: 100, warmupMinutes: null }),
];

beforeEach(() => {
  updateMutate.mockReset();
});

describe("MatchDurationsEditor — la durée de match par catégorie (P2-54 RMM-9)", () => {
  it("énonce le défaut de famille SERVI une fois par groupe (jamais recalculé côté front)", () => {
    renderWithProviders(<MatchDurationsEditor categories={CATEGORIES} />);
    // Le serveur sert 90/30 pour U13 et 105/30 pour Seniors : deux en-têtes distincts.
    expect(screen.getByText(/90 min de match/)).toBeInTheDocument();
    expect(screen.getByText(/105 min de match/)).toBeInTheDocument();
  });

  it("catégorie héritée : champ vide + placeholder = défaut servi + marque « défaut »", () => {
    renderWithProviders(<MatchDurationsEditor categories={CATEGORIES} />);
    const matchField = screen.getByLabelText("Durée du match — U13") as HTMLInputElement;
    expect(matchField.value).toBe("");
    expect(matchField.placeholder).toBe("90");
    const row = matchField.closest("li") as HTMLElement;
    expect(within(row).getAllByText(/défaut/i).length).toBeGreaterThan(0);
  });

  it("catégorie ajustée : valeur pleine + marque « Ajusté »", () => {
    renderWithProviders(<MatchDurationsEditor categories={CATEGORIES} />);
    const matchField = screen.getByLabelText("Durée du match — Senior") as HTMLInputElement;
    expect(matchField.value).toBe("100");
    const row = matchField.closest("li") as HTMLElement;
    expect(within(row).getByText(/Ajusté/)).toBeInTheDocument();
  });

  it("éditer le champ Match + blur → PUT avec la nouvelle valeur, l'échauffement inchangé", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchDurationsEditor categories={CATEGORIES} />);
    const field = screen.getByLabelText("Durée du match — U13");
    await user.type(field, "80");
    await user.tab();

    expect(updateMutate).toHaveBeenCalledTimes(1);
    expect(updateMutate.mock.calls[0][0]).toEqual({ category: CATEGORIES[0], input: { matchMinutes: 80, warmupMinutes: null } });
  });

  it("« Revenir au défaut » → PUT matchMinutes:null ET warmupMinutes:null (explicite)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchDurationsEditor categories={CATEGORIES} />);
    await user.click(screen.getByRole("button", { name: "Revenir au défaut — Senior" }));

    expect(updateMutate).toHaveBeenCalledTimes(1);
    expect(updateMutate.mock.calls[0][0]).toEqual({ category: CATEGORIES[1], input: { matchMinutes: null, warmupMinutes: null } });
  });

  it("« Revenir au défaut » est désactivé quand la catégorie est déjà au défaut (rien à réinitialiser)", () => {
    renderWithProviders(<MatchDurationsEditor categories={CATEGORIES} />);
    expect(screen.getByRole("button", { name: "Revenir au défaut — U13" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Revenir au défaut — Senior" })).toBeEnabled();
  });

  it("hors bornes : garde la saisie, aria-invalid + message, AUCUN PUT", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchDurationsEditor categories={CATEGORIES} />);
    const field = screen.getByLabelText("Durée du match — U13") as HTMLInputElement;
    await user.type(field, "20"); // sous la borne basse (30)
    await user.tab();

    expect(updateMutate).not.toHaveBeenCalled();
    expect(field.value).toBe("20"); // la saisie n'est PAS restaurée en silence
    expect(field).toHaveAttribute("aria-invalid", "true");
    expect(within(field.closest("li") as HTMLElement).getByRole("alert")).toHaveTextContent(/entre 30 et 240/);
  });

  it("champ vidé sur blur → restaure la valeur servie, AUCUN PUT (le reset ne passe que par le bouton)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchDurationsEditor categories={CATEGORIES} />);
    const field = screen.getByLabelText("Durée du match — Senior") as HTMLInputElement;
    await user.clear(field);
    await user.tab();

    expect(updateMutate).not.toHaveBeenCalled();
    expect(field.value).toBe("100");
  });
});
