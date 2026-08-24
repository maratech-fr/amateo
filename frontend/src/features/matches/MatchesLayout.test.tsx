import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MatchesLayout } from "./MatchesLayout";

// Le socle est validé quand le plan de saison pointe une version. Mutable par test
// pour éprouver le garde sur LES DEUX espaces (RMM-1 PR2 — le garde vit une fois
// dans le layout, pas dupliqué dans chaque page).
const meState = vi.hoisted(() => ({ chosen: null as string | null }));
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { chosenScheduleId: meState.chosen } } }),
}));

// RMM-3 — compteur de POST de visite : le « gardien » ne doit stamper QU'UNE fois
// par montage du layout, jamais sur un module verrouillé, jamais au re-render ni à
// la navigation boucle⇄configuration.
const visit = vi.hoisted(() => ({ count: 0 }));
vi.mock("./api", () => ({
  postModuleVisit: vi.fn(() => {
    visit.count += 1;
    return Promise.resolve({ firstVisit: true, newFixturesCount: 0, newConflictFingerprints: [], planningChanged: false, referenceTakenAt: "2026-08-24T10:00:00+00:00" });
  }),
}));

beforeEach(() => {
  visit.count = 0;
});

function renderAt(path: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/matchs" element={<MatchesLayout />}>
            <Route index element={<div>BOUCLE</div>} />
            <Route path="configuration" element={<div>CONFIG</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("MatchesLayout (RMM-1 PR2 — deux espaces)", () => {
  it("porte la navigation entre les deux espaces et rend l'espace courant", () => {
    meState.chosen = "s1";
    renderAt("/matchs");
    expect(screen.getByText("BOUCLE")).toBeInTheDocument();
    expect(screen.getByRole("navigation", { name: "Espaces matchs" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Configuration" })).toBeInTheDocument();
  });

  it("le garde socle verrouille la BOUCLE sans version pointée", () => {
    meState.chosen = null;
    renderAt("/matchs");
    expect(screen.getByRole("heading", { name: "Matchs verrouillés" })).toBeInTheDocument();
    expect(screen.queryByText("BOUCLE")).not.toBeInTheDocument();
  });

  it("le garde socle verrouille AUSSI la CONFIGURATION (les deux espaces)", () => {
    meState.chosen = null;
    renderAt("/matchs/configuration");
    expect(screen.getByRole("heading", { name: "Matchs verrouillés" })).toBeInTheDocument();
    expect(screen.queryByText("CONFIG")).not.toBeInTheDocument();
  });

  it("laisse passer la CONFIGURATION quand le socle est validé", () => {
    meState.chosen = "s1";
    renderAt("/matchs/configuration");
    expect(screen.getByText("CONFIG")).toBeInTheDocument();
  });
});

describe("MatchesLayout — le « gardien » (RMM-3, POST de visite)", () => {
  it("stampe la visite UNE fois au montage, quand le socle est validé", async () => {
    meState.chosen = "s1";
    renderAt("/matchs");
    await waitFor(() => expect(visit.count).toBe(1));
  });

  it("ne stampe RIEN sur un module verrouillé (pas de socle pointé)", async () => {
    meState.chosen = null;
    renderAt("/matchs");
    // On laisse le temps à un éventuel POST de partir : il ne doit jamais partir.
    await new Promise((r) => setTimeout(r, 50));
    expect(visit.count).toBe(0);
  });

  it("ne re-POST pas à la navigation boucle⇄configuration (même montage de layout)", async () => {
    meState.chosen = "s1";
    const user = userEvent.setup();
    renderAt("/matchs");
    await waitFor(() => expect(visit.count).toBe(1));

    // Naviguer vers la Configuration garde le LAYOUT monté (seul l'Outlet change) —
    // le cache staleTime Infinity n'est pas refetché.
    await user.click(screen.getByRole("link", { name: "Configuration" }));
    expect(screen.getByText("CONFIG")).toBeInTheDocument();
    await user.click(screen.getByRole("link", { name: "Semaine" }));
    expect(screen.getByText("BOUCLE")).toBeInTheDocument();

    await new Promise((r) => setTimeout(r, 50));
    expect(visit.count).toBe(1);
  });
});
