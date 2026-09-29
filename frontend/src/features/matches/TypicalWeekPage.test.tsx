import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { TypicalWeekPage } from "./TypicalWeekPage";

// PR 2a — la Semaine type porte le gabarit idéal + les créneaux partagés, sortis de la
// Configuration. On mute la couche api (PROD) et react-query tourne pour de vrai.
const state: Record<string, unknown[] | "error"> = { teams: [], tiers: [], venues: [], fixtures: [], habits: [], links: [], durations: [] };

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
  getTeamLinks: () => serve("links"),
  // P4-206 — la Semaine type charge les durées de catégorie (même source que le Calendrier)
  // pour dessiner la durée réelle par équipe ; elles sont GATÉES avec les autres lectures.
  getSportCategoryDurations: () => serve("durations"),
  // P4-272 ③ — l'éditeur de créneaux idéaux lit l'alerte de cohérence (calculée serveur).
  getMatchConstraintCoherence: () => Promise.resolve({ byRule: [], byHabit: [] }),
  createTeamMatchHabit: vi.fn(),
  updateTeamMatchHabit: vi.fn(),
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
  state.links = [];
  state.durations = [{ id: "cat-1", sportId: "s", name: "U13", matchMinutes: null, warmupMinutes: null, defaultMatchMinutes: 90, defaultWarmupMinutes: 20 }];
});

describe("TypicalWeekPage (PR 2a — la Semaine type)", () => {
  it("titre « Semaine type » + intro", async () => {
    renderWithProviders(<TypicalWeekPage />);
    expect(await screen.findByRole("heading", { name: "Semaine type", level: 2 })).toBeInTheDocument();
    expect(screen.getByText(/le modèle que le placement respecte au maximum/)).toBeInTheDocument();
  });

  it("porte la grille du gabarit idéal (semaine type)", async () => {
    renderWithProviders(<TypicalWeekPage />);
    // Le corps de la grille (état vide sans créneau idéal déclaré) est monté.
    expect(await screen.findByText(/Aucun créneau idéal déclaré/)).toBeInTheDocument();
  });

  it("porte l'éditeur de créneaux idéaux (son propre <h3>)", async () => {
    renderWithProviders(<TypicalWeekPage />);
    expect(await screen.findByRole("heading", { name: "Créneaux idéaux", level: 3 })).toBeInTheDocument();
  });

  it("le bouton « Passerelles » ouvre la modale des passerelles (P4-271 : plus d'habitudes ici)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TypicalWeekPage />);
    await user.click(await screen.findByRole("button", { name: "Passerelles" }));
    expect(await screen.findByRole("dialog", { name: "Passerelles" })).toBeInTheDocument();
  });

  // UXS-08 — la Semaine type est gatée sur ses lectures. Un échec doit céder à une alerte avec
  // réessai, jamais rendre « Aucun créneau idéal déclaré » (vide crédible) sur une lecture en échec.
  it("UXS-08 — useVenues en ÉCHEC → une alerte, jamais un vide crédible", async () => {
    state.venues = "error";
    renderWithProviders(<TypicalWeekPage />);
    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(screen.queryByText(/Aucun créneau idéal déclaré/)).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Semaine type", level: 2 })).not.toBeInTheDocument();
  });

  // P4-206 / UXS-08 — les durées de catégorie rejoignent le gate : leur échec cède à l'alerte,
  // jamais un vide crédible (« Aucun créneau idéal déclaré ») dessiné sans les durées servies.
  it("UXS-08 — useSportCategoryDurations en ÉCHEC → une alerte, jamais un vide crédible", async () => {
    state.durations = "error";
    renderWithProviders(<TypicalWeekPage />);
    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(screen.queryByText(/Aucun créneau idéal déclaré/)).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Semaine type", level: 2 })).not.toBeInTheDocument();
  });
});
