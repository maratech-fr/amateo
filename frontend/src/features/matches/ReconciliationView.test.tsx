import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Deviation, ImportFbiResult } from "./api";
import { ReconciliationView } from "./ReconciliationView";
import { useMatchesStore } from "./store";

const { importFbiFixtures, getTeams } = vi.hoisted(() => ({
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
}));

vi.mock("./api", () => ({ importFbiFixtures, getTeams }));

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
const payload = { file, mappings: [{ division: "DF2", fbiTeamLabel: null, teamId: "team-1", competitionId: null }], deviations: [deviation] };

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
