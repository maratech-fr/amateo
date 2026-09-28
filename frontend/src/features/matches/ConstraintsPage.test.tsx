import { fireEvent, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { ClubLeagueWindow } from "./api";
import { ConstraintsPage } from "./ConstraintsPage";

const createWindow = vi.fn();
const updateWindow = vi.fn();
const deleteWindow = vi.fn();
const windowsState: { data: ClubLeagueWindow[] | undefined; isError: boolean } = { data: [], isError: false };

// On pilote les hooks (miroir de la copie stockée), jamais le réseau. Le badge vient
// du SERVEUR : l'écran l'affiche, on ne le recalcule pas ici.
vi.mock("./queries", () => ({
  useClubLeagueWindows: () => ({ ...windowsState, refetch: vi.fn() }),
  useCreateClubLeagueWindow: () => ({ mutate: createWindow, isPending: false }),
  useUpdateClubLeagueWindow: () => ({ mutate: updateWindow, isPending: false }),
  useDeleteClubLeagueWindow: () => ({ mutate: deleteWindow, isPending: false }),
}));

const window = (over: Partial<ClubLeagueWindow> = {}): ClubLeagueWindow => ({
  id: "w1",
  version: 1,
  league: "AURA",
  category: "Seniors",
  level: "REGIONAL",
  gender: null,
  dayOfWeek: 6,
  kickoffMin: "14:00",
  kickoffMax: "16:00",
  badge: null,
  ...over,
});

function openLigue(): void {
  renderWithProviders(<ConstraintsPage />, { route: "/matchs/contraintes?section=ligue" });
}

beforeEach(() => {
  createWindow.mockClear();
  updateWindow.mockClear();
  deleteWindow.mockClear();
  windowsState.data = [];
  windowsState.isError = false;
});

describe("ConstraintsPage — section Ligue (P4-272 ①)", () => {
  it("affiche le bandeau « aucune règle fédérale » quand la copie est vide", () => {
    windowsState.data = [];
    openLigue();
    expect(screen.getByText(/Aucune fenêtre ligue — le placement n'applique plus de règle fédérale\./)).toBeInTheDocument();
  });

  it("affiche le badge « modifié »/« ajouté » calculé par le serveur (jamais redérivé)", () => {
    windowsState.data = [window({ id: "w1", category: "Seniors", badge: "modified" }), window({ id: "w2", category: "Poussins", badge: "added" })];
    openLigue();
    expect(screen.getByText("Modifié")).toBeInTheDocument();
    expect(screen.getByText("Ajouté")).toBeInTheDocument();
  });

  it("ajoute une fenêtre via l'API (genre « Tous » → null)", async () => {
    const user = userEvent.setup();
    windowsState.data = [];
    openLigue();

    // La ligne d'ajout (bordée en pointillés) porte le bouton « Ajouter ».
    const addRow = screen.getByRole("button", { name: "Ajouter" }).closest("div") as HTMLElement;
    fireEvent.change(within(addRow).getByLabelText("Catégorie"), { target: { value: "U13" } });
    await user.selectOptions(within(addRow).getByLabelText("Niveau"), "DEPARTEMENTAL");
    await user.selectOptions(within(addRow).getByLabelText("Jour"), "7");
    fireEvent.change(within(addRow).getByLabelText("De"), { target: { value: "10:00" } });
    fireEvent.change(within(addRow).getByLabelText("À"), { target: { value: "11:30" } });

    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(createWindow).toHaveBeenCalledWith(
      { category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 7, kickoffMin: "10:00", kickoffMax: "11:30" },
      expect.anything(),
    );
  });

  it("enregistre une correction de fenêtre via l'API (PUT id + input)", async () => {
    const user = userEvent.setup();
    windowsState.data = [window({ id: "w1", kickoffMax: "16:00" })];
    openLigue();

    // Le bouton reste inerte tant que rien n'a changé.
    const save = screen.getByRole("button", { name: "Enregistrer" });
    expect(save).toBeDisabled();
    // La ligne d'édition rend AVANT la ligne d'ajout → le premier champ « À » est le sien.
    fireEvent.change(screen.getAllByLabelText("À")[0], { target: { value: "17:30" } });
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(updateWindow).toHaveBeenCalledWith({
      id: "w1",
      input: { category: "Seniors", level: "REGIONAL", gender: null, dayOfWeek: 6, kickoffMin: "14:00", kickoffMax: "17:30" },
    });
  });
});
