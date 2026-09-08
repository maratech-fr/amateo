import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { pickListboxOption } from "@/test/pickListboxOption";

import type { ReconciliationPayload } from "./store";
import { ReconciliationView } from "./ReconciliationView";
import { useMatchesStore } from "./store";

const { getTeams, getPriorityTiers, applyFfbbRencontres } = vi.hoisted(() => ({
  getTeams: vi.fn(() => Promise.resolve([{ id: "team-1", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }])),
  getPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Fanion", color: null }])),
  applyFfbbRencontres: vi.fn(),
}));

vi.mock("./api", () => ({ getTeams, getPriorityTiers, applyFfbbRencontres }));

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
const apiPayload: ReconciliationPayload = { channel: "api", creatable: [creatableA, creatableB], fetchedAt: "2026-08-24T14:05:00+00:00" };

function renderView(payload: ReconciliationPayload | null) {
  useMatchesStore.setState({ reconciliation: payload });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [
      { path: "/matchs/reconciliation", element: <ReconciliationView /> },
      { path: "/matchs/importer", element: <div>FILE IMPORTER</div> },
      { path: "/matchs", element: <div>BOUCLE</div> },
    ],
    { initialEntries: ["/matchs/reconciliation"] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  getTeams.mockClear();
  applyFfbbRencontres.mockClear();
  useMatchesStore.setState({ reconciliation: null });
});
afterEach(() => useMatchesStore.setState({ reconciliation: null }));

describe("ReconciliationView (PR-3b — intégration des rencontres FFBB)", () => {
  it("accès direct SANS payload → renvoi propre, aucune écriture", () => {
    renderView(null);
    expect(screen.getByText(/Rien à examiner/i)).toBeInTheDocument();
    expect(applyFfbbRencontres).not.toHaveBeenCalled();
  });

  it("plus AUCUN panneau d'écarts — seuls le bandeau, la provenance et les rencontres à créer", () => {
    renderView(apiPayload);
    const banner = screen.getByText(/Ce que la FFBB publie à cet instant/i);
    expect(banner.closest("[role='status']")).not.toBeNull(); // status, jamais alert
    expect(screen.getByText(/Source : API FFBB/i)).toBeInTheDocument();
    // Le panneau d'arbitrage d'écarts (RMM-4) a disparu.
    expect(screen.queryByText(/Tranchez chaque écart/i)).not.toBeInTheDocument();
    expect(screen.queryByRole("article")).not.toBeInTheDocument();
  });

  it("« Intégrer » envoie les créations choisies puis renvoie vers la file (/matchs/importer)", async () => {
    applyFfbbRencontres.mockResolvedValueOnce({ created: 1, updated: 0, unresolvedDeviations: [], depositedAt: "2026-08-24T14:06:00+00:00" });
    const user = userEvent.setup();
    renderView(apiPayload);

    // Je choisis une équipe pour A seulement ; B reste « Ne pas créer ».
    await pickListboxOption(user, /Créer le match vs BRON BASKET/i, "SM1"); // team-1
    await user.click(screen.getByRole("button", { name: "Intégrer" }));

    await waitFor(() => expect(applyFfbbRencontres).toHaveBeenCalledOnce());
    expect(applyFfbbRencontres).toHaveBeenCalledWith([], [{ rencontreId: "renc-a", teamId: "team-1" }]);
    // FALSIFICATION — renc-b (laissé « Ne pas créer ») n'est jamais envoyé.
    const [, creations] = applyFfbbRencontres.mock.calls[0];
    expect(creations).not.toContainEqual(expect.objectContaining({ rencontreId: "renc-b" }));
    // Renvoi vers la file après intégration.
    expect(await screen.findByText("FILE IMPORTER")).toBeInTheDocument();
  });

  it("rien à intégrer (aucun choix) → « Intégrer » désactivé", () => {
    renderView(apiPayload);
    expect(screen.getByRole("button", { name: "Intégrer" })).toBeDisabled();
  });
});
