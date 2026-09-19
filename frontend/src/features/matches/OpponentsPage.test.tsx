import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Fixture, OpponentTravel } from "./api";
import { OpponentsPage } from "./OpponentsPage";

const revertMutate = vi.fn();
const retryMutate = vi.fn();
const updateRun = vi.fn();
const travelState: { data: OpponentTravel[]; isError: boolean } = { data: [], isError: false };
const fixturesState: { data: Fixture[] } = { data: [] };
const updateState: { step: "idle" | "running" } = { step: "idle" };
const geolocatedState: { value: boolean } = { value: true };

vi.mock("./queries", () => ({
  useOpponentTravel: () => ({ data: travelState.data, isError: travelState.isError, refetch: vi.fn() }),
  useClubGeolocated: () => geolocatedState.value,
  useFixtures: () => ({ data: fixturesState.data }),
  useUpdateOpponents: () => ({ run: updateRun, isPending: "idle" !== updateState.step, step: updateState.step }),
  useSetOpponentTravelAuto: () => ({ mutate: revertMutate, isPending: false }),
  useResolveOpponentTravel: () => ({ mutate: retryMutate, isPending: false }),
  // The modal (opened by « Localiser ») needs these on the mock.
  useVenueSuggestions: () => ({ data: undefined, isError: false, refetch: vi.fn() }),
  useFfbbSalles: () => ({ data: undefined, isError: false, refetch: vi.fn() }),
  useSetOpponentTravelManual: () => ({ mutate: vi.fn(), isPending: false }),
}));

// Le flux Mercure ne pilote rien dans ces tests (on injecte `travelStatus` directement).
vi.mock("@/shared/lib/travelStream", () => ({ useTravelStream: () => ({ connected: false, latest: null }) }));

const opp = (over: Partial<OpponentTravel> & { opponentLabel: string }): OpponentTravel => ({
  opponentOrganismeCode: "C1",
  opponentTeamKey: over.opponentLabel.toUpperCase(),
  located: true,
  hasLogo: false,
  precision: "VENUE",
  locationName: "Halle X",
  city: "Lyon",
  postalCode: null,
  travelMinutes: 20,
  approximated: false,
  source: "AUTO",
  scope: "CLUB",
  overrideVenueLabel: null,
  travelStatus: "done",
  ...over,
});

beforeEach(() => {
  revertMutate.mockReset();
  retryMutate.mockReset();
  updateRun.mockReset();
  travelState.data = [];
  travelState.isError = false;
  fixturesState.data = [];
  updateState.step = "idle";
  geolocatedState.value = true;
});

describe("OpponentsPage — la liste par club (C8, tableau tbody-par-club)", () => {
  it("porte le titre, un `th scope=row` (rowheader) par club, une ligne défaut + une ligne par équipe", () => {
    travelState.data = [
      opp({ opponentLabel: "BASKET BALL 5EME - 1", opponentTeamKey: "BB5-1", scope: "CLUB" }),
      opp({ opponentLabel: "BASKET BALL 5EME - 2", opponentTeamKey: "BB5-2", scope: "TEAM", source: "MANUAL" }),
    ];
    renderWithProviders(<OpponentsPage />);

    expect(screen.getByRole("heading", { name: "Adversaires", level: 2 })).toBeInTheDocument();
    // Le club est un en-tête de LIGNE (`th scope=row`) → role rowheader, avec « Toutes les équipes ».
    const rowheader = screen.getByRole("rowheader", { name: /BASKET BALL 5EME/ });
    expect(rowheader).toHaveTextContent("Toutes les équipes (défaut)");
    expect(screen.getByText("BASKET BALL 5EME - 1")).toBeInTheDocument();
    expect(screen.getByText("BASKET BALL 5EME - 2")).toBeInTheDocument();
  });

  it("tri : le club portant une équipe SANS trajet passe AVANT un club tout calculé", () => {
    travelState.data = [
      opp({ opponentOrganismeCode: "Z", opponentLabel: "Zebre", opponentTeamKey: "ZEBRE", travelMinutes: 30, travelStatus: "done" }),
      opp({ opponentOrganismeCode: "A", opponentLabel: "Alpha", opponentTeamKey: "ALPHA", located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null, travelStatus: "unavailable" }),
    ];
    renderWithProviders(<OpponentsPage />);

    const rowheaders = screen.getAllByRole("rowheader").map((h) => h.textContent);
    expect(rowheaders[0]).toContain("Alpha");
    expect(rowheaders[1]).toContain("Zebre");
  });

  it("les colonnes « Trajet » et « Rencontres » se replient sous @md (hidden @md:table-cell)", () => {
    travelState.data = [opp({ opponentLabel: "Team A", opponentTeamKey: "TA" })];
    renderWithProviders(<OpponentsPage />);

    const trajet = screen.getByRole("columnheader", { name: "Trajet" });
    const rencontres = screen.getByRole("columnheader", { name: "Rencontres" });
    expect(trajet.className).toContain("hidden");
    expect(trajet.className).toContain("@md:table-cell");
    expect(rencontres.className).toContain("hidden");
    expect(rencontres.className).toContain("@md:table-cell");
  });

  it("le filtre segmenté a exactement UN `aria-pressed` (« Tous » par défaut) + compteurs", () => {
    travelState.data = [
      opp({ opponentLabel: "Localisée", opponentTeamKey: "L1", located: true }),
      opp({ opponentOrganismeCode: "C2", opponentLabel: "Sans gym", opponentTeamKey: "SG", located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null, travelStatus: "unavailable" }),
      opp({ opponentOrganismeCode: "C3", opponentLabel: "Ville seule", opponentTeamKey: "VS", located: true, precision: "CITY", travelStatus: "done" }),
    ];
    renderWithProviders(<OpponentsPage />);

    const group = screen.getByRole("group", { name: "Filtrer les adversaires" });
    const pressed = within(group).getAllByRole("button", { pressed: true });
    expect(pressed).toHaveLength(1);
    expect(pressed[0]).toHaveTextContent("Tous");
    expect(within(group).getByRole("button", { name: /À localiser/ })).toHaveTextContent("(1)");
    expect(within(group).getByRole("button", { name: /Gymnase à préciser/ })).toHaveTextContent("(1)");
  });

  it("« À localiser » ne garde que les clubs à équipe non localisée, et bascule l'aria-pressed", async () => {
    travelState.data = [
      opp({ opponentOrganismeCode: "OK", opponentLabel: "Localisée", opponentTeamKey: "L1", located: true }),
      opp({ opponentOrganismeCode: "KO", opponentLabel: "Sans gym", opponentTeamKey: "SG", located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null, travelStatus: "unavailable" }),
    ];
    renderWithProviders(<OpponentsPage />);

    await userEvent.click(screen.getByRole("button", { name: /À localiser/ }));
    expect(screen.getByRole("rowheader", { name: /Sans gym/ })).toBeInTheDocument();
    expect(screen.queryByRole("rowheader", { name: /Localisée/ })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /À localiser/ })).toHaveAttribute("aria-pressed", "true");
  });
});

describe("OpponentsPage — la progression du calcul (C6, dérivée de travelStatus)", () => {
  it("un calcul en cours → région live « en cours… », bouton « Mettre à jour » DÉSACTIVÉ, aucun spinner de ligne", () => {
    travelState.data = [
      opp({ opponentLabel: "En calcul", opponentTeamKey: "EC", located: true, travelMinutes: null, travelStatus: "pending" }),
      opp({ opponentOrganismeCode: "C2", opponentLabel: "Faite", opponentTeamKey: "F1", located: true, travelStatus: "done" }),
    ];
    renderWithProviders(<OpponentsPage />);

    expect(screen.getByRole("status")).toHaveTextContent("Calcul des trajets en cours…");
    expect(screen.getByText("1 / 2 trajets calculés")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Mettre à jour les adversaires/ })).toBeDisabled();
    // Pas de spinner par ligne : « en cours… » est un simple texte, aucun aria-label « Chargement ».
    expect(screen.getAllByText("en cours…").length).toBeGreaterThan(0);
    expect(screen.queryByLabelText(/Chargement/i)).not.toBeInTheDocument();
  });

  it("tout calculé → région live « Trajets calculés. », PAS de bannière « Réessayer »", () => {
    travelState.data = [opp({ opponentLabel: "Faite", opponentTeamKey: "F1", located: true, travelStatus: "done" })];
    renderWithProviders(<OpponentsPage />);

    expect(screen.getByRole("status")).toHaveTextContent("Trajets calculés.");
    expect(screen.queryByRole("button", { name: "Réessayer les manquants" })).not.toBeInTheDocument();
  });

  it("échec partiel (localisé mais sans minutes, hors calcul) → bannière + « Réessayer les manquants »", async () => {
    travelState.data = [
      opp({ opponentLabel: "Ratée", opponentTeamKey: "R1", located: true, travelMinutes: null, travelStatus: "unavailable" }),
      opp({ opponentOrganismeCode: "C2", opponentLabel: "Faite", opponentTeamKey: "F1", located: true, travelStatus: "done" }),
    ];
    renderWithProviders(<OpponentsPage />);

    expect(screen.getByRole("status")).toHaveTextContent("Trajets calculés — 1 indisponibles.");
    const retry = screen.getByRole("button", { name: "Réessayer les manquants" });
    await userEvent.click(retry);
    expect(retryMutate).toHaveBeenCalledTimes(1);
  });

  it("siège non localisé → bandeau « Renseigner le siège », pas de progression", () => {
    geolocatedState.value = false;
    travelState.data = [opp({ opponentLabel: "Team", opponentTeamKey: "T1" })];
    renderWithProviders(<OpponentsPage />);

    expect(screen.getByRole("link", { name: "Renseigner le siège" })).toBeInTheDocument();
    expect(screen.queryByText(/trajets calculés/)).not.toBeInTheDocument();
  });
});
