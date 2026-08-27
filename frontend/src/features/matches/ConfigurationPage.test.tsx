import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { ConfigurationPage } from "./ConfigurationPage";
import { useMatchesStore } from "./store";

// Le SET-UP (rare) : les 3 actions rares + l'image A/B en plein droit + une seconde
// entrée d'import saisonnier. Aucun garde socle ici (il vit dans le layout).
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
  getLatestFbiIngestion: vi.fn(() => Promise.resolve({ latest: null })),
  getFfbbRencontres: vi.fn(),
  getOpponentTravel: vi.fn(() => Promise.resolve([])),
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

  // ── RMM-6 PR-2 — l'éditeur des échéances de saisie ───────────────────────────
  it("porte l'éditeur « Échéances de saisie » (aucune compétition → son état vide)", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByRole("heading", { name: "Échéances de saisie", level: 3 })).toBeInTheDocument();
    expect(screen.getByText(/Aucune compétition/i)).toBeInTheDocument();
  });

  // ── P2-54 RMM-9 — l'éditeur « Durée des matchs » ─────────────────────────────
  it("porte l'éditeur « Durée des matchs » (aucune catégorie → son état vide)", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByRole("heading", { name: "Durée des matchs", level: 3 })).toBeInTheDocument();
  });

  // ── RMM-4 — la carte de fraîcheur du dépôt FBI ───────────────────────────────
  it("aucun dépôt cette saison → la carte de fraîcheur le dit (latest null)", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByText(/Aucun dépôt.*cette saison/i)).toBeInTheDocument();
  });

  it("un dépôt existe → la carte affiche « Dernier dépôt FBI » en relatif", async () => {
    const { getLatestFbiIngestion } = await import("./api");
    (getLatestFbiIngestion as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      latest: { depositedAt: "2026-08-22T09:00:00+00:00", source: "FBI_XLSX", created: 5, updated: 1, unchanged: 2, deviationsCount: 0 },
    });
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByText(/Dernier dépôt FBI/i)).toBeInTheDocument();
  });

  // ── RMM-4 PR-3 — le bouton du canal API FFBB ─────────────────────────────────
  it("« Vérifier via l'API FFBB » fetch à la demande et porte un payload canal API vers la vue", async () => {
    const { getFfbbRencontres } = await import("./api");
    (getFfbbRencontres as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      deviations: [],
      creatable: [{ rencontreId: "renc-a", competitionNom: "AMICAL", date: "2026-09-23", kickoff: "20:00", homeAway: "HOME", opponentLabel: "BRON", venueLabel: null, numeroJournee: null, suggestedTeamId: null }],
      fetchedAt: "2026-08-24T14:05:00+00:00",
    });
    useMatchesStore.setState({ reconciliation: null });
    const user = userEvent.setup();
    renderWithProviders(<ConfigurationPage />);

    await user.click(await screen.findByRole("button", { name: /Vérifier via l'API FFBB/i }));

    await waitFor(() => expect(getFfbbRencontres).toHaveBeenCalledOnce());
    const carried = useMatchesStore.getState().reconciliation;
    expect(carried?.channel).toBe("api");
    if (null === carried || "api" !== carried.channel) {
      throw new Error("payload canal API attendu");
    }
    expect(carried.creatable).toHaveLength(1);
  });
});
