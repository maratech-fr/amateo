import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { FfbbSalle, OpponentTravel } from "./api";
import { LocateOpponentModal } from "./LocateOpponentModal";

const manualMutate = vi.fn();
const sallesState: { data: { postalCode: string | null; salles: FfbbSalle[] } | undefined; isError: boolean } = {
  data: undefined,
  isError: false,
};

vi.mock("./queries", () => ({
  useFfbbSalles: () => ({ data: sallesState.data, isError: sallesState.isError, refetch: vi.fn() }),
  useSetOpponentTravelManual: () => ({ mutate: manualMutate, isPending: false }),
}));

const OPPONENT: OpponentTravel = {
  opponentOrganismeCode: "ARA0069001",
  opponentLabel: "Meyzieu Basket",
  located: false,
  precision: null,
  locationName: null,
  travelMinutes: null,
  approximated: false,
  source: null,
  overrideVenueLabel: null,
};

const salle = (over: Partial<FfbbSalle>): FfbbSalle => ({
  name: "Gymnase des Servizières",
  address: "Rue X",
  city: "Meyzieu",
  externalRef: "S123",
  latitude: "45.77",
  longitude: "4.90",
  ...over,
});

beforeEach(() => {
  manualMutate.mockReset();
  sallesState.data = undefined;
  sallesState.isError = false;
});

describe("LocateOpponentModal — la correction manuelle du lieu (P2-54 PR-3)", () => {
  it("nomme l'adversaire dans son titre", () => {
    renderWithProviders(<LocateOpponentModal opponent={OPPONENT} onClose={vi.fn()} />);
    expect(screen.getByText("Localiser Meyzieu Basket")).toBeInTheDocument();
  });

  it("choisir une salle pose la surcharge MANUELLE avec ses coordonnées", async () => {
    sallesState.data = { postalCode: "69330", salles: [salle({})] };
    const onClose = vi.fn();
    renderWithProviders(<LocateOpponentModal opponent={OPPONENT} onClose={onClose} />);

    await userEvent.type(screen.getByLabelText("Commune (code postal)"), "69330");
    const list = screen.getByRole("list", { name: /Salles FFBB/ });
    await userEvent.click(within(list).getByRole("button", { name: /Gymnase des Servizières/ }));

    expect(manualMutate).toHaveBeenCalledTimes(1);
    expect(manualMutate.mock.calls[0][0]).toEqual({
      opponentOrganismeCode: "ARA0069001",
      venueLabel: "Gymnase des Servizières",
      venueExternalRef: "S123",
      latitude: 45.77,
      longitude: 4.9,
    });
  });

  it("une salle sans coordonnées ne peut pas être choisie", async () => {
    sallesState.data = { postalCode: "69330", salles: [salle({ latitude: null, longitude: null })] };
    renderWithProviders(<LocateOpponentModal opponent={OPPONENT} onClose={vi.fn()} />);

    await userEvent.type(screen.getByLabelText("Commune (code postal)"), "69330");
    const list = screen.getByRole("list", { name: /Salles FFBB/ });
    expect(within(list).getByRole("button", { name: /Gymnase des Servizières/ })).toBeDisabled();
  });
});
