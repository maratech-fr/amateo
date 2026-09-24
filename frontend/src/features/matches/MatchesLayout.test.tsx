import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MatchesLayout } from "./MatchesLayout";
import { useMatchesStore } from "./store";

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
// PR-3b — les rencontres nourrissent le badge de l'onglet Importer (pendingReviewCount).
// Mutable par test pour piloter le compte (NEW/OUT_OF_SYNC/REVIEWED) et l'échec.
const fixturesState = vi.hoisted(() => ({ rows: [] as { reviewState: string; pendingDeviations: { autoApplied: boolean }[] }[], fail: false }));
// P4-207 — les conflits nourrissent le badge de l'onglet Conflits (openConflictCount). Mutable
// par test pour piloter le compte à traiter.
const conflictsState = vi.hoisted(() => ({ rows: [] as { type: string; severity: number; resolution: unknown }[] }));
vi.mock("./api", () => ({
  postModuleVisit: vi.fn(() => {
    visit.count += 1;
    return Promise.resolve({ firstVisit: true, newFixturesCount: 0, newConflictFingerprints: [], planningChanged: false, referenceTakenAt: "2026-08-24T10:00:00+00:00" });
  }),
  getFixtures: vi.fn(() => (fixturesState.fail ? Promise.reject(new Error("boom")) : Promise.resolve(fixturesState.rows))),
  getConflicts: vi.fn(() => Promise.resolve({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: conflictsState.rows })),
}));

beforeEach(() => {
  visit.count = 0;
  fixturesState.rows = [];
  fixturesState.fail = false;
  conflictsState.rows = [];
});

function renderAt(path: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/matchs" element={<MatchesLayout />}>
            <Route index element={<div>BOUCLE</div>} />
            <Route path="consulter" element={<div>CONSULTER</div>} />
            <Route path="importer" element={<div>IMPORTER</div>} />
            <Route path="configuration" element={<div>CONFIG</div>} />
            <Route path="semaine-type" element={<div>SEMAINE_TYPE</div>} />
            <Route path="conflits" element={<div>CONFLITS</div>} />
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

  it("porte les SIX onglets, dans l'ordre Conflits · Calendrier · Importer · Configuration · Adversaires · Semaine type (C8)", () => {
    meState.chosen = "s1";
    renderAt("/matchs");
    const nav = screen.getByRole("navigation", { name: "Espaces matchs" });
    const labels = within(nav)
      .getAllByRole("link")
      .map((l) => l.textContent);
    expect(labels).toEqual(["Conflits", "Calendrier", "Importer", "Configuration", "Adversaires", "Semaine type"]);
    // Plus aucun onglet « Consulter » ni « Semaine » (fusionnés dans « Calendrier »).
    expect(labels).not.toContain("Consulter");
    expect(labels).not.toContain("Semaine");
  });

  it("la nav défile horizontalement (overflow-x-auto, ni flex-wrap ni scrollbar-hide)", () => {
    meState.chosen = "s1";
    renderAt("/matchs");
    const nav = screen.getByRole("navigation", { name: "Espaces matchs" });
    expect(nav).toHaveClass("overflow-x-auto");
    expect(nav.className).not.toContain("flex-wrap");
    expect(nav.className).not.toContain("scrollbar-hide");
  });

  it("porte l'onglet « Semaine type » et rend sa page (PR 2a)", async () => {
    meState.chosen = "s1";
    const user = userEvent.setup();
    renderAt("/matchs");
    await user.click(screen.getByRole("link", { name: "Semaine type" }));
    expect(screen.getByText("SEMAINE_TYPE")).toBeInTheDocument();
  });

  it("porte l'onglet Conflits et rend l'espace Conflits (PR A)", async () => {
    meState.chosen = "s1";
    const user = userEvent.setup();
    renderAt("/matchs");
    await user.click(screen.getByRole("link", { name: "Conflits" }));
    expect(screen.getByText("CONFLITS")).toBeInTheDocument();
  });

  it("porte l'onglet Calendrier (index /matchs) et rend l'écran unique (PR 3b)", async () => {
    meState.chosen = "s1";
    const user = userEvent.setup();
    renderAt("/matchs/configuration");
    const calendrierLink = screen.getByRole("link", { name: "Calendrier" });
    expect(calendrierLink).toBeInTheDocument();
    await user.click(calendrierLink);
    expect(screen.getByText("BOUCLE")).toBeInTheDocument();
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

describe("MatchesLayout — le badge de l'onglet Importer (PR-3b)", () => {
  it("affiche le compte quand des rencontres restent à traiter (« Importer · N »)", async () => {
    meState.chosen = "s1";
    // NEW + OUT_OF_SYNC + une REVIEWED à alerte auto-appliquée comptent ; une REVIEWED
    // sans alerte ne compte pas (P4-199) → 3.
    fixturesState.rows = [
      { reviewState: "NEW", pendingDeviations: [] },
      { reviewState: "OUT_OF_SYNC", pendingDeviations: [{ autoApplied: false }] },
      { reviewState: "REVIEWED", pendingDeviations: [{ autoApplied: true }] },
      { reviewState: "REVIEWED", pendingDeviations: [] },
    ];
    renderAt("/matchs");
    // Le compte arrive après le fetch (data absente au premier rendu).
    expect(await screen.findByRole("link", { name: "Importer · 3" })).toBeInTheDocument();
  });

  it("aucun compte quand tout est traité — jamais « Importer · 0 »", async () => {
    meState.chosen = "s1";
    fixturesState.rows = [{ reviewState: "REVIEWED", pendingDeviations: [] }];
    renderAt("/matchs");
    await waitFor(() => expect(visit.count).toBe(1)); // laisse le fetch se poser
    expect(screen.getByRole("link", { name: "Importer" })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Importer · / })).not.toBeInTheDocument();
  });

  it("aucun compte en cas d'échec de lecture (pas de « · » sur données absentes)", async () => {
    meState.chosen = "s1";
    fixturesState.fail = true;
    renderAt("/matchs");
    await waitFor(() => expect(visit.count).toBe(1));
    expect(screen.getByRole("link", { name: "Importer" })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Importer · / })).not.toBeInTheDocument();
  });
});

describe("MatchesLayout — le badge de l'onglet Conflits (P4-207)", () => {
  it("affiche le compte À TRAITER (« Conflits · N »), l'annoté ne pèse pas", async () => {
    meState.chosen = "s1";
    conflictsState.rows = [
      { type: "VENUE_OVERLAP", severity: 1, resolution: null },
      { type: "MATCH_MATCH", severity: 3, resolution: null },
      // Annoté → ne compte pas.
      { type: "MATCH_TRAINING", severity: 5, resolution: { status: "DEROGATION_REQUESTED", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } },
    ];
    renderAt("/matchs");
    expect(await screen.findByRole("link", { name: "Conflits · 2" })).toBeInTheDocument();
  });

  it("aucun compte quand tout est traité — jamais « Conflits · 0 »", async () => {
    meState.chosen = "s1";
    conflictsState.rows = [{ type: "VENUE_OVERLAP", severity: 1, resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } }];
    renderAt("/matchs");
    await waitFor(() => expect(visit.count).toBe(1));
    expect(screen.getByRole("link", { name: "Conflits" })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Conflits · / })).not.toBeInTheDocument();
  });
});

describe("MatchesLayout — entrer dans le module consomme la règle d'atterrissage (UXS-07)", () => {
  // La décision d'atterrissage est un état de session : les autres tests montent le layout
  // (qui la marque désormais), il faut donc la remettre à zéro avant chacun de ceux-ci.
  beforeEach(() => {
    useMatchesStore.setState({ landingDecided: false });
  });

  it("marque la décision au montage quand le socle est validé (entrée par la Configuration)", async () => {
    meState.chosen = "s1";
    renderAt("/matchs/configuration");
    // Attendre le POST de visite laisse TOUTES les lectures se poser dans act ; l'effet de
    // marquage a couru au montage.
    await waitFor(() => expect(visit.count).toBe(1));
    expect(useMatchesStore.getState().landingDecided).toBe(true);
  });

  it("entrer par la Configuration puis cliquer « Calendrier » n'emmène PAS sur Conflits", async () => {
    meState.chosen = "s1";
    const user = userEvent.setup();
    renderAt("/matchs/configuration");
    await waitFor(() => expect(visit.count).toBe(1));
    expect(useMatchesStore.getState().landingDecided).toBe(true);
    // Le harnais rend « BOUCLE » à l'index ; combiné au cas « décision déjà prise →
    // Calendrier immédiat » de MatchesLanding, la règle « pas de renvoi forcé » est prouvée.
    await user.click(screen.getByRole("link", { name: "Calendrier" }));
    expect(screen.getByText("BOUCLE")).toBeInTheDocument();
  });

  it("ne marque RIEN sur un module verrouillé (pas de socle pointé)", async () => {
    meState.chosen = null;
    renderAt("/matchs/configuration");
    // Le garde socle court-circuite l'Outlet ET l'effet de marquage (gaté comme la visite).
    await waitFor(() => expect(screen.getByRole("heading", { name: "Matchs verrouillés" })).toBeInTheDocument());
    expect(useMatchesStore.getState().landingDecided).toBe(false);
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
    await user.click(screen.getByRole("link", { name: "Calendrier" }));
    expect(screen.getByText("BOUCLE")).toBeInTheDocument();

    await new Promise((r) => setTimeout(r, 50));
    expect(visit.count).toBe(1);
  });
});
