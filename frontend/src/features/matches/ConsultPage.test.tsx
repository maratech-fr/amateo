import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";

import { ConsultPage } from "./ConsultPage";
import { useMatchesStore } from "./store";

// PR-2a — jointures coach⇄équipe (mêmes requêtes que /planning, comme MatchesPage).
vi.mock("@/features/planning/queries", () => ({
  useTeamCoaches: () => ({ data: [] }),
  useCoachPlayers: () => ({ data: [] }),
}));

vi.mock("./api", () => ({
  getFixtures: vi.fn(() =>
    Promise.resolve([
      // Domicile placé, AMICAL (competitionId null) — apparaît sur la grille.
      { id: "fx-home-amical", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Voisins", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null },
      // Domicile placé, COUPE — pour éprouver les chips de type.
      { id: "fx-home-coupe", teamId: "team-2", seasonId: "s", competitionId: "comp-coupe", matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Rivaux", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00", externalRef: null, fbiVenueLabel: null, placementSource: "SOLVER", unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null },
      // Extérieur du même week-end (bande AwayList, en lecture seule).
      { id: "fx-away", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "Grenoble", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: null, fbiVenueLabel: "Halle Clemenceau", placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null },
      // Domicile placé la semaine SUIVANTE (2026-10-10) — pour éprouver le scope hebdo des compteurs.
      { id: "fx-home-w2", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-10", homeAway: "HOME", opponentLabel: "Lointains", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null },
    ]),
  ),
  getCompetitions: vi.fn(() => Promise.resolve([{ id: "comp-coupe", teamId: "team-2", name: "Coupe AURA", competitionType: "CUP", ffbbCompetitionId: "ffbb-1", expectedMatchdays: 8 }])),
  getTeams: vi.fn(() =>
    Promise.resolve([
      { id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
      { id: "team-2", name: "Seniors", sportCategoryId: "cat-2", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
      { id: "team-3", name: "Cadets", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 1 },
    ]),
  ),
  getPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Fanion", color: null }, { id: 3, label: "B", name: "Moyenne", color: null }])),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }])),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Jean", lastName: "Dupont" }])),
  getTeamMatchHabits: vi.fn(() =>
    // Habitude samedi de team-3 (sans match ce week-end) → un ghost « Habitude Cadets ».
    Promise.resolve([{ id: "h-1", teamId: "team-3", dayOfWeek: 6, kickoffTime: "14:00", venueId: "venue-1" }]),
  ),
  getConflicts: vi.fn(() =>
    Promise.resolve({
      clubId: "c",
      seasonId: "s",
      seasonPlanChosen: true,
      conflicts: [
        // VENUE_OVERLAP (semaine 2026-10-03) : famille « Collision de gymnase ».
        {
          type: "VENUE_OVERLAP",
          severity: 1,
          left: { fixtureId: "fx-home-amical", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
          right: { fixtureId: "fx-home-coupe", teamId: "team-2", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "18:00", windowStart: "", windowEnd: "" },
        },
        // VENUE_UNAVAILABLE daté la semaine SUIVANTE (2026-10-10) : famille « Gymnase indisponible ».
        {
          type: "VENUE_UNAVAILABLE",
          severity: 1,
          fixture: { fixtureId: "fx-home-w2", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-10", kickoffTime: "16:00", status: "PLACED" },
        },
      ],
    }),
  ),
  getOpponentTravel: vi.fn(() => Promise.resolve([])),
}));

function renderConsult() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [
      { path: "/matchs", element: <div>PLACER</div> },
      { path: "/matchs/consulter", element: <ConsultPage /> },
    ],
    { initialEntries: ["/matchs/consulter"] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  setTodayOverride("2026-10-01");
  useMatchesStore.setState({
    selectedWeekend: null,
    filterMode: "equipe",
    filterIds: [],
    consultKinds: null,
    consultFamilies: null,
    consultTypicalWeek: true,
    consultTemporality: "semaine",
    consultMonth: null,
    consultPhaseId: null,
  });
});

describe("ConsultPage (PR-2a — onglet Consulter, lecture seule)", () => {
  it("est en LECTURE SEULE : aucun bouton de placement ni d'import", async () => {
    renderConsult();
    expect(await screen.findByRole("button", { name: /Amical/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Placer automatiquement/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Importer FBI/ })).not.toBeInTheDocument();
    // AwayList en lecture seule : pas de crayon/corbeille.
    expect(screen.queryByRole("button", { name: /Modifier le match/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Supprimer le match/ })).not.toBeInTheDocument();
  });

  it("affiche les chips type de compétition et les chips familles avec compteur", async () => {
    renderConsult();
    // Chips type de compétition (multi, défaut tout coché).
    expect(await screen.findByRole("button", { name: /Amical/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Championnat/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Coupe/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Brassage/ })).toBeInTheDocument();
    // Chip famille avec compteur (VENUE_OVERLAP → « Collision de gymnase », 1).
    expect(screen.getByRole("button", { name: /Collision de gymnase/ })).toBeInTheDocument();
  });

  it("le compteur d'une famille suit la SEMAINE affichée (scope hebdo)", async () => {
    const user = userEvent.setup();
    renderConsult();
    // Semaine 2026-10-03 : la collision de gymnase, pas l'indispo (semaine suivante).
    expect(await screen.findByRole("button", { name: /Collision de gymnase/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Gymnase indisponible/ })).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Semaine suivante" }));
    // Semaine 2026-10-10 : l'indispo apparaît, la collision disparaît.
    expect(await screen.findByRole("button", { name: /Gymnase indisponible/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Collision de gymnase/ })).not.toBeInTheDocument();
  });

  it("la semaine type (défaut ON) montre les ghosts d'habitude ; l'éteindre les retire", async () => {
    const user = userEvent.setup();
    renderConsult();
    expect(await screen.findByText("Habitude Cadets")).toBeInTheDocument();

    await user.click(screen.getByRole("switch", { name: /Semaine type/ }));
    expect(screen.queryByText("Habitude Cadets")).not.toBeInTheDocument();
  });

  it("cliquer un match placé pose la semaine et renvoie vers Placer (/matchs)", async () => {
    const user = userEvent.setup();
    const { container } = renderConsult();
    await screen.findByRole("button", { name: /Amical/ });
    const cell = await waitFor(() => {
      const el = container.querySelector('[data-fixture-id="fx-home-amical"]');
      expect(el).not.toBeNull();
      return el as HTMLElement;
    });
    await user.click(cell);

    expect(screen.getByText("PLACER")).toBeInTheDocument();
    expect(useMatchesStore.getState().selectedWeekend).toBe("2026-10-03");
  });
});

describe("ConsultPage (PR-2b — temporalités Mois et Phase)", () => {
  it("bascule Mois : table groupée par jour, compteurs sur le MOIS (les deux familles)", async () => {
    const user = userEvent.setup();
    renderConsult();
    await user.click(await screen.findByRole("button", { name: "Mois" }));

    // Table présente + jour d'octobre en en-tête de groupe.
    expect(await screen.findByRole("table")).toBeInTheDocument();
    // Les rencontres du mois apparaissent (grille par jour) — l'amical et la coupe.
    expect(screen.getByText("Voisins")).toBeInTheDocument();
    expect(screen.getByText("Rivaux")).toBeInTheDocument();
    // Compteurs de familles sur le MOIS : la collision (10-03) ET l'indispo (10-10).
    expect(screen.getByRole("button", { name: /Collision de gymnase/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Gymnase indisponible/ })).toBeInTheDocument();
    // La semaine type n'est pas rendue hors Semaine.
    expect(screen.queryByRole("switch", { name: /Semaine type/ })).not.toBeInTheDocument();
  });

  it("bascule Phase : sélecteur natif + complétude + journées de la compétition appariée", async () => {
    const user = userEvent.setup();
    renderConsult();
    await user.click(await screen.findByRole("button", { name: "Phase" }));

    // Sélecteur natif des compétitions appariées (Coupe AURA — Seniors).
    const select = await screen.findByRole("combobox", { name: /Phase|compétition/i });
    expect(select).toBeInTheDocument();
    expect(screen.getByRole("option", { name: /Coupe AURA — Seniors/ })).toBeInTheDocument();
    // Complétude : 1 rencontre importée / 8 journées attendues.
    expect(screen.getByText(/1\s*\/\s*8\s+journées importées/)).toBeInTheDocument();
    // La rencontre de la phase (Rivaux, comp-coupe) dans la table.
    expect(screen.getByText("Rivaux")).toBeInTheDocument();
  });

  it("cliquer une ligne de la table (Mois) renvoie vers Placer sur son week-end", async () => {
    const user = userEvent.setup();
    renderConsult();
    await user.click(await screen.findByRole("button", { name: "Mois" }));
    await screen.findByRole("table");
    // Le bouton de ligne de l'amical (fx-home-amical, 2026-10-03).
    const rowButton = screen.getByRole("button", { name: /Voisins/ });
    await user.click(rowButton);
    expect(screen.getByText("PLACER")).toBeInTheDocument();
    expect(useMatchesStore.getState().selectedWeekend).toBe("2026-10-03");
  });
});
