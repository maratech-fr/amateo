import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useSearchParams } from "react-router";

import { renderWithProviders } from "@/test/utils";

import { ConfigurationPage } from "./ConfigurationPage";

// P4-185 — une section = un écran : les 6 cartes sont devenues 6 accordéons
// CONTRÔLÉS, un seul ouvert à la fois, ancré `?section=`. On mute la couche api
// (PROD) et react-query tourne pour de vrai ; « pending » = promesse pendante pour
// exercer le régime chargement (en-tête SANS compte).
const state: Record<string, unknown[] | "pending"> = {
  teams: [],
  tiers: [],
  venues: [],
  competitions: [],
  habits: [],
  rotations: [],
  durations: [],
  fixtures: [],
  travel: [],
};

function serve(key: string): Promise<unknown> {
  const value = state[key];
  return "pending" === value ? new Promise<unknown>(() => {}) : Promise.resolve(value);
}

vi.mock("./api", () => ({
  getTeams: () => serve("teams"),
  getPriorityTiers: () => serve("tiers"),
  getVenues: () => serve("venues"),
  getCompetitions: () => serve("competitions"),
  getTeamMatchHabits: () => serve("habits"),
  getMatchSlotRotations: () => serve("rotations"),
  getSportCategoryDurations: () => serve("durations"),
  getFixtures: () => serve("fixtures"),
  getOpponentTravel: () => serve("travel"),
  updateSportCategoryDuration: vi.fn(),
  createMatchSlotRotation: vi.fn(),
  updateMatchSlotRotation: vi.fn(),
  deleteMatchSlotRotation: vi.fn(),
  resolveOpponentTravel: vi.fn(),
  setOpponentTravelAuto: vi.fn(),
  setOpponentTravelManual: vi.fn(),
}));

// Sonde d'URL : lit `?section=` pour prouver ce que la page écrit.
function SectionProbe() {
  const [params] = useSearchParams();
  return <span data-testid="section-param">{params.get("section") ?? "(absent)"}</span>;
}

function Harness() {
  return (
    <>
      <ConfigurationPage />
      <SectionProbe />
    </>
  );
}

beforeEach(() => {
  state.teams = [{ id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 }];
  state.tiers = [{ id: 3, label: "B", name: "Moyenne", color: null }];
  state.venues = [{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00", externalLabels: [] }];
  state.competitions = [];
  state.habits = [];
  state.rotations = [];
  state.durations = [];
  state.fixtures = [];
  state.travel = [];
});

describe("ConfigurationPage (P4-185 — une section = un écran)", () => {
  it("à l'arrivée : seul le gabarit est ouvert, les 5 autres repliés (« Accès match » absent du DOM)", async () => {
    renderWithProviders(<Harness />);
    // Le gabarit est ouvert : son contenu (image A/B) est présent.
    expect(await screen.findByText(/Aucune habitude déclarée/)).toBeInTheDocument();
    // L'en-tête du gabarit est déplié…
    expect(screen.getByRole("button", { name: "Le gabarit idéal — la semaine type" })).toHaveAttribute("aria-expanded", "true");
    // …et une section sœur est repliée.
    expect(screen.getByRole("button", { name: "Réglages de saison" })).toHaveAttribute("aria-expanded", "false");
    // Le contenu des sections repliées n'est PAS monté.
    expect(screen.queryByRole("button", { name: "Accès match" })).not.toBeInTheDocument();
  });

  it("un seul ouvert à la fois : ouvrir « Réglages de saison » replie le gabarit", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />);
    await screen.findByText(/Aucune habitude déclarée/);
    await user.click(screen.getByRole("button", { name: "Réglages de saison" }));
    // Le contenu des Réglages apparaît…
    expect(screen.getByRole("button", { name: "Accès match" })).toBeInTheDocument();
    // …et celui du gabarit a disparu (démontage au repli).
    expect(screen.queryByText(/Aucune habitude déclarée/)).not.toBeInTheDocument();
  });

  it("deep-link ?section=reglages : les réglages sont ouverts à l'arrivée", async () => {
    renderWithProviders(<Harness />, { route: "/matchs/configuration?section=reglages" });
    expect(await screen.findByRole("button", { name: "Accès match" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Habitudes & passerelles" })).toBeInTheDocument();
    // Le gabarit reste replié.
    expect(screen.queryByText(/Aucune habitude déclarée/)).not.toBeInTheDocument();
  });

  it("ouvrir « Échéances de saisie » monte son éditeur", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />);
    await user.click(await screen.findByRole("button", { name: /^Échéances de saisie/ }));
    expect(await screen.findByRole("heading", { name: "Échéances de saisie", level: 3 })).toBeInTheDocument();
    expect(screen.getByText(/Aucune compétition/i)).toBeInTheDocument();
  });

  it("l'en-tête porte un résumé calculé à partir des données servies", async () => {
    state.rotations = [
      { id: "r1", venueId: "venue-1", dayOfWeek: 6, kickoffTime: "20:30", teamIds: ["team-1", "team-2"] },
      { id: "r2", venueId: "venue-1", dayOfWeek: 5, kickoffTime: "18:00", teamIds: ["team-1", "team-3"] },
    ];
    state.competitions = [
      { id: "c1", teamId: "team-1", name: "PNM", competitionType: "championnat", effectiveEntryDeadline: "2026-10-01" },
      { id: "c2", teamId: "team-1", name: "RM2", competitionType: "championnat", effectiveEntryDeadline: null },
    ];
    state.travel = [
      { opponentOrganismeCode: "A", opponentLabel: "Adv A", located: false, precision: null, locationName: null, travelMinutes: null, approximated: false, source: null, overrideVenueLabel: null },
      { opponentOrganismeCode: "B", opponentLabel: "Adv B", located: true, precision: null, locationName: null, travelMinutes: null, approximated: false, source: null, overrideVenueLabel: null },
    ];
    renderWithProviders(<Harness />);

    expect(await screen.findByRole("button", { name: "Créneaux partagés (alternance) · 2 rotations" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Échéances de saisie · 1 renseignée sur 2 compétitions" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Adversaires à localiser · 1 à localiser sur 2" })).toBeInTheDocument();
  });

  it("en chargement (promesse pendante) : l'en-tête n'affiche AUCUN compte — jamais un « 0 » fabriqué", async () => {
    state.rotations = "pending";
    state.competitions = "pending";
    state.durations = "pending";
    state.travel = "pending";
    renderWithProviders(<Harness />);
    // L'en-tête existe tout de suite, SANS résumé.
    expect(await screen.findByRole("button", { name: "Créneaux partagés (alternance)" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Adversaires à localiser" })).toBeInTheDocument();
    // Aucune variante « · N rotations » n'est présente.
    expect(screen.queryByRole("button", { name: /rotation/ })).not.toBeInTheDocument();
  });

  it("replier la section ouverte écrit `section=aucune` (tout replié) — sinon le défaut rouvrirait le gabarit", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />);
    await screen.findByText(/Aucune habitude déclarée/);
    expect(screen.getByTestId("section-param")).toHaveTextContent("(absent)");
    await user.click(screen.getByRole("button", { name: "Le gabarit idéal — la semaine type" }));
    // Tout est replié…
    expect(screen.queryByText(/Aucune habitude déclarée/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Accès match" })).not.toBeInTheDocument();
    // …et l'URL le consigne.
    expect(screen.getByTestId("section-param")).toHaveTextContent("aucune");
  });

  // ── P4-186 (rappel) — les DONNÉES FBI/FFBB ne sont PAS dans la Configuration ──
  it("ne porte PLUS les données FBI/FFBB (migrées dans Importer)", async () => {
    renderWithProviders(<Harness />);
    await screen.findByText(/Aucune habitude déclarée/);
    expect(screen.queryByRole("button", { name: "Engagements FFBB" })).not.toBeInTheDocument();
    expect(screen.queryByText(/Dépôt saisonnier FBI/i)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Importer FBI/ })).not.toBeInTheDocument();
  });
});
