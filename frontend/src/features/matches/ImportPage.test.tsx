import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useToastStore } from "@/shared/stores/toastStore";

import type { AttachVenueLabelResult, FbiIngestionLatest, Fixture, FixtureReviewState, PendingDeviation, ReviewFixturesResult, Venue } from "./api";
import { ImportPage } from "./ImportPage";
import { weekendKeyOf } from "./lib/weekendGrid";
import { useMatchesStore } from "./store";

const {
  getTeams,
  getPriorityTiers,
  getFixtures,
  getVenues,
  getLatestFbiIngestion,
  getFfbbRencontres,
  applyFfbbRencontres,
  reviewFixtures,
  resolveFixtureDeviation,
  attachVenueLabel,
} = vi.hoisted(() => ({
  getTeams: vi.fn(),
  getPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Réserve", color: null }])),
  getFixtures: vi.fn(),
  getVenues: vi.fn((): Promise<Venue[]> => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] }])),
  getLatestFbiIngestion: vi.fn((): Promise<{ latest: FbiIngestionLatest | null }> => Promise.resolve({ latest: null })),
  getFfbbRencontres: vi.fn(),
  applyFfbbRencontres: vi.fn(() => Promise.resolve({ created: 0, updated: 0, unresolvedDeviations: [], depositedAt: "2026-08-24T14:06:00+00:00" })),
  reviewFixtures: vi.fn((): Promise<ReviewFixturesResult> => Promise.resolve({ reviewed: 1, skipped: [] })),
  resolveFixtureDeviation: vi.fn(() => Promise.resolve({ fixtureId: "x", reviewState: "REVIEWED", reviewedAt: "2026-10-02T10:00:00+00:00", pendingDeviations: [] })),
  attachVenueLabel: vi.fn((): Promise<AttachVenueLabelResult> => Promise.resolve({ venueId: "venue-1", label: "GYMNASE MATEO", attached: 2 })),
}));

vi.mock("./api", () => ({ getTeams, getPriorityTiers, getFixtures, getVenues, getLatestFbiIngestion, getFfbbRencontres, applyFfbbRencontres, reviewFixtures, resolveFixtureDeviation, attachVenueLabel }));

/** ky 2.x expose le corps parsé sur `error.data` — on reproduit ce contrat pour le 422 nommé. */
function httpError(status: number, body: unknown): HTTPError {
  const error = new HTTPError(new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } }), new Request("http://localhost/api/venues/venue-1/external-labels"), {} as never);
  (error as unknown as { data?: unknown }).data = body;
  return error;
}

const teams = [
  { id: "team-1", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
  { id: "team-2", name: "SF2", sportCategoryId: "c", level: null, gender: null, priorityTierId: 2, tierOrder: 0 },
];

let seq = 0;
function fx(teamId: string, reviewState: FixtureReviewState, matchDate: string, extra: Partial<Fixture> = {}): Fixture {
  seq += 1;
  return {
    id: `fx-${seq}`,
    teamId,
    seasonId: "s1",
    competitionId: null,
    matchDate,
    homeAway: "HOME",
    opponentLabel: "BRON",
    status: "PLACED",
    venueId: null,
    kickoffTime: null,
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: null,
    unplacedReason: null,
    reviewState,
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null,
    suggestedVenueId: null,
    ...extra,
  };
}

const dateDev: PendingDeviation = { field: "date", appValue: "2026-12-05", sourceValue: "2026-12-12", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: false };
const autoDev: PendingDeviation = { field: "kickoff", appValue: "15:00", sourceValue: "16:00", channel: "FFBB_API", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true };

function renderPage(fixtures: Fixture[], route = "/matchs/importer") {
  getTeams.mockResolvedValue(teams);
  getFixtures.mockResolvedValue(fixtures);
  getVenues.mockResolvedValue([{ id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] }]);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [
      { path: "/matchs/importer", element: <ImportPage /> },
      { path: "/matchs", element: <div>BOUCLE</div> },
      { path: "/matchs/reconciliation", element: <div>RECONCILIATION</div> },
    ],
    { initialEntries: [route] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  seq = 0; // ids déterministes par test (fx-1, fx-2, …)
  vi.clearAllMocks();
  getPriorityTiers.mockResolvedValue([{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Réserve", color: null }]);
  getLatestFbiIngestion.mockResolvedValue({ latest: null });
  applyFfbbRencontres.mockResolvedValue({ created: 0, updated: 0, unresolvedDeviations: [], depositedAt: "2026-08-24T14:06:00+00:00" });
  reviewFixtures.mockResolvedValue({ reviewed: 1, skipped: [] });
  getVenues.mockResolvedValue([{ id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] }]);
  attachVenueLabel.mockResolvedValue({ venueId: "venue-1", label: "GYMNASE MATEO", attached: 2 });
  useMatchesStore.setState({ reconciliation: null, filterMode: "equipe", filterIds: [], selectedWeekend: null });
  useToastStore.setState({ toasts: [] });
});

describe("ImportPage — les entrées de données", () => {
  it("porte les trois entrées (Importer FBI · Vérifier via l'API FFBB · Engagements FFBB) + la fraîcheur", async () => {
    renderPage([]);
    expect(await screen.findByRole("button", { name: /Importer FBI/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Vérifier via l'API FFBB/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Engagements FFBB/ })).toBeInTheDocument();
    expect(screen.getByText(/Aucun dépôt FBI cette saison/i)).toBeInTheDocument();
  });

  it("un dépôt existe → fraîcheur en relatif", async () => {
    getLatestFbiIngestion.mockResolvedValueOnce({ latest: { depositedAt: "2026-08-22T09:00:00+00:00", source: "FBI_XLSX", created: 5, updated: 1, unchanged: 2, deviationsCount: 0 } });
    renderPage([]);
    expect(await screen.findByText(/Dernier dépôt FBI/i)).toBeInTheDocument();
  });
});

describe("ImportPage — la file de traitement", () => {
  it("« Rien à traiter » quand tout est traité", async () => {
    renderPage([fx("team-1", "REVIEWED", "2026-11-01", { reviewedAt: "2026-10-01T10:00:00+00:00" })]);
    expect(await screen.findByText("Rien à traiter")).toBeInTheDocument();
  });

  it("liste l'équipe avec son en-tête compté (à valider + écarts)", async () => {
    renderPage([fx("team-1", "NEW", "2026-11-07"), fx("team-1", "OUT_OF_SYNC", "2026-12-05", { pendingDeviations: [dateDev] })]);
    expect(await screen.findByRole("button", { name: /SM1 · 1 à valider · 1 écart/ })).toBeInTheDocument();
  });

  it("Valider en ligne traite CETTE rencontre ({fixtureIds})", async () => {
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07")], "/matchs/importer?equipe=team-1");
    const validate = await screen.findByRole("button", { name: "Valider" });
    await user.click(validate);
    expect(reviewFixtures).toHaveBeenCalledWith({ fixtureIds: ["fx-1"] });
  });

  it("Tout valider traite l'équipe ({teamId}) et NOMME les sautées (équipe + date, jamais l'id)", async () => {
    reviewFixtures.mockResolvedValueOnce({ reviewed: 1, skipped: [{ fixtureId: "fx-2", reason: "pending_deviations" }] });
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07"), fx("team-1", "OUT_OF_SYNC", "2026-12-05", { pendingDeviations: [dateDev] })], "/matchs/importer?equipe=team-1");
    await user.click(await screen.findByRole("button", { name: "Tout valider" }));
    expect(reviewFixtures).toHaveBeenCalledWith({ teamId: "team-1" });
    await waitFor(() => {
      const messages = useToastStore.getState().toasts.map((t) => t.message);
      expect(messages.some((m) => m.includes("SM1") && /déc\./.test(m))).toBe(true);
      // FALSIFICATION — l'id de la rencontre sautée n'apparaît jamais.
      expect(messages.some((m) => m.includes("fx-2"))).toBe(false);
    });
  });

  it("un écart se lit en deux colonnes Amateo / FBI avec la conséquence, et se tranche", async () => {
    const user = userEvent.setup();
    renderPage([fx("team-1", "OUT_OF_SYNC", "2026-12-05", { pendingDeviations: [dateDev] })], "/matchs/importer?equipe=team-1");
    await screen.findByText("Amateo");
    expect(screen.getByText("FBI")).toBeInTheDocument();
    expect(screen.getByText("2026-12-05")).toBeInTheDocument();
    expect(screen.getByText("2026-12-12")).toBeInTheDocument();
    // Conséquence de « prendre le fichier » sur une date : dé-placement.
    expect(screen.getByText(/dé-place le match/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Prendre FBI" }));
    expect(resolveFixtureDeviation).toHaveBeenCalledWith({ fixtureId: "fx-1", field: "date", choice: "take_source" });

    await user.click(screen.getByRole("button", { name: "Garder Amateo" }));
    expect(resolveFixtureDeviation).toHaveBeenCalledWith({ fixtureId: "fx-1", field: "date", choice: "keep_app" });
  });

  it("une valeur auto-appliquée hors périmètre se signale (bandeau), pas d'arbitrage", async () => {
    renderPage([fx("team-2", "OUT_OF_SYNC", "2026-12-05", { pendingDeviations: [autoDev] })], "/matchs/importer?equipe=team-2");
    expect(await screen.findByText(/La source a déplacé ce match/)).toBeInTheDocument();
    // Rien à arbitrer → un « Valider » d'acquittement, pas de « Prendre FBI ».
    expect(screen.getByRole("button", { name: "Valider" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Prendre/ })).not.toBeInTheDocument();
  });

  it("« Afficher les traitées » révèle les rencontres traitées", async () => {
    const user = userEvent.setup();
    renderPage([fx("team-1", "REVIEWED", "2026-11-01", { reviewedAt: "2026-10-01T10:00:00+00:00" })]);
    // Masquées par défaut → « Rien à traiter ».
    expect(await screen.findByText("Rien à traiter")).toBeInTheDocument();
    await user.click(screen.getByRole("checkbox", { name: "Afficher les traitées" }));
    expect(await screen.findByRole("button", { name: /SM1/ })).toBeInTheDocument();
  });

  it("Placer pose le filtre équipe + le week-end dans le store et renvoie vers la boucle", async () => {
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07")], "/matchs/importer?equipe=team-1");
    await user.click(await screen.findByRole("button", { name: "Placer" }));
    const state = useMatchesStore.getState();
    expect(state.filterMode).toBe("equipe");
    expect(state.filterIds).toContain("team-1");
    expect(state.selectedWeekend).toBe(weekendKeyOf("2026-11-07"));
    expect(await screen.findByText("BOUCLE")).toBeInTheDocument();
  });

  it("`?equipe=` ouvre l'accordéon de l'équipe au montage", async () => {
    renderPage([fx("team-1", "NEW", "2026-11-07")], "/matchs/importer?equipe=team-1");
    const header = await screen.findByRole("button", { name: /SM1/ });
    expect(header).toHaveAttribute("aria-expanded", "true");
    expect(within(header.closest("div") as HTMLElement).getByRole("button", { name: "Valider" })).toBeInTheDocument();
  });
});

describe("ImportPage — rattacher un gymnase depuis le libellé (P4-187b)", () => {
  it("l'en-tête compte les domiciles sans gymnase (« N sans gymnase »)", async () => {
    renderPage([fx("team-1", "NEW", "2026-11-07", { fbiVenueLabel: "GYMNASE MATEO" })]);
    expect(await screen.findByRole("button", { name: /SM1 · 1 à valider · 1 sans gymnase/ })).toBeInTheDocument();
  });

  it("Confirmer poste le libellé BRUT sur le gymnase choisi + toast succès nommant le gymnase", async () => {
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07", { fbiVenueLabel: "GYMNASE MATEO", suggestedVenueId: "venue-1" })], "/matchs/importer?equipe=team-1");
    await user.click(await screen.findByRole("button", { name: "Rattacher" }));
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    expect(attachVenueLabel).toHaveBeenCalledWith({ venueId: "venue-1", label: "GYMNASE MATEO" });
    await waitFor(() => {
      const messages = useToastStore.getState().toasts.map((t) => t.message);
      expect(messages.some((m) => m.includes("Gymnase Alpha") && m.includes("2 domiciles"))).toBe(true);
    });
  });

  it("un 422 nommé du serveur est affiché TEL QUEL (message serveur, pas un repli)", async () => {
    attachVenueLabel.mockRejectedValueOnce(httpError(422, { error: "Ce libellé est déjà porté par un autre gymnase. Retirez-le d'abord." }));
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07", { fbiVenueLabel: "GYMNASE MATEO", suggestedVenueId: "venue-1" })], "/matchs/importer?equipe=team-1");
    await user.click(await screen.findByRole("button", { name: "Rattacher" }));
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    await waitFor(() => {
      const messages = useToastStore.getState().toasts.map((t) => t.message);
      expect(messages.some((m) => m.includes("déjà porté par un autre gymnase"))).toBe(true);
    });
  });
});

describe("ImportPage — Vérifier via l'API FFBB (D3, cas a/b/c)", () => {
  it("(a) des rencontres à créer → payload API porté + navigation vers la vue d'intégration", async () => {
    getFfbbRencontres.mockResolvedValueOnce({
      deviations: [],
      creatable: [{ rencontreId: "renc-a", competitionNom: "AMICAL", date: "2026-09-23", kickoff: "20:00", homeAway: "HOME", opponentLabel: "BRON", venueLabel: null, numeroJournee: null, suggestedTeamId: null }],
      fetchedAt: "2026-08-24T14:05:00+00:00",
    });
    const user = userEvent.setup();
    renderPage([]);
    await user.click(await screen.findByRole("button", { name: /Vérifier via l'API FFBB/i }));
    await waitFor(() => expect(getFfbbRencontres).toHaveBeenCalledOnce());
    const carried = useMatchesStore.getState().reconciliation;
    expect(carried?.channel).toBe("api");
    expect(await screen.findByText("RECONCILIATION")).toBeInTheDocument();
    // FALSIFICATION — aucun apply dans le cas « à créer » (la vue applique).
    expect(applyFfbbRencontres).not.toHaveBeenCalled();
  });

  it("(b) que des écarts → apply sans création (les consigne dans la file)", async () => {
    getFfbbRencontres.mockResolvedValueOnce({
      deviations: [{ fixtureId: "fx-1", externalRef: "1", division: "DF2", teamId: "team-1", status: "PLACED", persisting: false, fields: { date: { app: "a", file: "b" } } }],
      creatable: [],
      fetchedAt: "2026-08-24T14:05:00+00:00",
    });
    const user = userEvent.setup();
    renderPage([]);
    await user.click(await screen.findByRole("button", { name: /Vérifier via l'API FFBB/i }));
    await waitFor(() => expect(applyFfbbRencontres).toHaveBeenCalledWith([], []));
    // Pas de navigation vers la vue d'intégration (rien à créer).
    expect(screen.queryByText("RECONCILIATION")).not.toBeInTheDocument();
  });

  it("(c) rien à faire → aucun apply, on ne date pas un dépôt pour rien", async () => {
    getFfbbRencontres.mockResolvedValueOnce({ deviations: [], creatable: [], fetchedAt: "2026-08-24T14:05:00+00:00" });
    const user = userEvent.setup();
    renderPage([]);
    await user.click(await screen.findByRole("button", { name: /Vérifier via l'API FFBB/i }));
    await waitFor(() => expect(getFfbbRencontres).toHaveBeenCalledOnce());
    expect(applyFfbbRencontres).not.toHaveBeenCalled();
    expect(useMatchesStore.getState().reconciliation).toBeNull();
    const messages = useToastStore.getState().toasts.map((t) => t.message);
    expect(messages.some((m) => /en phase/i.test(m))).toBe(true);
  });
});
