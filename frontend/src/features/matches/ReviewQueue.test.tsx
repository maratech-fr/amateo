import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider, useLocation } from "react-router";
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
  neutralGround: false,
  reviewState: "NEW",
  reviewedAt: null,
  pendingDeviations: [],
  ffbbRencontreId: null,
  opponentOrganismeCode: null,
  opponentTeamKey: null,
  suggestedVenueId: null,
} as unknown as Fixture;

function CalendarProbe() {
  const location = useLocation();
  return (
    <div>
      <span>PLACER</span>
      <span data-testid="calendar-search">{location.search}</span>
    </div>
  );
}

function renderQueue() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [
      { path: "/matchs/importer", element: <ReviewQueue fixtures={[homeAmical]} teams={[team]} venues={[venue]} /> },
      { path: "/matchs", element: <CalendarProbe /> },
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

/**
 * Retours Nico (BCCL, 2026-10-27), M3 — la file d'import ne parle plus d'une « validation » (le
 * fondateur : « ça fait quoi, ça mène où ? »), c'est un ACQUITTEMENT. Wording VALIDÉ :
 *  - phrase d'aide en tête : « L'import a déjà mis à jour vos matchs. Marquez-les comme vus pour
 *    vider la liste ; les écarts, eux, demandent votre arbitrage. » ;
 *  - en-tête d'équipe : « N nouveautés à voir · M écarts à trancher » (plus « N à valider ·
 *    M écarts ») ;
 *  - bouton de corps : « Tout marquer comme vu » (plus « Tout valider »).
 * La tournure de pluriel reste à NEO ; les promesses pinées ici : les mots « nouveauté(s) à voir »,
 * « écart(s) à trancher », « Tout marquer comme vu » et la phrase d'aide apparaissent, « à valider »
 * / « Tout valider » ont disparu.
 */
describe("ReviewQueue — file d'import = acquittement, pas validation (M3, BCCL 2026-10-27)", () => {
  function fixture(extra: Partial<Fixture>): Fixture {
    return {
      ...(homeAmical as Fixture),
      ...extra,
    } as Fixture;
  }

  function renderWith(fixtures: Fixture[]) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const router = createMemoryRouter(
      [{ path: "/matchs/importer", element: <ReviewQueue fixtures={fixtures} teams={[team]} venues={[venue]} /> }],
      { initialEntries: ["/matchs/importer"] },
    );
    return render(
      <QueryClientProvider client={qc}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );
  }

  const newFixture = fixture({ id: "fx-new", reviewState: "NEW", matchDate: "2030-01-05" });
  const outOfSync = fixture({
    id: "fx-ecart",
    reviewState: "OUT_OF_SYNC",
    matchDate: "2030-01-12",
    pendingDeviations: [{ field: "date", appValue: "2030-01-12", sourceValue: "2030-01-19", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: false }] as Fixture["pendingDeviations"],
  });

  it("l'en-tête d'équipe dit « nouveautés à voir » + « écarts à trancher », jamais « à valider »", () => {
    renderWith([newFixture, outOfSync]);
    const header = screen.getByRole("button", { name: /SM1/ });
    expect(header).toHaveTextContent(/nouveaut\p{L}* à voir/u);
    expect(header).toHaveTextContent(/écart\p{L}* à trancher/u);
    expect(header).not.toHaveTextContent("à valider");
  });

  it("une phrase d'aide explique que l'import a déjà mis à jour les matchs, à marquer comme vus", () => {
    renderWith([newFixture, outOfSync]);
    expect(screen.getByText(/L'import a déjà mis à jour vos matchs/)).toBeInTheDocument();
    expect(screen.getByText(/[Mm]arquez-les comme vus pour vider la liste/)).toBeInTheDocument();
  });

  it("le bouton de corps dit « Tout marquer comme vu » (plus « Tout valider »)", async () => {
    const user = userEvent.setup();
    renderWith([newFixture, outOfSync]);
    await user.click(screen.getByRole("button", { name: /SM1/ }));
    expect(screen.getByRole("button", { name: "Tout marquer comme vu" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Tout valider" })).not.toBeInTheDocument();
  });
});

describe("ReviewQueue.onPlace (A8)", () => {
  it("« Replacer » un domicile amical porte le masque de type (amical) dans l'URL de destination + pointe la rencontre", async () => {
    const user = userEvent.setup();
    renderQueue();
    // Ouvrir l'accordéon de l'équipe puis « Replacer ».
    await user.click(await screen.findByRole("button", { name: /SM1/ }));
    await user.click(await screen.findByRole("button", { name: "Replacer" }));
    // Le masque de type voyage DANS L'URL (le store serait écrasé par le seed de CalendarPage).
    await screen.findByText("PLACER");
    const search = (await screen.findByTestId("calendar-search")).textContent ?? "";
    expect(search).toMatch(/[?&]type=[^&]*amical/);
    // La rencontre pointée survit par le store (le seed n'y touche pas).
    expect(useMatchesStore.getState().selectedFixtureId).toBe("fx-1");
  });
});
