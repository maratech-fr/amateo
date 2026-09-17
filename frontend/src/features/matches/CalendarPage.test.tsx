import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";
import { pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";

import * as matchesApi from "./api";
import { CalendarPage } from "./CalendarPage";
import { useMatchesStore } from "./store";

// URL EXPLICITE (les 4 types + extérieurs + semaine type) : le seed lit l'URL au montage et
// REDÉFINIT l'état Consulter, donc le `beforeEach` seul ne suffit pas à préserver les tests de
// comportement (les nouveaux DÉFAUTS masquent amicaux et extérieurs). Les défauts sont testés à part.
const EXPLICIT = "/?type=amical,championnat,coupe,brassage&exterieurs=1&type_semaine=1";

const { placeFixture, unplaceFixture, submitFixture } = vi.hoisted(() => ({
  placeFixture: vi.fn(() => Promise.resolve({})),
  unplaceFixture: vi.fn(() => Promise.resolve({})),
  submitFixture: vi.fn(() => Promise.resolve({})),
}));

const meState = vi.hoisted(() => ({ club: undefined as Record<string, unknown> | undefined }));
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "s1", hasFinishedVersion: true }, club: meState.club } }),
}));

const planningLinks = vi.hoisted(() => ({ teamCoaches: [] as unknown[], coachPlayers: [] as unknown[] }));
vi.mock("@/features/planning/queries", () => ({
  useTeamCoaches: () => ({ data: planningLinks.teamCoaches }),
  useCoachPlayers: () => ({ data: planningLinks.coachPlayers }),
}));

vi.mock("./api", () => ({
  getFixtures: vi.fn(() =>
    Promise.resolve([
      { id: "fx-unplaced", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Voisins", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: null },
      { id: "fx-placed", teamId: "team-2", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Rivaux", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: "26" },
      { id: "fx-away", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "Grenoble", status: "UNPLACED", venueId: null, kickoffTime: null, fbiVenueLabel: "Halle Clemenceau", externalRef: null },
    ]),
  ),
  getCompetitions: vi.fn(() => Promise.resolve([])),
  getTeams: vi.fn(() =>
    Promise.resolve([
      { id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
      { id: "team-2", name: "Seniors", sportCategoryId: "cat-2", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
    ]),
  ),
  getPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Fanion", color: null }, { id: 3, label: "B", name: "Moyenne", color: null }])),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00", externalLabels: [] }])),
  getCategories: vi.fn(() => Promise.resolve([{ id: "cat-1", name: "U13" }, { id: "cat-2", name: "Seniors" }])),
  getSportCategoryDurations: vi.fn(() =>
    Promise.resolve([
      { id: "cat-1", sportId: "sp", name: "U13", matchMinutes: null, warmupMinutes: null, defaultMatchMinutes: 105, defaultWarmupMinutes: 30 },
      { id: "cat-2", sportId: "sp", name: "Seniors", matchMinutes: null, warmupMinutes: null, defaultMatchMinutes: 90, defaultWarmupMinutes: 30 },
    ]),
  ),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Jean", lastName: "Dupont" }])),
  getLeagueWindows: vi.fn(() => Promise.resolve({ league: "AURA", items: [], resolvedTeamWindows: {} })),
  getVenueMatchWindows: vi.fn(() => Promise.resolve([])),
  getVenueUnavailabilities: vi.fn(() => Promise.resolve([])),
  getTeamMatchHabits: vi.fn(() => Promise.resolve([])),
  getTeamLinks: vi.fn(() => Promise.resolve([])),
  getMatchSlotRotations: vi.fn(() => Promise.resolve([])),
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
      seasonPlanChosen: true,
      conflicts: [
        {
          type: "MATCH_MATCH",
          severity: 3, resolution: null,
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
  updateFixture: vi.fn(() => Promise.resolve({})),
  deleteFixture: vi.fn(() => Promise.resolve()),
  unplaceFixture,
  moveFixture: vi.fn(() => Promise.resolve({})),
  lockFixture: vi.fn(() => Promise.resolve({})),
  unlockFixture: vi.fn(() => Promise.resolve({})),
  swapFixtures: vi.fn(() => Promise.resolve()),
  submitFixture,
  reopenFixture: vi.fn(() => Promise.resolve({})),
  postModuleVisit: vi.fn(() => Promise.resolve({ firstVisit: true, newFixturesCount: 0, newConflictFingerprints: [], planningChanged: false, referenceTakenAt: "2026-08-24T10:00:00+00:00" })),
  getLatestFbiIngestion: vi.fn(() => Promise.resolve({ latest: { depositedAt: "2026-08-20T09:00:00+00:00", source: "FBI_XLSX", created: 10, updated: 2, unchanged: 3, deviationsCount: 0 } })),
  getOpponentTravel: vi.fn(() => Promise.resolve([])),
  getVenueLabelInventory: vi.fn(() => Promise.resolve([])),
}));

beforeEach(() => {
  placeFixture.mockClear();
  unplaceFixture.mockClear();
  submitFixture.mockClear();
  meState.club = undefined;
  planningLinks.teamCoaches = [];
  planningLinks.coachPlayers = [];
  setTodayOverride(null);
  useMatchesStore.setState({
    selectedWeekend: null,
    selectedFixtureId: null,
    swapSourceId: null,
    fixtureFormOpen: false,
    importDialogOpen: false,
    filterMode: "equipe",
    filterIds: [],
    // État EXPLICITE (les 4 types cochés + extérieurs affichés) : les tests de comportement
    // ci-dessous reposent sur des amicaux (competitionId null) et le bloc extérieur, que les
    // NOUVEAUX défauts (amicaux + extérieurs masqués) cacheraient. Les défauts sont testés à part.
    consultKinds: ["amical", "championnat", "coupe", "brassage"],
    consultFamilies: null,
    consultTypicalWeek: true,
    consultAway: true,
    consultTemporality: "semaine",
    consultMonth: null,
    consultPhaseId: null,
    unplacedReasons: new Map(),
  });
});

describe("CalendarPage — la Semaine (ex-boucle, fusion PR 3b)", () => {
  it("au chargement, rend le FullPageSpinner PLEINE PAGE (pas un spinner nu)", () => {
    vi.mocked(matchesApi.getFixtures).mockReturnValueOnce(new Promise(() => {}));
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(screen.getByLabelText("Chargement")).toHaveClass("size-8");
  });

  it("rend la barre « Semaine affichée » (3 compteurs) ET l'établi (grille + radar) SANS rail", async () => {
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    const group = await screen.findByRole("group", { name: "Semaine affichée" });
    expect(within(group).getByRole("button", { name: /1 à placer/ })).toBeInTheDocument();
    expect(within(group).getByRole("link", { name: /1 conflits/ })).toBeInTheDocument();
    expect(within(group).getByRole("button", { name: /2 à saisir dans FBI/ })).toBeInTheDocument();
    // Le radar est visible d'emblée (plus de vue à sélectionner) : le conflit du coach.
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    expect(screen.getByText(/U13 et Seniors/)).toBeInTheDocument();
    // Plus aucun rail (navigation d'étapes).
    expect(screen.queryByRole("navigation", { name: undefined })).not.toBeInTheDocument();
  });

  it("affiche le n° de rencontre dans la grille, absent quand null", async () => {
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText("n° 26")).toBeInTheDocument();
    expect(screen.queryByText(/n° —/)).not.toBeInTheDocument();
  });

  it("la grille dessine le bloc à la durée de match SERVIE (défaut de famille 90 min)", async () => {
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByTitle(/16:00–17:30/)).toBeInTheDocument();
    expect(screen.queryByTitle(/16:00–17:45/)).not.toBeInTheDocument();
  });

  it("« à saisir dans FBI » ouvre la modale de saisie ; cocher appelle submit", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: /à saisir dans FBI/ }));
    const dialog = await screen.findByRole("dialog", { name: "À recopier dans FBI" });
    expect(within(dialog).getByRole("heading", { name: "Seniors" })).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: /Marquer saisi.*Rivaux/ }));
    expect(submitFixture).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-placed" }));
  });

  it("liste le match à placer et pose le domicile", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: /vs Voisins/ }));
    await screen.findByRole("button", { name: /Gymnase/ });
    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "15:00");
    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(placeFixture).toHaveBeenCalledOnce();
    expect(placeFixture).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-unplaced" }), { venueId: "venue-1", kickoffTime: "15:00" });
  });

  it("« Placer automatiquement » (barre d'actions) auto-place et fait remonter la raison", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: /Placer automatiquement/ }));
    const { placeMatches: placeMatchesMock } = await import("./api");
    expect(placeMatchesMock).toHaveBeenCalledOnce();
    expect(await screen.findByText(/Aucune fenêtre d'accès match/)).toBeInTheDocument();
  });

  it("« Placer automatiquement » affiche le solde et se désactive à 0 (Découverte bridée)", async () => {
    meState.club = { entitlements: { planCode: "decouverte", planName: "Découverte", maxTeams: null, teamsUsed: 4, creditsMax: 10, creditsUsed: 10, canGenerate: false, canPlaceMatches: false, canExportPdf: false, seasonTransition: false } };
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    const place = await screen.findByRole("button", { name: /Placer automatiquement \(0\)/ });
    expect(place).toBeDisabled();
  });

  it("offre payante : « Placer automatiquement » n'affiche AUCUN solde", async () => {
    meState.club = { entitlements: { planCode: "essentiel", planName: "Essentiel", maxTeams: 20, teamsUsed: 4, creditsMax: null, creditsUsed: 0, canGenerate: true, canPlaceMatches: true, canExportPdf: true, seasonTransition: true } };
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByRole("button", { name: "Placer automatiquement" })).toBeEnabled();
  });

  it("montre la bande extérieur ET le radar gradué dans le même établi", async () => {
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText(/à Grenoble \(Halle Clemenceau\)/)).toBeInTheDocument();
    // « Personne en double » paraît en tête de groupe de gravité ET par ligne — au moins une.
    expect((await screen.findAllByText("Personne en double")).length).toBeGreaterThan(0);
  });

  it("cliquer un bloc EXTÉRIEUR de la grille ouvre le dialogue d'édition", async () => {
    const user = userEvent.setup();
    const { container } = renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    const awayBlock = await waitFor(() => {
      const el = container.querySelector('[data-away="true"][data-fixture-id="fx-away"]');
      expect(el).not.toBeNull();
      return el as HTMLElement;
    });
    await user.click(awayBlock);
    expect(await screen.findByRole("heading", { name: "Modifier le match" })).toBeInTheDocument();
  });

  it("en mode échange, cliquer un extérieur ne fait RIEN (bloc inerte)", async () => {
    const user = userEvent.setup();
    const { container } = renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: /Seniors.*Rivaux/ }));
    await user.click(screen.getByRole("button", { name: /Échanger avec/ }));
    expect(screen.getByText(/cliquez le match à échanger/)).toBeInTheDocument();
    const awayBlock = container.querySelector('[data-away="true"]') as HTMLElement;
    await user.click(awayBlock);
    expect(screen.getByText(/cliquez le match à échanger/)).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Modifier le match" })).not.toBeInTheDocument();
  });

  it("clic sur une cellule placée ouvre le panneau de boucle manuelle", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: /Seniors.*Rivaux/ }));
    expect(await screen.findByRole("button", { name: "Déplacer" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "Dé-placer" }));
    expect(unplaceFixture).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-placed" }));
  });

  it("le slot de panneau est PERMANENT : état vide « Sélectionnez un match » sans sélection", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText("Sélectionnez un match")).toBeInTheDocument();
    await user.click(await screen.findByRole("button", { name: /Seniors.*Rivaux/ }));
    expect(await screen.findByRole("button", { name: "Dé-placer" })).toBeInTheDocument();
    expect(screen.queryByText("Sélectionnez un match")).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Fermer" }));
    expect(await screen.findByText("Sélectionnez un match")).toBeInTheDocument();
  });

  it("Échap sort du mode échange ; le bandeau reste tant qu'il est armé", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: /Seniors.*Rivaux/ }));
    await user.click(screen.getByRole("button", { name: /Échanger avec/ }));
    expect(screen.getByText(/cliquez le match à échanger/)).toBeInTheDocument();
    await user.keyboard("{Escape}");
    expect(screen.queryByText(/cliquez le match à échanger/)).not.toBeInTheDocument();
  });

  it("la barre d'actions porte Nouveau match ET Placer automatiquement, sans les gestes rares", async () => {
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByRole("button", { name: /Nouveau match/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Placer automatiquement/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Habitudes & passerelles" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Engagements FFBB" })).not.toBeInTheDocument();
    // La vue « batch » et son bouton « Importer FBI » ont disparu (Importer est un onglet).
    expect(screen.queryByRole("button", { name: /Importer FBI/ })).not.toBeInTheDocument();
  });

  it("un delta plein affiche le bandeau du gardien sans changer les compteurs", async () => {
    const { postModuleVisit } = await import("./api");
    (postModuleVisit as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      firstVisit: false,
      newFixturesCount: 12,
      newConflictFingerprints: ["a", "b", "c"],
      planningChanged: true,
      referenceTakenAt: "2026-08-24T10:00:00+00:00",
    });
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    const banner = await screen.findByRole("status");
    expect(banner).toHaveTextContent("Depuis votre dernière visite");
    expect(banner).toHaveTextContent("12 matchs arrivés");
    const group = await screen.findByRole("group", { name: "Semaine affichée" });
    expect(within(group).getByRole("link", { name: /1 conflits/ })).toBeInTheDocument();
  });

  it("affiche un rappel discret du dernier dépôt FBI", async () => {
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText(/Dernier dépôt FBI/i)).toBeInTheDocument();
  });

  it("P4-133 — une lecture des rencontres en échec DIT l'échec, jamais « Aucun match importé »", async () => {
    vi.mocked(matchesApi.getFixtures).mockRejectedValueOnce(new Error("réseau"));
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("Le chargement a échoué.");
    expect(within(alert).getByRole("button", { name: "Réessayer" })).toBeInTheDocument();
    expect(screen.queryByText("Aucun match importé")).not.toBeInTheDocument();
  });

  it("P4-133 — un échec de lecture des gymnases cède aussi la place au message d'échec", async () => {
    vi.mocked(matchesApi.getVenues).mockRejectedValueOnce(new Error("réseau"));
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText("Le chargement a échoué.")).toBeInTheDocument();
  });
});

describe("CalendarPage — filtres PR-1 + navigation de semaine", () => {
  it("sans filtre : axe « équipe » par défaut, radar et compteurs inchangés", async () => {
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByRole("button", { name: "Par équipe" })).toHaveAttribute("aria-pressed", "true");
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    const group = await screen.findByRole("group", { name: "Semaine affichée" });
    expect(within(group).getByRole("link", { name: /1 conflits/ })).toBeInTheDocument();
  });

  it("filtre « par coach » : grille, compteurs et radar recadrés sur le périmètre du coach", async () => {
    vi.mocked(matchesApi.getCoaches).mockResolvedValueOnce([
      { id: "thomas", firstName: "Thomas", lastName: "Martin" },
      { id: "autre", firstName: "Autre", lastName: "Coach" },
    ]);
    vi.mocked(matchesApi.getTeams).mockResolvedValueOnce([
      { id: "sm1", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
      { id: "u15m1", name: "U15M1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
      { id: "hors", name: "HorsPerim", sportCategoryId: "c", level: null, gender: null, priorityTierId: 3, tierOrder: 1 },
    ]);
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([
      { id: "fx-sm1", teamId: "sm1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "HOME", opponentLabel: "AlphaOpp", status: "PLACED", venueId: "venue-1", kickoffTime: "14:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
      { id: "fx-u15", teamId: "u15m1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "HOME", opponentLabel: "BetaOpp", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
      { id: "fx-hors", teamId: "hors", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "HOME", opponentLabel: "GammaOpp", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
      { id: "fx-sm1-away", teamId: "sm1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "DeltaOpp", status: "UNPLACED", venueId: null, kickoffTime: null, fbiVenueLabel: "Halle X", externalRef: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
    ]);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({
      clubId: "c",
      seasonId: "s",
      seasonPlanChosen: true,
      conflicts: [
        { type: "MATCH_MATCH", severity: 3, resolution: null, coachRole: "MAIN", coachId: "thomas", left: { fixtureId: "fx-sm1", teamId: "sm1", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "14:00", windowStart: "", windowEnd: "" }, right: { fixtureId: "fx-u15", teamId: "u15m1", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "16:00", windowStart: "", windowEnd: "" } },
        { type: "VENUE_OVERLAP", severity: 2, resolution: null, venueId: "venue-1", left: { fixtureId: "fx-hors", teamId: "hors", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "18:00", windowStart: "", windowEnd: "" }, right: { fixtureId: "fx-hors", teamId: "hors", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "18:00", windowStart: "", windowEnd: "" } },
      ],
    });
    planningLinks.teamCoaches = [
      { id: "tc1", teamId: "sm1", coachId: "thomas", role: "ASSISTANT" },
      { id: "tc2", teamId: "u15m1", coachId: "thomas", role: "MAIN" },
    ];

    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: "Par coach" }));
    await user.click(screen.getByRole("button", { name: /Coachs :/ }));
    await user.click(await screen.findByRole("button", { name: "Thomas Martin" }));

    // Radar + grille visibles ensemble : le conflit du coach, ses domiciles, jamais l'équipe hors-périmètre.
    expect(await screen.findByText(/SM1 et U15M1/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /SM1.*AlphaOpp/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /U15M1.*BetaOpp/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /HorsPerim/ })).not.toBeInTheDocument();
    // Compteurs recadrés : 1 conflit du coach.
    const group = await screen.findByRole("group", { name: "Semaine affichée" });
    expect(within(group).getByRole("link", { name: /1 conflits/ })).toBeInTheDocument();
    expect(screen.getByText("assistant")).toBeInTheDocument();
  });

  it("semaine par défaut : la première semaine ≥ la semaine courante", async () => {
    setTodayOverride("2026-09-10");
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([
      { id: "fx-past", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-09-05", homeAway: "HOME", opponentLabel: "Anciens", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
      { id: "fx-future", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-09-19", homeAway: "HOME", opponentLabel: "Futurs", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
    ]);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: [] });
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText(/Semaine du 14 sept\. au 20 sept\./)).toBeInTheDocument();
    expect(screen.queryByText(/au 6 sept\./)).not.toBeInTheDocument();
  });

  it("un week-end 100 % extérieur rend l'établi (bande extérieur), jamais « Aucun domicile à recopier »", async () => {
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([
      { id: "fx-away-1", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "Grenoble", status: "UNPLACED", venueId: null, kickoffTime: null, fbiVenueLabel: "Halle Clemenceau", externalRef: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
    ]);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: [] });
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByRole("heading", { name: "À placer" })).toBeInTheDocument();
    expect(screen.getByText(/À l'extérieur ce week-end/)).toBeInTheDocument();
    expect(screen.queryByText(/Aucun domicile à recopier/)).not.toBeInTheDocument();
  });

  it("filtre « par gymnase » : les extérieurs (sans gymnase) sont exclus", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: "Par gymnase" }));
    await user.click(screen.getByRole("button", { name: /Gymnases :/ }));
    // L'option du filtre est un BOUTON « Gymnase Alpha » (le même nom paraît aussi en
    // en-tête de colonne de la grille, un <span> — d'où le ciblage par rôle bouton).
    await user.click(await screen.findByRole("button", { name: "Gymnase Alpha" }));
    expect(await screen.findByRole("button", { name: /Seniors.*Rivaux/ })).toBeInTheDocument();
    expect(screen.queryByText(/à Grenoble/)).not.toBeInTheDocument();
  });

  it("un domicile de la semaine sans gymnase ⇒ compteur discret sous la grille", async () => {
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText(/1 domicile de ce week-end sans gymnase, non affiché/)).toBeInTheDocument();
  });

  it("des libellés de salle non appariés ⇒ bandeau au-dessus de la grille", async () => {
    vi.mocked(matchesApi.getVenueLabelInventory).mockResolvedValueOnce([
      { labelKey: "gymnase mateo", displayLabel: "GYMNASE MATEO", venueId: null, suggestedVenueId: null, homeCount: 4, placedCount: 0, unplacedCount: 4 },
    ]);
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText(/1 libellé de salle non apparié/)).toBeInTheDocument();
  });

  it("saison vide ⇒ EmptyState « Aucun match importé » + lien Importer (compteurs à zéro)", async () => {
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([]);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: [] });
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText("Aucun match importé")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Importer des rencontres/ })).toHaveAttribute("href", "/matchs/importer");
    // Compteurs toujours affichés, à zéro.
    const group = screen.getByRole("group", { name: "Semaine affichée" });
    expect(within(group).getByRole("button", { name: /0 à placer/ })).toBeInTheDocument();
  });
});

describe("CalendarPage — chips, familles, temporalités (ex-Consulter)", () => {
  const consultFixtures = [
    { id: "fx-home-amical", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Voisins", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
    { id: "fx-home-coupe", teamId: "team-2", seasonId: "s", competitionId: "comp-coupe", matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Rivaux", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00", externalRef: null, fbiVenueLabel: null, placementSource: "SOLVER", unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
    { id: "fx-home-w2", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-10", homeAway: "HOME", opponentLabel: "Lointains", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
  ];
  const consultCompetitions = [
    { id: "comp-coupe", teamId: "team-2", name: "Coupe AURA", competitionType: "CUP", ffbbCompetitionId: "ffbb-1", expectedMatchdays: null },
    { id: "comp-champ", teamId: "team-1", name: "Nationale 3", competitionType: "CHAMPIONSHIP", ffbbCompetitionId: "ffbb-2", expectedMatchdays: 10 },
  ];
  const consultConflicts = {
    clubId: "c",
    seasonId: "s",
    seasonPlanChosen: true,
    conflicts: [
      { type: "VENUE_OVERLAP", severity: 1, resolution: null, left: { fixtureId: "fx-home-amical", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" }, right: { fixtureId: "fx-home-coupe", teamId: "team-2", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "18:00", windowStart: "", windowEnd: "" } },
      { type: "VENUE_UNAVAILABLE", severity: 1, resolution: null, fixture: { fixtureId: "fx-home-w2", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-10", kickoffTime: "16:00", status: "PLACED" } },
    ],
  };

  function seedConsult() {
    setTodayOverride("2026-10-01");
    // `mockResolvedValueOnce` (jamais persistant) : consommé par le seul rendu du test,
    // ne fuit pas vers les tests suivants (les mocks retombent sur leurs défauts d'usine).
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce(consultFixtures as never);
    vi.mocked(matchesApi.getCompetitions).mockResolvedValueOnce(consultCompetitions as never);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce(consultConflicts as never);
  }

  it("affiche les chips de type de compétition et une chip famille avec compteur", async () => {
    seedConsult();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByRole("button", { name: /Amical/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Championnat/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Coupe/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Brassage/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Collision de gymnase/ })).toBeInTheDocument();
  });

  it("une famille entièrement traitée garde sa chip à 0 en sourdine (P4-207)", async () => {
    setTodayOverride("2026-10-01");
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce(consultFixtures as never);
    vi.mocked(matchesApi.getCompetitions).mockResolvedValueOnce(consultCompetitions as never);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({
      clubId: "c",
      seasonId: "s",
      seasonPlanChosen: true,
      conflicts: [
        { type: "VENUE_OVERLAP", severity: 1, resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" }, left: { fixtureId: "fx-home-amical", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" }, right: { fixtureId: "fx-home-coupe", teamId: "team-2", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "18:00", windowStart: "", windowEnd: "" } },
      ],
    } as never);
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    const chip = await screen.findByRole("button", { name: /Collision de gymnase/ });
    expect(within(chip).getByText("0")).toHaveClass("text-muted-foreground");
  });

  it("le compteur d'une famille suit la SEMAINE affichée (scope hebdo)", async () => {
    seedConsult();
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByRole("button", { name: /Collision de gymnase/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Gymnase indisponible/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Semaine suivante" }));
    expect(await screen.findByRole("button", { name: /Gymnase indisponible/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Collision de gymnase/ })).not.toBeInTheDocument();
  });

  it("la semaine type (interrupteur ON) montre les ghosts d'habitude ; l'éteindre les retire", async () => {
    seedConsult();
    vi.mocked(matchesApi.getTeamMatchHabits).mockResolvedValueOnce([{ id: "h-1", teamId: "team-3", dayOfWeek: 6, kickoffTime: "14:00", venueId: "venue-1" }] as never);
    vi.mocked(matchesApi.getTeams).mockResolvedValueOnce([
      { id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
      { id: "team-2", name: "Seniors", sportCategoryId: "cat-2", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
      { id: "team-3", name: "Cadets", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 1 },
    ] as never);
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    expect(await screen.findByText("Habitude Cadets")).toBeInTheDocument();
    await user.click(screen.getByRole("switch", { name: /Semaine type/ }));
    expect(screen.queryByText("Habitude Cadets")).not.toBeInTheDocument();
  });

  it("bascule Mois : table groupée par jour, compteurs sur le MOIS (les deux familles)", async () => {
    seedConsult();
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: "Mois" }));
    expect(await screen.findByRole("table")).toBeInTheDocument();
    expect(screen.getByText("Voisins")).toBeInTheDocument();
    expect(screen.getByText("Rivaux")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Collision de gymnase/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Gymnase indisponible/ })).toBeInTheDocument();
    expect(screen.queryByRole("switch", { name: /Semaine type/ })).not.toBeInTheDocument();
  });

  it("bascule Phase : coupe SANS dénominateur (P4-195), championnat AVEC dénominateur", async () => {
    seedConsult();
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: "Phase" }));
    const select = await screen.findByRole("combobox", { name: /Phase|compétition/i });
    expect(screen.getByRole("option", { name: /Coupe AURA — Seniors/ })).toBeInTheDocument();
    expect(screen.getByText(/^\s*1 journée importée\s*$/)).toBeInTheDocument();
    expect(screen.getByText("Rivaux")).toBeInTheDocument();
    await user.selectOptions(select, "comp-champ");
    expect(await screen.findByText(/0\s*\/\s*10\s+journées importées/)).toBeInTheDocument();
  });

  it("cliquer une ligne (Mois) bascule en Semaine et pointe le match (plus de navigation)", async () => {
    seedConsult();
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: "Mois" }));
    await screen.findByRole("table");
    await user.click(screen.getByRole("button", { name: /Voisins/ }));
    // Bascule en Semaine (le segment « Semaine » devient pressé), semaine + rencontre posées.
    await waitFor(() => expect(screen.getByRole("button", { name: "Semaine" })).toHaveAttribute("aria-pressed", "true"));
    expect(useMatchesStore.getState().consultTemporality).toBe("semaine");
    expect(useMatchesStore.getState().selectedWeekend).toBe("2026-10-03");
    expect(useMatchesStore.getState().selectedFixtureId).toBe("fx-home-amical");
  });
});

describe("CalendarPage — modale FBI (ConfirmDialog imbriqué)", () => {
  it("le ConfirmDialog vit DANS la modale : Échap ferme le confirm seul, le focus revient au déclencheur", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    await user.click(await screen.findByRole("button", { name: /à saisir dans FBI/ }));
    const dialog = await screen.findByRole("dialog", { name: "À recopier dans FBI" });
    const batchBtn = within(dialog).getByRole("button", { name: "Tout marquer saisi" });
    await user.click(batchBtn);
    // Le ConfirmDialog imbriqué est ouvert.
    expect(await screen.findByText(/Marquer saisi 1 match/)).toBeInTheDocument();
    // Échap ne ferme QUE le confirm ; la modale FBI reste ouverte.
    await user.keyboard("{Escape}");
    await waitFor(() => expect(screen.queryByText(/Marquer saisi 1 match/)).not.toBeInTheDocument());
    expect(screen.getByRole("dialog", { name: "À recopier dans FBI" })).toBeInTheDocument();
    // Le focus est revenu au bouton déclencheur (restauration `useModalA11y`).
    expect(batchBtn).toHaveFocus();
  });

  it("Échap sur la modale FBI la ferme et rend le focus au bouton compteur", async () => {
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    const fbiBtn = await screen.findByRole("button", { name: /à saisir dans FBI/ });
    await user.click(fbiBtn);
    await screen.findByRole("dialog", { name: "À recopier dans FBI" });
    await user.keyboard("{Escape}");
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "À recopier dans FBI" })).not.toBeInTheDocument());
    expect(fbiBtn).toHaveFocus();
  });
});

// ── A — nouveaux défauts + interrupteur « Extérieurs » ─────────────────────────────
describe("CalendarPage — défauts (état vierge) + interrupteur Extérieurs", () => {
  function fx(over: Record<string, unknown>) {
    return {
      seasonId: "s",
      externalRef: null,
      fbiVenueLabel: null,
      placementSource: "MANUAL",
      unplacedReason: null,
      reviewState: "NEW" as const,
      reviewedAt: null,
      pendingDeviations: [],
      ffbbRencontreId: null,
      opponentOrganismeCode: null,
      opponentTeamKey: null,
      suggestedVenueId: null,
      ...over,
    };
  }
  const champComp = { id: "comp-champ", teamId: "team-1", name: "Nat3", competitionType: "CHAMPIONSHIP", ffbbCompetitionId: "f", expectedMatchdays: 10 };
  const mix = [
    fx({ id: "fx-champ", teamId: "team-1", competitionId: "comp-champ", matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "ChampHome", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00" }),
    fx({ id: "fx-amical", teamId: "team-2", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "AmicalHome", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00" }),
    fx({ id: "fx-away", teamId: "team-1", competitionId: "comp-champ", matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "GrenobleChamp", status: "UNPLACED", venueId: null, kickoffTime: null, fbiVenueLabel: "Halle X" }),
  ];
  function seedMix() {
    setTodayOverride("2026-10-01");
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce(mix as never);
    vi.mocked(matchesApi.getCompetitions).mockResolvedValueOnce([champComp] as never);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: [] } as never);
  }

  it("état vierge : amicaux masqués (type), extérieurs masqués (interrupteur) ; puces par défaut", async () => {
    seedMix();
    renderWithProviders(<CalendarPage />, { route: "/" });
    // Puces : championnat/coupe/brassage cochés, amical décoché ; interrupteur Extérieurs éteint.
    expect(await screen.findByRole("button", { name: "Championnat" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "Amical" })).toHaveAttribute("aria-pressed", "false");
    expect(screen.getByRole("switch", { name: "Extérieurs" })).toHaveAttribute("aria-checked", "false");
    // Le championnat à domicile s'affiche ; l'amical et l'extérieur non.
    expect(screen.getByRole("button", { name: /ChampHome/ })).toBeInTheDocument();
    expect(screen.queryByText("AmicalHome")).not.toBeInTheDocument();
    expect(screen.queryByText(/GrenobleChamp/)).not.toBeInTheDocument();
  });

  it("Semaine : allumer « Extérieurs » révèle la bande extérieur et pose exterieurs=1", async () => {
    seedMix();
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: "/" });
    await screen.findByRole("button", { name: /ChampHome/ });
    expect(screen.queryByText(/à GrenobleChamp/)).not.toBeInTheDocument();
    await user.click(screen.getByRole("switch", { name: "Extérieurs" }));
    // L'extérieur reparaît (colonne « Extérieur » de la grille ET bande AwayList).
    expect((await screen.findAllByText(/à GrenobleChamp/)).length).toBeGreaterThan(0);
    expect(useMatchesStore.getState().consultAway).toBe(true);
  });

  it("Mois : l'interrupteur Extérieurs masque aussi les extérieurs de la table", async () => {
    seedMix();
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: "/" });
    await user.click(await screen.findByRole("button", { name: "Mois" }));
    await screen.findByRole("table");
    expect(screen.getByText("ChampHome")).toBeInTheDocument();
    expect(screen.queryByText("GrenobleChamp")).not.toBeInTheDocument();
    await user.click(screen.getByRole("switch", { name: "Extérieurs" }));
    expect(await screen.findByText("GrenobleChamp")).toBeInTheDocument();
  });

  it("NR cas Mara : extérieurs masqués, le conflit domicile × extérieur reste au radar", async () => {
    setTodayOverride("2026-10-01");
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([
      fx({ id: "fx-home", teamId: "team-1", competitionId: "comp-champ", matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "HomeChamp", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00" }),
      fx({ id: "fx-away", teamId: "team-2", competitionId: "comp-champ", matchDate: "2026-10-03", homeAway: "AWAY", opponentLabel: "AwayChamp", status: "UNPLACED", venueId: null, kickoffTime: null, fbiVenueLabel: "Halle X" }),
    ] as never);
    vi.mocked(matchesApi.getCompetitions).mockResolvedValueOnce([champComp] as never);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({
      clubId: "c",
      seasonId: "s",
      seasonPlanChosen: true,
      conflicts: [
        { type: "MATCH_MATCH", severity: 3, resolution: null, coachRole: "MAIN", coachId: "coach-1", left: { fixtureId: "fx-home", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" }, right: { fixtureId: "fx-away", teamId: "team-2", homeAway: "AWAY", matchDate: "2026-10-03", kickoffTime: null, windowStart: "", windowEnd: "" } },
      ],
    } as never);
    const { container } = renderWithProviders(<CalendarPage />, { route: "/" });
    // Extérieurs éteint : aucun bloc extérieur sur la grille…
    await screen.findByRole("button", { name: /HomeChamp/ });
    expect(screen.getByRole("switch", { name: "Extérieurs" })).toHaveAttribute("aria-checked", "false");
    expect(container.querySelector('[data-away="true"]')).toBeNull();
    // …mais le conflit domicile × extérieur reste affiché au radar.
    expect((await screen.findAllByText("Personne en double")).length).toBeGreaterThan(0);
  });

  it("NR compteurs : les trois compteurs sont IDENTIQUES interrupteur allumé/éteint", async () => {
    seedMix();
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: "/" });
    const group = await screen.findByRole("group", { name: "Semaine affichée" });
    const before = group.textContent;
    await user.click(screen.getByRole("switch", { name: "Extérieurs" }));
    await waitFor(() => expect(useMatchesStore.getState().consultAway).toBe(true));
    expect(group.textContent).toBe(before);
  });

  it("indice « masqués » : phrase plurielle + « Afficher » lève les masques", async () => {
    seedMix();
    const user = userEvent.setup();
    renderWithProviders(<CalendarPage />, { route: "/" });
    // 1 amical (type) + 1 extérieur (interrupteur) masqués sur la semaine affichée.
    expect(await screen.findByText("2 matchs masqués cette semaine (1 extérieur, 1 amical).")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Afficher" }));
    // Les masques sont levés : amical (cellule grille) + extérieur apparaissent, l'indice disparaît.
    expect(await screen.findByRole("button", { name: /AmicalHome/ })).toBeInTheDocument();
    expect((await screen.findAllByText(/à GrenobleChamp/)).length).toBeGreaterThan(0);
    expect(screen.queryByText(/matchs? masqués? cette semaine/)).not.toBeInTheDocument();
    // Annonce sr-only remplie après interaction.
    expect(screen.getByText("2 matchs affichés")).toBeInTheDocument();
  });

  it("indice « masqués » : phrase SINGULIÈRE (un seul amical masqué)", async () => {
    setTodayOverride("2026-10-01");
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([
      fx({ id: "fx-champ", teamId: "team-1", competitionId: "comp-champ", matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "ChampHome", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00" }),
      fx({ id: "fx-amical", teamId: "team-2", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "AmicalHome", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00" }),
    ] as never);
    vi.mocked(matchesApi.getCompetitions).mockResolvedValueOnce([champComp] as never);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: [] } as never);
    renderWithProviders(<CalendarPage />, { route: "/" });
    expect(await screen.findByText("1 match masqué cette semaine (1 amical).")).toBeInTheDocument();
  });

  it("« Réinitialiser » remet les défauts (types, extérieurs, semaine type) sans toucher la temporalité", async () => {
    const user = userEvent.setup();
    // Route EXPLICITE (tout coché + extérieurs + semaine type) → l'état diffère des défauts.
    renderWithProviders(<CalendarPage />, { route: EXPLICIT });
    const reset = await screen.findByRole("button", { name: "Réinitialiser" });
    await user.click(reset);
    await waitFor(() => expect(useMatchesStore.getState().consultKinds).toBeNull());
    expect(useMatchesStore.getState().consultAway).toBe(false);
    expect(useMatchesStore.getState().consultTypicalWeek).toBe(false);
    expect(screen.getByRole("button", { name: "Amical" })).toHaveAttribute("aria-pressed", "false");
  });

  it("A8 intégration : à la route produite par « Voir la semaine », l'URL SUFFIT (store à false écrasé)", async () => {
    // Store aux défauts (extérieurs MASQUÉS) : c'est l'URL, pas le store, qui doit décider.
    useMatchesStore.setState({ consultAway: false, consultKinds: null });
    renderWithProviders(<CalendarPage />, { route: "/?type=amical,championnat,coupe,brassage&exterieurs=1" });
    // Le seed lit l'URL → interrupteur allumé + bande extérieure rendue (fx-away « Grenoble »).
    expect(await screen.findByRole("switch", { name: "Extérieurs" })).toHaveAttribute("aria-checked", "true");
    expect((await screen.findAllByText(/à Grenoble/)).length).toBeGreaterThan(0);
    expect(useMatchesStore.getState().consultAway).toBe(true);
  });
});
