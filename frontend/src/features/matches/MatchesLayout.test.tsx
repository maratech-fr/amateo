import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router";
import { describe, expect, it, vi } from "vitest";

import { MatchesLayout } from "./MatchesLayout";

// Le socle est validé quand le plan de saison pointe une version. Mutable par test
// pour éprouver le garde sur LES DEUX espaces (RMM-1 PR2 — le garde vit une fois
// dans le layout, pas dupliqué dans chaque page).
const meState = vi.hoisted(() => ({ chosen: null as string | null }));
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { chosenScheduleId: meState.chosen } } }),
}));

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
