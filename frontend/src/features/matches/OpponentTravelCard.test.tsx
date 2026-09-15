import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { OpponentTravel } from "./api";
import { OpponentTravelCard } from "./OpponentTravelCard";

const resolveMutate = vi.fn();
const revertMutate = vi.fn();
const travelState: { data: OpponentTravel[]; isError: boolean } = { data: [], isError: false };

vi.mock("./queries", () => ({
  useOpponentTravel: () => ({ data: travelState.data, isError: travelState.isError, refetch: vi.fn() }),
  useResolveOpponentTravel: () => ({ mutate: resolveMutate, isPending: false }),
  useSetOpponentTravelAuto: () => ({ mutate: revertMutate, isPending: false }),
  // The modal (opened by « Localiser ») needs these on the mock — never invoked here.
  useVenueSuggestions: () => ({ data: undefined, isError: false, refetch: vi.fn() }),
  useFfbbSalles: () => ({ data: undefined, isError: false, refetch: vi.fn() }),
  useSetOpponentTravelManual: () => ({ mutate: vi.fn(), isPending: false }),
}));

const opp = (over: Partial<OpponentTravel> & { opponentLabel: string }): OpponentTravel => ({
  opponentOrganismeCode: "C1",
  opponentTeamKey: over.opponentLabel.toUpperCase(),
  located: true,
  precision: "VENUE",
  locationName: "Halle X",
  city: null,
  postalCode: null,
  travelMinutes: 20,
  approximated: false,
  source: "AUTO",
  scope: "CLUB",
  overrideVenueLabel: null,
  ...over,
});

beforeEach(() => {
  resolveMutate.mockReset();
  revertMutate.mockReset();
  travelState.data = [];
  travelState.isError = false;
});

describe("OpponentTravelCard — l'écran SET-UP du trajet adverse, GROUPÉ PAR CLUB (PR-3)", () => {
  it("regroupe deux équipes d'un même organisme sous UN en-tête de club dérivé + compteur", () => {
    travelState.data = [
      opp({ opponentLabel: "BASKET BALL 5EME - 1", opponentTeamKey: "BB5-1", scope: "CLUB" }),
      opp({ opponentLabel: "BASKET BALL 5EME - 2", opponentTeamKey: "BB5-2", scope: "TEAM", source: "MANUAL" }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    // En-tête club = libellé de la première équipe MOINS le suffixe « - n » (affichage).
    expect(screen.getByRole("heading", { name: "BASKET BALL 5EME", level: 4 })).toBeInTheDocument();
    expect(screen.getByText("2 équipes")).toBeInTheDocument();
    // La ligne défaut est en tête, puis les libellés BRUTS entiers des deux équipes.
    expect(screen.getByText("Toutes les équipes (défaut)")).toBeInTheDocument();
    expect(screen.getByText("BASKET BALL 5EME - 1")).toBeInTheDocument();
    expect(screen.getByText("BASKET BALL 5EME - 2")).toBeInTheDocument();
  });

  it("pastille « défaut du club » sur une équipe CLUB/null localisée, jamais sur une équipe TEAM", () => {
    travelState.data = [
      opp({ opponentLabel: "Team CLUB", opponentTeamKey: "T-CLUB", scope: "CLUB" }),
      opp({ opponentLabel: "Team TEAM", opponentTeamKey: "T-TEAM", scope: "TEAM", source: "MANUAL" }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    // La pastille de grain paraît pour l'équipe gouvernée par le club (une occurrence).
    expect(screen.getAllByText("défaut du club")).toHaveLength(1);
    // L'équipe TEAM porte le retour au défaut ; l'équipe CLUB non.
    expect(screen.getByRole("button", { name: "Revenir au défaut du club pour Team TEAM" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Revenir au défaut du club pour Team CLUB" })).not.toBeInTheDocument();
  });

  it("le retour au défaut d'une ÉQUIPE poste auto avec son teamKey (supprime la surcharge)", async () => {
    travelState.data = [opp({ opponentLabel: "Team TEAM", opponentTeamKey: "T-TEAM", scope: "TEAM", source: "MANUAL" })];
    renderWithProviders(<OpponentTravelCard />);

    await userEvent.click(screen.getByRole("button", { name: "Revenir au défaut du club pour Team TEAM" }));
    expect(revertMutate.mock.calls[0][0]).toEqual({ opponentOrganismeCode: "C1", opponentTeamKey: "T-TEAM" });
  });

  it("« Rétablir l'automatique » sur la ligne club n'apparaît que si le défaut club est MANUEL → auto sans teamKey", async () => {
    travelState.data = [opp({ opponentLabel: "Team CLUB", opponentTeamKey: "T-CLUB", scope: "CLUB", source: "MANUAL", overrideVenueLabel: "Le vrai gymnase" })];
    renderWithProviders(<OpponentTravelCard />);

    const revert = screen.getByRole("button", { name: "Rétablir l'automatique pour Team CLUB, toutes les équipes" });
    await userEvent.click(revert);
    expect(revertMutate.mock.calls[0][0]).toEqual({ opponentOrganismeCode: "C1" });
  });

  it("une entrée SANS code fédéral : une ligne club à part, sans bouton Localiser", () => {
    travelState.data = [
      opp({ opponentLabel: "Perdu FC", opponentOrganismeCode: null, opponentTeamKey: null, located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByText(/code fédéral non résolu — relancez la localisation/)).toBeInTheDocument();
    // La reprise passe par « Recalculer les trajets » en tête, jamais un « Localiser » ici.
    expect(screen.queryByRole("button", { name: /^Localiser/ })).not.toBeInTheDocument();
  });

  it("tri : le club portant une équipe non localisée passe AVANT un club tout localisé", () => {
    travelState.data = [
      opp({ opponentOrganismeCode: "Z", opponentLabel: "Zebre", opponentTeamKey: "ZEBRE", located: true }),
      opp({ opponentOrganismeCode: "A", opponentLabel: "Alpha", opponentTeamKey: "ALPHA", located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    const headings = screen.getAllByRole("heading", { level: 4 }).map((h) => h.textContent);
    // Alpha (non localisé) d'abord malgré l'ordre alphabétique inverse du service.
    expect(headings).toEqual(["Alpha", "Zebre"]);
  });

  it("des non localisés → une PHRASE warning (jamais l'ancien encart), le nombre d'équipes", () => {
    travelState.data = [
      opp({ opponentOrganismeCode: "A", opponentLabel: "Alpha", opponentTeamKey: "ALPHA", located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null }),
      opp({ opponentOrganismeCode: "B", opponentLabel: "Beta", opponentTeamKey: "BETA", located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByText("2 équipes adverses sans gymnase — leurs matchs n'entrent pas dans le radar.")).toBeInTheDocument();
  });

  it("recalcule tous les trajets à la demande", async () => {
    travelState.data = [opp({ opponentLabel: "Voisin FC", opponentTeamKey: "VOISIN" })];
    renderWithProviders(<OpponentTravelCard />);

    await userEvent.click(screen.getByRole("button", { name: /Recalculer les trajets/ }));
    expect(resolveMutate).toHaveBeenCalledTimes(1);
  });

  // ── TÉMOIN GARDÉ (ex OpponentTravelCard.test.tsx:84-88) : tout localisé → ton calme ─────
  it("tout localisé → un ton calme, jamais la phrase d'alerte", () => {
    travelState.data = [opp({ opponentLabel: "Voisin FC", opponentTeamKey: "VOISIN" })];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByText(/Tous vos adversaires sont localisés/)).toBeInTheDocument();
    expect(screen.queryByText(/sans gymnase — leurs matchs/)).not.toBeInTheDocument();
  });

  it("ouvre la modale de localisation depuis une ligne équipe (bouton Localiser nommé sur le libellé brut)", async () => {
    travelState.data = [opp({ opponentLabel: "Team A", opponentTeamKey: "T-A", scope: "CLUB" })];
    renderWithProviders(<OpponentTravelCard />);

    await userEvent.click(screen.getByRole("button", { name: "Localiser Team A" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Localiser Team A")).toBeInTheDocument();
  });
});
