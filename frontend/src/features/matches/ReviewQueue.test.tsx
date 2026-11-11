import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider, useLocation } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Fixture, Team, Venue } from "./api";
import { ReviewQueue } from "./ReviewQueue";
import { useMatchesStore } from "./store";
import { MATCHES_TEXTS } from "./texts";

// La file n'exerce ici que le geste « Replacer » (A8) : on isole les mutations.
vi.mock("./queries", () => ({
  useReviewFixtures: () => ({ mutate: vi.fn(), isPending: false }),
  useResolveFixtureDeviation: () => ({ mutate: vi.fn(), isPending: false }),
  useAttachVenueLabel: () => ({ mutate: vi.fn(), isPending: false }),
  useCompetitions: () => ({ data: [] }),
}));
// Chantier Membre (PR 2) : `useCanManage` piloté par cas (gestionnaire par défaut) ; le reste de la session reste réel.
const session = vi.hoisted(() => ({ canManage: true }));
vi.mock("@/shared/session/queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/shared/session/queries")>()),
  useCanManage: () => session.canManage,
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
 * M3 (2026-10-27) — la file d'import ne parle plus d'une « validation » (« ça fait quoi, ça mène
 * où ? »), c'est un ACQUITTEMENT : une phrase d'aide en tête, un en-tête d'équipe « N nouveautés à
 * voir · M écarts à trancher », un bouton de corps « Tout marquer comme vu ». Lot prod Matchs PR 2 :
 * l'aide et le bouton vivent dans `texts.ts` (clés `reviewHelp`, `reviewMarkAllSeen`) — le test
 * vérifie la CLÉ ; l'en-tête garde ses mots pinés (« à valider » a disparu).
 */
describe("ReviewQueue — file d'import = acquittement, pas validation (M3)", () => {
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

  it("les textes de la file vivent dans texts.ts (aide, gestes de masse, toasts)", () => {
    expect(typeof MATCHES_TEXTS.reviewHelp).toBe("string");
    expect(typeof MATCHES_TEXTS.reviewMarkAllSeen).toBe("string");
    expect(typeof MATCHES_TEXTS.reviewMarkAwaySeen).toBe("string");
    expect(typeof MATCHES_TEXTS.reviewMarkAwaySeenDisabled).toBe("string");
    expect(typeof MATCHES_TEXTS.reviewAwaySeenToast).toBe("function");
    expect(typeof MATCHES_TEXTS.reviewSkippedToast).toBe("function");
  });

  it("une phrase d'aide explique que l'import a déjà mis à jour les matchs, à marquer comme vus", () => {
    renderWith([newFixture, outOfSync]);
    expect(screen.getByText(MATCHES_TEXTS.reviewHelp)).toBeInTheDocument();
  });

  it("le bouton de corps est « Tout marquer comme vu » (clé), plus « Tout valider »", async () => {
    const user = userEvent.setup();
    renderWith([newFixture, outOfSync]);
    await user.click(screen.getByRole("button", { name: /SM1/ }));
    expect(screen.getByRole("button", { name: MATCHES_TEXTS.reviewMarkAllSeen })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Tout valider" })).not.toBeInTheDocument();
  });

  /**
   * Chantier Membre (PR 2) : la file d'import se LIT par un Membre (nouveautés, écarts) mais il
   * n'acquitte rien — aucun « Tout marquer comme vu ». Témoin : le cas précédent, même décor.
   */
  it("Membre : lit la file, sans « Tout marquer comme vu »", async () => {
    session.canManage = false;
    try {
      const user = userEvent.setup();
      renderWith([newFixture, outOfSync]);
      await user.click(screen.getByRole("button", { name: /SM1/ }));
      expect(screen.queryByRole("button", { name: MATCHES_TEXTS.reviewMarkAllSeen })).not.toBeInTheDocument();
    } finally {
      session.canManage = true;
    }
  });
});

/**
 * Lot prod Matchs PR 2 — les toasts des gestes de masse s'accordent sur CHAQUE nombre et nomment
 * chaque rencontre laissée de côté (équipe + date) : le singulier ne dit jamais « rencontres », le
 * pluriel le dit, et « aucune vue, aucune laissée » se lit comme la raison du bouton désactivé.
 */
describe("ReviewQueue — accords des toasts de masse (lot prod Matchs PR 2)", () => {
  it("extérieurs : 1 au singulier, N au pluriel, 0 = la raison du bouton désactivé", () => {
    expect(MATCHES_TEXTS.reviewAwaySeenToast(1, [])).not.toMatch(/rencontres/);
    expect(MATCHES_TEXTS.reviewAwaySeenToast(9, [])).toMatch(/\b9 rencontres\b/);
    expect(MATCHES_TEXTS.reviewAwaySeenToast(0, [])).toBe(MATCHES_TEXTS.reviewMarkAwaySeenDisabled);
  });

  it("extérieurs : chaque laissée de côté est nommée, équipe et date", () => {
    const one = MATCHES_TEXTS.reviewAwaySeenToast(9, [{ team: "U15F2", date: "sam. 5 déc." }]);
    expect(one).toContain("U15F2");
    expect(one).toContain("sam. 5 déc.");
    const two = MATCHES_TEXTS.reviewAwaySeenToast(1, [
      { team: "U15F2", date: "sam. 5 déc." },
      { team: "SM1", date: "dim. 13 déc." },
    ]);
    for (const part of ["U15F2", "sam. 5 déc.", "SM1", "dim. 13 déc."]) {
      expect(two).toContain(part);
    }
    expect(two).not.toBe(MATCHES_TEXTS.reviewAwaySeenToast(1, [{ team: "U15F2", date: "sam. 5 déc." }]));
  });

  it("équipe : nomme l'équipe et chaque date, accordé sur le nombre", () => {
    const single = MATCHES_TEXTS.reviewSkippedToast("SM1", 1, ["sam. 5 déc."]);
    expect(single).toContain("SM1");
    expect(single).toContain("sam. 5 déc.");
    expect(single).not.toMatch(/rencontres/);
    const plural = MATCHES_TEXTS.reviewSkippedToast("SM1", 2, ["sam. 5 déc.", "sam. 12 déc."]);
    expect(plural).toMatch(/\b2 rencontres\b/);
    expect(plural).toContain("sam. 12 déc.");
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
