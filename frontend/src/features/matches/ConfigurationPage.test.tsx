import { screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { ConfigurationPage } from "./ConfigurationPage";

// Le SET-UP (rare) : les 3 actions rares + l'image A/B en plein droit + une seconde
// entrée d'import saisonnier. Aucun garde socle ici (il vit dans le layout).
vi.mock("./api", () => ({
  getTeams: vi.fn(() => Promise.resolve([{ id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 }])),
  getPriorityTiers: vi.fn(() => Promise.resolve([{ id: 3, label: "B", name: "Moyenne", color: null }])),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }])),
  getTeamMatchHabits: vi.fn(() => Promise.resolve([])),
  getFixtures: vi.fn(() => Promise.resolve([])),
}));

describe("ConfigurationPage (RMM-1 PR2 — le SET-UP)", () => {
  it("porte les 3 entrées rares (Engagements FFBB · Accès match · Habitudes)", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByRole("button", { name: "Engagements FFBB" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Accès match" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Habitudes & passerelles" })).toBeInTheDocument();
  });

  it("affiche l'image A/B en écran de plein droit — plus jamais derrière un toggle", async () => {
    renderWithProviders(<ConfigurationPage />);
    // Aucune habitude déclarée → le gabarit rend son état vide, DIRECTEMENT en page.
    expect(await screen.findByText(/Aucune habitude déclarée/)).toBeInTheDocument();
    // Le toggle « Week-end type » de l'ancien écran n'existe plus.
    expect(screen.queryByRole("button", { name: /Week-end type/ })).not.toBeInTheDocument();
  });

  it("offre la seconde entrée d'import FBI (dépôt saisonnier)", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByRole("button", { name: /Importer FBI/ })).toBeInTheDocument();
  });
});
