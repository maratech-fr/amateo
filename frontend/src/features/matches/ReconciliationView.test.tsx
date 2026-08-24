import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Deviation, ImportFbiResult } from "./api";
import { ReconciliationView } from "./ReconciliationView";
import { useMatchesStore } from "./store";

const { importFbiFixtures, getTeams, getPriorityTiers, applyFfbbRencontres, getFfbbRencontres } = vi.hoisted(() => ({
  importFbiFixtures: vi.fn(
    (): Promise<ImportFbiResult> =>
      Promise.resolve({
        message: "Import terminé.",
        created: 0,
        updated: 1,
        unchanged: 3,
        exempted: 0,
        errors: [],
        warnings: [],
        unmappedDivisions: [],
        completeness: [],
        unresolvedDeviations: [],
        depositedAt: "2026-08-24T10:00:00+00:00",
      }),
  ),
  getTeams: vi.fn(() => Promise.resolve([{ id: "team-1", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }])),
  getPriorityTiers: vi.fn(() => Promise.resolve([])),
  applyFfbbRencontres: vi.fn(),
  getFfbbRencontres: vi.fn(),
}));

vi.mock("./api", () => ({ importFbiFixtures, getTeams, getPriorityTiers, applyFfbbRencontres, getFfbbRencontres }));

const deviation: Deviation = {
  fixtureId: "fx-1",
  externalRef: "101137",
  division: "DF2",
  teamId: "team-1",
  status: "PLACED",
  persisting: false,
  fields: { date: { app: "2026-11-28", file: "2026-12-05" } },
};

const file = new File(["xlsx"], "fbi.xlsx");
const payload = { channel: "xlsx" as const, file, mappings: [{ division: "DF2", fbiTeamLabel: null, teamId: "team-1", competitionId: null }], deviations: [deviation] };

beforeEach(() => {
  importFbiFixtures.mockClear();
  getTeams.mockClear();
  useMatchesStore.setState({ reconciliation: null });
});
afterEach(() => useMatchesStore.setState({ reconciliation: null }));

describe("ReconciliationView (RMM-4 — vue dédiée)", () => {
  it("accès direct SANS données → renvoi propre vers la boucle, aucune carte", () => {
    renderWithProviders(<ReconciliationView />, { route: "/matchs/reconciliation" });
    expect(screen.getByText(/Re-déposez le fichier/i)).toBeInTheDocument();
    expect(screen.queryByRole("article")).not.toBeInTheDocument();
    // FALSIFICATION — rien n'est jamais écrit sur un accès sans données.
    expect(importFbiFixtures).not.toHaveBeenCalled();
  });

  it("avec le payload porté en mémoire : la vue montre les écarts + « Appliquer l'import »", async () => {
    useMatchesStore.setState({ reconciliation: payload });
    renderWithProviders(<ReconciliationView />, { route: "/matchs/reconciliation" });
    expect(await screen.findByRole("article")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Appliquer l'import/i })).toBeInTheDocument();
    // Le geste est réversible sans rien écraser — c'est dit à l'écran.
    expect(screen.getByText(/re-déposer le fichier les re-présente/i)).toBeInTheDocument();
  });

  it("Appliquer envoie fichier + mappings + les décisions EXACTEMENT posées", async () => {
    useMatchesStore.setState({ reconciliation: payload });
    const user = userEvent.setup();
    renderWithProviders(<ReconciliationView />, { route: "/matchs/reconciliation" });
    await screen.findByRole("article");

    await user.click(screen.getByRole("button", { name: /Prendre le fichier.*Date/i }));
    await user.click(screen.getByRole("button", { name: /Appliquer l'import/i }));
    // Résumé de confirmation AVANT d'écrire.
    await user.click(await screen.findByRole("button", { name: /^Appliquer$/i }));

    await waitFor(() => expect(importFbiFixtures).toHaveBeenCalledOnce());
    expect(importFbiFixtures).toHaveBeenCalledWith(file, payload.mappings, [{ fixtureId: "fx-1", field: "date", choice: "take_file" }]);
  });

  it("Quitter (Abandonner) n'écrit RIEN", async () => {
    useMatchesStore.setState({ reconciliation: payload });
    const user = userEvent.setup();
    renderWithProviders(<ReconciliationView />, { route: "/matchs/reconciliation" });
    await screen.findByRole("article");

    await user.click(screen.getByRole("button", { name: /Abandonner/i }));
    expect(importFbiFixtures).not.toHaveBeenCalled();
    expect(useMatchesStore.getState().reconciliation).toBeNull();
  });

  it("le rapport final montre les écarts NON tranchés avec une phrase rassurante", async () => {
    importFbiFixtures.mockResolvedValueOnce({
      message: "Import terminé.",
      created: 0,
      updated: 0,
      unchanged: 4,
      exempted: 0,
      errors: [],
      warnings: [],
      unmappedDivisions: [],
      completeness: [],
      unresolvedDeviations: [deviation],
      depositedAt: "2026-08-24T10:00:00+00:00",
    });
    useMatchesStore.setState({ reconciliation: payload });
    const user = userEvent.setup();
    renderWithProviders(<ReconciliationView />, { route: "/matchs/reconciliation" });
    await screen.findByRole("article");

    await user.click(screen.getByRole("button", { name: /Appliquer l'import/i }));
    await user.click(await screen.findByRole("button", { name: /^Appliquer$/i }));

    await waitFor(() => expect(screen.getByText(/1 écart.*non tranché/i)).toBeInTheDocument());
    expect(screen.getByText(/rien n'a été écrasé/i)).toBeInTheDocument();
  });
});

describe("ReconciliationView — canal API FFBB (RMM-4 PR-3)", () => {
  const creatableA = {
    rencontreId: "renc-a",
    competitionNom: "AMICAL",
    date: "2026-09-23",
    kickoff: "20:00",
    homeAway: "HOME" as const,
    opponentLabel: "BRON BASKET",
    venueLabel: "GYMNASE STUB",
    numeroJournee: null,
    suggestedTeamId: null,
  };
  const creatableB = { ...creatableA, rencontreId: "renc-b", opponentLabel: "VAULX BASKET" };
  const apiPayload = { channel: "api" as const, deviations: [], creatable: [creatableA, creatableB], fetchedAt: "2026-08-24T14:05:00+00:00" };

  it("affiche le bandeau d'honnêteté (info, pas alarme) et la provenance API", () => {
    useMatchesStore.setState({ reconciliation: apiPayload });
    renderWithProviders(<ReconciliationView />, { route: "/matchs/reconciliation" });

    const banner = screen.getByText(/Ce que la FFBB publie à cet instant/i);
    expect(banner).toBeInTheDocument();
    expect(banner.closest("[role='status']")).not.toBeNull(); // status, jamais alert
    expect(screen.getByText(/la couverture fédérale n'est pas garantie/i)).toBeInTheDocument();
    expect(screen.getByText(/Source : API FFBB/i)).toBeInTheDocument();
  });

  it("un creatable NON sélectionné n'est PAS envoyé (falsification)", async () => {
    applyFfbbRencontres.mockResolvedValueOnce({ created: 1, updated: 0, unresolvedDeviations: [], depositedAt: "2026-08-24T14:06:00+00:00" });
    useMatchesStore.setState({ reconciliation: apiPayload });
    const user = userEvent.setup();
    renderWithProviders(<ReconciliationView />, { route: "/matchs/reconciliation" });

    // Je choisis une équipe pour A seulement ; B reste « Ne pas créer ».
    await screen.findAllByRole("option", { name: "SM1" }); // les équipes se chargent async (une option par ligne)
    await user.selectOptions(await screen.findByLabelText(/Créer le match vs BRON BASKET/i), "team-1");
    await user.click(screen.getByRole("button", { name: /^Appliquer$/i }));
    // Résumé de confirmation AVANT d'écrire — le bouton du dialogue.
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /^Appliquer$/i }));

    await waitFor(() => expect(applyFfbbRencontres).toHaveBeenCalledOnce());
    expect(applyFfbbRencontres).toHaveBeenCalledWith([], [{ rencontreId: "renc-a", teamId: "team-1" }]);
    // FALSIFICATION — renc-b (laissé « Ne pas créer ») n'est jamais envoyé.
    const [, creations] = applyFfbbRencontres.mock.calls[0];
    expect(creations).not.toContainEqual(expect.objectContaining({ rencontreId: "renc-b" }));
  });

  it("rien à appliquer (aucun choix) → « Appliquer » désactivé", () => {
    useMatchesStore.setState({ reconciliation: apiPayload });
    renderWithProviders(<ReconciliationView />, { route: "/matchs/reconciliation" });
    expect(screen.getByRole("button", { name: /^Appliquer$/i })).toBeDisabled();
  });
});
