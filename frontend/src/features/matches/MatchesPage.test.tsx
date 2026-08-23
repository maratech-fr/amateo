import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { MatchesPage } from "./MatchesPage";
import { useMatchesStore } from "./store";

const { placeFixture, unplaceFixture } = vi.hoisted(() => ({
  placeFixture: vi.fn(() => Promise.resolve({})),
  unplaceFixture: vi.fn(() => Promise.resolve({})),
}));

// Matches are unlocked only once the season's socle is validated. `club`
// (avec entitlements) est mutable pour piloter le solde de crédits par test.
const meState = vi.hoisted(() => ({ club: undefined as Record<string, unknown> | undefined }));
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "s1", hasFinishedVersion: true }, club: meState.club } }),
}));

vi.mock("./api", () => ({
  getFixtures: vi.fn(() =>
    Promise.resolve([
      { id: "fx-unplaced", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Voisins", status: "UNPLACED", venueId: null, kickoffTime: null },
      { id: "fx-placed", teamId: "team-2", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Rivaux", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00" },
      // P1-4 PR E2 — an away match of the same weekend (visible in the away band).
      { id: "fx-away", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "Grenoble", status: "UNPLACED", venueId: null, kickoffTime: null, fbiVenueLabel: "Halle Clemenceau" },
    ]),
  ),
  getCompetitions: vi.fn(() => Promise.resolve([])),
  getTeams: vi.fn(() =>
    Promise.resolve([
      { id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
      { id: "team-2", name: "Seniors", sportCategoryId: "cat-2", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
    ]),
  ),
  getPriorityTiers: vi.fn(() =>
    Promise.resolve([
      { id: 1, label: "S", name: "Fanion", color: null },
      { id: 3, label: "B", name: "Moyenne", color: null },
    ]),
  ),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }])),
  getCategories: vi.fn(() => Promise.resolve([{ id: "cat-1", name: "U13" }, { id: "cat-2", name: "Seniors" }])),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Jean", lastName: "Dupont" }])),
  getLeagueWindows: vi.fn(() => Promise.resolve({ league: "AURA", items: [], resolvedTeamWindows: {} })),
  // Capacity layer (P1-4 PR B) — empty: no window declared, nothing blocks.
  getVenueMatchWindows: vi.fn(() => Promise.resolve([])),
  getVenueUnavailabilities: vi.fn(() => Promise.resolve([])),
  // Preferences layer (P1-4 PR C) — empty: no habit, no link.
  getTeamMatchHabits: vi.fn(() => Promise.resolve([])),
  getTeamLinks: vi.fn(() => Promise.resolve([])),
  // Auto-placement (P1-4 PR D).
  placeMatches: vi.fn(() =>
    Promise.resolve({
      placed: 1,
      skipped: 0,
      unplaced: [{ matchId: "fx-unplaced", reason: "no_access_window", message: "Aucune fenêtre d'accès match ne contient l'empreinte de 2h15 ce jour-là." }],
      diagnostics: [],
    }),
  ),
  getConflicts: vi.fn(() =>
    Promise.resolve({
      clubId: "c",
      seasonId: "s",
      conflicts: [
        {
          type: "MATCH_MATCH",
          severity: 3,
          coachRole: "MAIN",
          coachId: "coach-1",
          start: "2026-10-03T15:30:00+00:00",
          end: "2026-10-03T16:00:00+00:00",
          left: { fixtureId: "fx-unplaced", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: null, windowStart: "", windowEnd: "" },
          right: { fixtureId: "fx-placed", teamId: "team-2", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
        },
      ],
    }),
  ),
  createFixture: vi.fn(() => Promise.resolve({})),
  placeFixture,
  // Manual loop (P1-4 PR E1).
  updateFixture: vi.fn(() => Promise.resolve({})),
  deleteFixture: vi.fn(() => Promise.resolve()),
  unplaceFixture,
  moveFixture: vi.fn(() => Promise.resolve({})),
  lockFixture: vi.fn(() => Promise.resolve({})),
  unlockFixture: vi.fn(() => Promise.resolve({})),
  swapFixtures: vi.fn(() => Promise.resolve()),
}));

beforeEach(() => {
  placeFixture.mockClear();
  unplaceFixture.mockClear();
  meState.club = undefined;
  useMatchesStore.setState({ selectedWeekend: null, selectedFixtureId: null, swapSourceId: null, fixtureFormOpen: false });
});

describe("MatchesPage (integration)", () => {
  it("lists the unplaced home match and renders the conflict radar", async () => {
    renderWithProviders(<MatchesPage />);

    // Unplaced to-do list.
    expect(await screen.findByRole("button", { name: /vs Voisins/ })).toBeInTheDocument();
    // Radar shows the same-coach conflict.
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    expect(screen.getByText(/U13 et Seniors/)).toBeInTheDocument();
    // Placed match is on the grid.
    expect(screen.getByText("Seniors")).toBeInTheDocument();
  });

  it("auto-places on demand and surfaces the unplaced reason (P1-4 PR D)", async () => {
    const user = (await import("@testing-library/user-event")).default.setup();
    renderWithProviders(<MatchesPage />);

    await screen.findByRole("button", { name: /vs Voisins/ });
    await user.click(screen.getByRole("button", { name: /Placer automatiquement/ }));

    const { placeMatches: placeMatchesMock } = await import("./api");
    expect(placeMatchesMock).toHaveBeenCalledOnce();
    // The named reason lands under the still-unplaced match — the
    // ask-your-derogation-early signal.
    expect(await screen.findByText(/Aucune fenêtre d'accès match/)).toBeInTheDocument();
  });

  it("P1-3 §4bis — le bouton « Placer » affiche le solde et se désactive à 0 (Découverte bridée)", async () => {
    meState.club = { entitlements: { planCode: "decouverte", planName: "Découverte", maxTeams: null, teamsUsed: 4, creditsMax: 10, creditsUsed: 10, canGenerate: false, canPlaceMatches: false, canExportPdf: false, seasonTransition: false } };
    renderWithProviders(<MatchesPage />);
    const place = await screen.findByRole("button", { name: /Placer automatiquement \(0\)/ });
    expect(place).toBeDisabled();
  });

  it("P1-3 §4bis — offre payante : le bouton « Placer » n'affiche AUCUN solde", async () => {
    meState.club = { entitlements: { planCode: "essentiel", planName: "Essentiel", maxTeams: 20, teamsUsed: 4, creditsMax: null, creditsUsed: 0, canGenerate: true, canPlaceMatches: true, canExportPdf: true, seasonTransition: true } };
    renderWithProviders(<MatchesPage />);
    const place = await screen.findByRole("button", { name: "Placer automatiquement" });
    expect(place).toBeEnabled();
  });

  it("opens the placement panel and places a home fixture (venue + kickoff)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);

    await user.click(await screen.findByRole("button", { name: /vs Voisins/ }));

    const venue = await screen.findByLabelText("Gymnase");
    await user.selectOptions(venue, "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "15:00");
    await user.click(screen.getByRole("button", { name: "Placer" }));

    expect(placeFixture).toHaveBeenCalledOnce();
    expect(placeFixture).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-unplaced" }), { venueId: "venue-1", kickoffTime: "15:00" });
  });

  it("shows the away band with the opponent venue (P1-4 PR E2)", async () => {
    renderWithProviders(<MatchesPage />);

    // Away band of the active weekend — the away match is visible at last.
    expect(await screen.findByText(/à Grenoble \(Halle Clemenceau\)/)).toBeInTheDocument();

    // The graded diagnostic groups by severity (MAIN coach clash = group 3).
    expect(await screen.findByText("Coach principal en double")).toBeInTheDocument();
  });

  // ── RMM-1 PR2 — la boucle hebdo perd les actions rares ET le toggle A/B ────────────
  it("la barre de la boucle se réduit : ni actions rares, ni toggle « Week-end type »", async () => {
    renderWithProviders(<MatchesPage />);
    await screen.findByRole("button", { name: /vs Voisins/ });

    // Les 3 actions rares sont parties en Configuration.
    expect(screen.queryByRole("button", { name: "Habitudes & passerelles" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Accès match" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Engagements FFBB" })).not.toBeInTheDocument();
    // L'image A/B est un écran de plein droit (Configuration), plus un toggle.
    expect(screen.queryByRole("button", { name: /Week-end type/ })).not.toBeInTheDocument();

    // La barre réduite reste : placer auto · importer FBI · nouveau match.
    expect(screen.getByRole("button", { name: /Placer automatiquement/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Importer FBI/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Nouveau match/ })).toBeInTheDocument();
  });

  it("clicking a placed grid cell opens the manual-loop panel (P1-4 PR E1)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);

    // The placed match renders as a clickable grid cell (team + kickoff + opponent).
    await user.click(await screen.findByRole("button", { name: /Seniors.*Rivaux/ }));

    // Panel of a PLACED match: main action is Déplacer, manual loop below.
    expect(await screen.findByRole("button", { name: "Déplacer" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "Dé-placer" }));
    expect(unplaceFixture).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-placed" }));
  });
});
