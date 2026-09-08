import { screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { ConfigurationPage } from "./ConfigurationPage";

// Le SET-UP (rare) : les 2 réglages rares + l'image A/B + éditeurs. Depuis PR-3b
// (P4-186), tout ce qui touche les DONNÉES FBI/FFBB a déménagé dans l'onglet
// Importer — la Configuration ne porte plus que des réglages.
vi.mock("./api", () => ({
  getTeams: vi.fn(() => Promise.resolve([{ id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 }])),
  getPriorityTiers: vi.fn(() => Promise.resolve([{ id: 3, label: "B", name: "Moyenne", color: null }])),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }])),
  getCompetitions: vi.fn(() => Promise.resolve([])),
  getTeamMatchHabits: vi.fn(() => Promise.resolve([])),
  getMatchSlotRotations: vi.fn(() => Promise.resolve([])),
  getSportCategoryDurations: vi.fn(() => Promise.resolve([])),
  updateSportCategoryDuration: vi.fn(),
  createMatchSlotRotation: vi.fn(),
  updateMatchSlotRotation: vi.fn(),
  deleteMatchSlotRotation: vi.fn(),
  getFixtures: vi.fn(() => Promise.resolve([])),
  getOpponentTravel: vi.fn(() => Promise.resolve([])),
}));

describe("ConfigurationPage (RMM-1 PR2 — le SET-UP, allégée P4-186)", () => {
  it("porte les 2 réglages rares (Accès match · Habitudes) — plus « Engagements FFBB »", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByRole("button", { name: "Accès match" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Habitudes & passerelles" })).toBeInTheDocument();
    // Les engagements FFBB ont migré dans Importer.
    expect(screen.queryByRole("button", { name: "Engagements FFBB" })).not.toBeInTheDocument();
  });

  it("affiche l'image A/B en écran de plein droit — plus jamais derrière un toggle", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByText(/Aucune habitude déclarée/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Week-end type/ })).not.toBeInTheDocument();
  });

  it("porte l'éditeur « Échéances de saisie » (aucune compétition → son état vide)", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByRole("heading", { name: "Échéances de saisie", level: 3 })).toBeInTheDocument();
    expect(screen.getByText(/Aucune compétition/i)).toBeInTheDocument();
  });

  it("porte l'éditeur « Durée des matchs » (aucune catégorie → son état vide)", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByRole("heading", { name: "Durée des matchs", level: 3 })).toBeInTheDocument();
  });

  // ── P4-186 — les DONNÉES FBI/FFBB ont quitté la Configuration ─────────────────
  it("ne porte PLUS la carte « Dépôt saisonnier FBI » ni ses actions", async () => {
    renderWithProviders(<ConfigurationPage />);
    // On attend que la page soit rendue (une carte connue).
    await screen.findByRole("button", { name: "Accès match" });
    expect(screen.queryByText(/Dépôt saisonnier FBI/i)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Importer FBI/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Vérifier via l'API FFBB/i })).not.toBeInTheDocument();
    expect(screen.queryByText(/dépôt FBI/i)).not.toBeInTheDocument();
  });
});
