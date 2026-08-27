import { screen } from "@testing-library/react";
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
  // The modal is not opened in these tests, but its hooks must exist on the mock.
  useFfbbSalles: () => ({ data: undefined, isError: false, refetch: vi.fn() }),
  useSetOpponentTravelManual: () => ({ mutate: vi.fn(), isPending: false }),
}));

const opp = (over: Partial<OpponentTravel> & { opponentLabel: string }): OpponentTravel => ({
  opponentOrganismeCode: "ORG-" + over.opponentLabel,
  located: true,
  precision: "VENUE",
  locationName: "Halle X",
  travelMinutes: 20,
  approximated: false,
  source: "AUTO",
  overrideVenueLabel: null,
  ...over,
});

beforeEach(() => {
  resolveMutate.mockReset();
  revertMutate.mockReset();
  travelState.data = [];
  travelState.isError = false;
});

describe("OpponentTravelCard — l'écran SET-UP du trajet adverse (P2-54 PR-3)", () => {
  it("met en avant les adversaires non localisés avec un geste « Localiser »", () => {
    travelState.data = [opp({ opponentLabel: "Perdu FC", located: false, precision: null, locationName: null, travelMinutes: null, source: null })];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByText("Adversaires à localiser")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Localiser l'adversaire Perdu FC" })).toBeInTheDocument();
  });

  it("affiche un adversaire localisé avec sa source et son trajet", () => {
    travelState.data = [opp({ opponentLabel: "Voisin FC", travelMinutes: 18 })];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByText("Voisin FC")).toBeInTheDocument();
    expect(screen.getByText("Auto")).toBeInTheDocument();
    expect(screen.getByText("18 min")).toBeInTheDocument();
  });

  it("propose le retour à l'automatique sur une correction MANUELLE seulement", async () => {
    travelState.data = [
      opp({ opponentLabel: "Corrigé FC", source: "MANUAL", overrideVenueLabel: "Le vrai gymnase" }),
      opp({ opponentLabel: "Auto FC", source: "AUTO" }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByText("Manuel")).toBeInTheDocument();
    const revert = screen.getByRole("button", { name: "Rétablir la localisation automatique de Corrigé FC" });
    // No revert offered for the AUTO opponent.
    expect(screen.queryByRole("button", { name: /Rétablir la localisation automatique de Auto FC/ })).not.toBeInTheDocument();

    await userEvent.click(revert);
    expect(revertMutate).toHaveBeenCalledWith("ORG-Corrigé FC");
  });

  it("recalcule tous les trajets à la demande", async () => {
    travelState.data = [opp({ opponentLabel: "Voisin FC" })];
    renderWithProviders(<OpponentTravelCard />);

    await userEvent.click(screen.getByRole("button", { name: /Recalculer les trajets/ }));
    expect(resolveMutate).toHaveBeenCalledTimes(1);
  });

  it("tout localisé → un ton calme, jamais l'encart d'alerte", () => {
    travelState.data = [opp({ opponentLabel: "Voisin FC" })];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByText(/Tous vos adversaires sont localisés/)).toBeInTheDocument();
    expect(screen.queryByText("Adversaires à localiser")).not.toBeInTheDocument();
  });
});
