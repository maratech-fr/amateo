import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { TypicalWeekPage } from "./TypicalWeekPage";

// PR 2a — la Semaine type porte le gabarit idéal + les créneaux partagés, sortis de la
// Configuration. On mute la couche api (PROD) et react-query tourne pour de vrai.
const state: Record<string, unknown[] | "error"> = { teams: [], tiers: [], venues: [], fixtures: [], habits: [], rotations: [], links: [] };

function serve(key: string): Promise<unknown> {
  const value = state[key];
  return "error" === value ? Promise.reject(new Error("boom")) : Promise.resolve(value);
}

vi.mock("./api", () => ({
  getTeams: () => serve("teams"),
  getPriorityTiers: () => serve("tiers"),
  getVenues: () => serve("venues"),
  getFixtures: () => serve("fixtures"),
  getTeamMatchHabits: () => serve("habits"),
  getMatchSlotRotations: () => serve("rotations"),
  getTeamLinks: () => serve("links"),
  createMatchSlotRotation: vi.fn(),
  updateMatchSlotRotation: vi.fn(),
  deleteMatchSlotRotation: vi.fn(),
  createTeamMatchHabit: vi.fn(),
  deleteTeamMatchHabit: vi.fn(),
  createTeamLink: vi.fn(),
  updateTeamLink: vi.fn(),
  deleteTeamLink: vi.fn(),
}));

beforeEach(() => {
  state.teams = [{ id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 }];
  state.tiers = [{ id: 3, label: "B", name: "Moyenne", color: null }];
  state.venues = [{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00", externalLabels: [] }];
  state.fixtures = [];
  state.habits = [];
  state.rotations = [];
  state.links = [];
});

describe("TypicalWeekPage (PR 2a — la Semaine type)", () => {
  it("titre « Semaine type » + intro", async () => {
    renderWithProviders(<TypicalWeekPage />);
    expect(await screen.findByRole("heading", { name: "Semaine type", level: 2 })).toBeInTheDocument();
    expect(screen.getByText(/le modèle que le placement respecte au maximum/)).toBeInTheDocument();
  });

  it("porte la grille du gabarit idéal (semaine type)", async () => {
    renderWithProviders(<TypicalWeekPage />);
    // Le corps de la grille (état vide sans habitude déclarée) est monté.
    expect(await screen.findByText(/Aucune habitude déclarée/)).toBeInTheDocument();
  });

  it("porte l'éditeur de créneaux partagés (son propre <h3>)", async () => {
    renderWithProviders(<TypicalWeekPage />);
    expect(await screen.findByRole("heading", { name: "Créneaux partagés (alternance)", level: 3 })).toBeInTheDocument();
  });

  it("le bouton « Habitudes & passerelles » ouvre la modale", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TypicalWeekPage />);
    await user.click(await screen.findByRole("button", { name: "Habitudes & passerelles" }));
    // Le nom accessible de la modale vient de son `label` (« et »), le titre visible garde le « & ».
    expect(await screen.findByRole("dialog", { name: "Habitudes et passerelles" })).toBeInTheDocument();
  });

  // UXS-08 — la Semaine type est gatée sur ses lectures. Un échec doit céder à une alerte avec
  // réessai, jamais rendre « Aucune habitude déclarée » (vide crédible) sur une lecture en échec.
  it("UXS-08 — useVenues en ÉCHEC → une alerte, jamais un vide crédible", async () => {
    state.venues = "error";
    renderWithProviders(<TypicalWeekPage />);
    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(screen.queryByText(/Aucune habitude déclarée/)).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Semaine type", level: 2 })).not.toBeInTheDocument();
  });
});
