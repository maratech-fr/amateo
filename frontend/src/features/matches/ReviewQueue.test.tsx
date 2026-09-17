import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Fixture, Team, Venue } from "./api";
import { ReviewQueue } from "./ReviewQueue";
import { useMatchesStore } from "./store";

// La file n'exerce ici que le geste « Replacer » (A8) : on isole les mutations.
vi.mock("./queries", () => ({
  useReviewFixtures: () => ({ mutate: vi.fn(), isPending: false }),
  useResolveFixtureDeviation: () => ({ mutate: vi.fn(), isPending: false }),
  useAttachVenueLabel: () => ({ mutate: vi.fn(), isPending: false }),
  useCompetitions: () => ({ data: [] }),
}));

const team = { id: "team-1", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 } as unknown as Team;
const venue = { id: "venue-1", name: "Alpha", color: null, externalLabels: [] } as unknown as Venue;
// Domicile AMICAL futur (competitionId null ⇒ amical, hors défauts) : « Replacer » disponible.
const homeAmical = {
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2030-01-05",
  homeAway: "HOME",
  opponentLabel: "Adv",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: "MANUAL",
  unplacedReason: null,
  reviewState: "NEW",
  reviewedAt: null,
  pendingDeviations: [],
  ffbbRencontreId: null,
  opponentOrganismeCode: null,
  opponentTeamKey: null,
  suggestedVenueId: null,
} as unknown as Fixture;

function renderQueue() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [
      { path: "/matchs/importer", element: <ReviewQueue fixtures={[homeAmical]} teams={[team]} venues={[venue]} /> },
      { path: "/matchs", element: <div>PLACER</div> },
    ],
    { initialEntries: ["/matchs/importer"] },
  );
  return render(
    <QueryClientProvider client={qc}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  useMatchesStore.setState({ consultKinds: null, consultAway: false, filterMode: "equipe", filterIds: [], selectedFixtureId: null });
});

describe("ReviewQueue.onPlace (A8)", () => {
  it("« Replacer » un domicile amical lève le masque de type (amical) et pointe la rencontre", async () => {
    const user = userEvent.setup();
    renderQueue();
    // Ouvrir l'accordéon de l'équipe puis « Replacer ».
    await user.click(await screen.findByRole("button", { name: /SM1/ }));
    await user.click(await screen.findByRole("button", { name: "Replacer" }));
    expect(useMatchesStore.getState().consultKinds).toContain("amical");
    expect(useMatchesStore.getState().selectedFixtureId).toBe("fx-1");
    expect(await screen.findByText("PLACER")).toBeInTheDocument();
  });
});
