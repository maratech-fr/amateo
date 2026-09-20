import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { OpponentClub, OpponentTravelPayload, OpponentVenue } from "./api";
import { OpponentsPage } from "./OpponentsPage";

const retryMutate = vi.fn();
const updateRun = vi.fn();
const deleteMutate = vi.fn();
const repointMutate = vi.fn();
const travelState: { data: OpponentTravelPayload; isError: boolean } = { data: { clubGeolocated: true, opponents: [] }, isError: false };
const updateState: { step: "idle" | "running" } = { step: "idle" };
const geolocatedState: { value: boolean } = { value: true };

vi.mock("./queries", () => ({
  useOpponentTravel: () => ({ data: travelState.data, isError: travelState.isError, refetch: vi.fn() }),
  useClubGeolocated: () => geolocatedState.value,
  useUpdateOpponents: () => ({ run: updateRun, isPending: "idle" !== updateState.step, step: updateState.step }),
  useResolveOpponentTravel: () => ({ mutate: retryMutate, isPending: false }),
  useDeleteVenueLink: () => ({ mutate: deleteMutate, isPending: false }),
  useRepointVenueLink: () => ({ mutate: repointMutate, isPending: false }),
  // The add/pair modal (opened by « Ajouter un gymnase » / « Apparier ») needs these.
  useVenueSuggestions: () => ({ data: undefined, isError: false, refetch: vi.fn() }),
  useFfbbSalles: () => ({ data: undefined, isError: false, refetch: vi.fn() }),
  useAddOpponentVenue: () => ({ mutate: vi.fn(), isPending: false }),
  usePairOpponentVenueLabel: () => ({ mutate: vi.fn(), isPending: false }),
}));

vi.mock("@/shared/lib/travelStream", () => ({ useTravelStream: () => ({ connected: false, latest: null }) }));

const venue = (over: Partial<OpponentVenue> & { id: string; label: string }): OpponentVenue => ({
  externalRef: null,
  latitude: 45.8,
  longitude: 5,
  source: "AUTO",
  travelMinutes: 20,
  travelStatus: "done",
  approximated: false,
  fixtureCount: 1,
  fallbackVenueName: null,
  ...over,
});

const club = (over: Partial<OpponentClub> & { name: string }): OpponentClub => ({
  code: "C1",
  pairingKey: "C1",
  city: "Lyon",
  postalCode: null,
  precision: "VENUE",
  hasLogo: false,
  fixtureCount: 1,
  venues: [],
  unmatchedLabels: [],
  ...over,
});

beforeEach(() => {
  retryMutate.mockReset();
  updateRun.mockReset();
  deleteMutate.mockReset();
  repointMutate.mockReset();
  travelState.data = { clubGeolocated: true, opponents: [] };
  travelState.isError = false;
  updateState.step = "idle";
  geolocatedState.value = true;
});

describe("OpponentsPage — la liste par club adverse (grain gymnase)", () => {
  it("un club avec un gymnase : la ligne club (th scope=row), la ligne gymnase (trajet + source)", () => {
    travelState.data = { clubGeolocated: true, opponents: [club({ code: "C1", name: "ASVEL", venues: [venue({ id: "l1", label: "Halle Astroballe", travelMinutes: 20 })], fixtureCount: 3 })] };
    renderWithProviders(<OpponentsPage />);

    expect(screen.getByRole("rowheader", { name: /ASVEL/ })).toBeInTheDocument();
    expect(screen.getByText("Halle Astroballe")).toBeInTheDocument();
    expect(screen.getByText("20 min")).toBeInTheDocument();
    expect(screen.getByText("Auto")).toBeInTheDocument();
  });

  it("un club SANS gymnase : « Aucun gymnase connu » + « Ajouter un gymnase », aucune ligne fille (pas de colSpan)", () => {
    travelState.data = { clubGeolocated: true, opponents: [club({ code: "C2", name: "BC Sans Gym", venues: [], fixtureCount: 4 })] };
    const { container } = renderWithProviders(<OpponentsPage />);

    expect(screen.getByText("Aucun gymnase connu")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Ajouter un gymnase/ })).toBeInTheDocument();
    // Pas de cellule fourre-tout : aucun colSpan dans le tableau.
    expect(container.querySelector("td[colspan]")).toBeNull();
    // Une phrase globale sous le tableau.
    expect(screen.getByText(/1 club adverse sans gymnase/)).toBeInTheDocument();
  });

  it("les libellés « à apparier » sont dans le MÊME rowgroup que le club, avec le bouton « Apparier »", () => {
    travelState.data = {
      clubGeolocated: true,
      opponents: [club({ code: "C3", name: "BC Orphelin", venues: [venue({ id: "l3", label: "Gymnase A" })], unmatchedLabels: [{ label: "SALLE MACHIN", fixtureCount: 2 }] })],
    };
    renderWithProviders(<OpponentsPage />);

    const rowgroups = screen.getAllByRole("rowgroup");
    // Le tbody du club (dernier rowgroup : thead + un tbody par club) contient le libellé orphelin.
    const clubBody = rowgroups[rowgroups.length - 1];
    expect(within(clubBody).getByText(/SALLE MACHIN/)).toBeInTheDocument();
    expect(within(clubBody).getByText("à apparier")).toBeInTheDocument();
    expect(within(clubBody).getByRole("button", { name: "Apparier" })).toBeInTheDocument();
  });

  it("les segments de filtre nomment leur UNITÉ dans l'aria-label (clubs vs salles), texte visible = nombre nu", () => {
    travelState.data = {
      clubGeolocated: true,
      opponents: [
        club({ code: "A", name: "Sans gym A", venues: [] }),
        club({ code: "B", name: "Avec orphelin", venues: [venue({ id: "lb", label: "Gym B" })], unmatchedLabels: [{ label: "SALLE X", fixtureCount: 1 }, { label: "SALLE Y", fixtureCount: 1 }] }),
      ],
    };
    renderWithProviders(<OpponentsPage />);

    expect(screen.getByRole("button", { name: "Sans gymnase — 1 clubs" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "À apparier — 2 salles" })).toBeInTheDocument();
  });

  it("filtre « À apparier » : la ligne club + SEULEMENT les libellés orphelins (les gymnases appariés sont masqués)", async () => {
    const user = userEvent.setup();
    travelState.data = {
      clubGeolocated: true,
      opponents: [club({ code: "B", name: "Avec orphelin", venues: [venue({ id: "lb", label: "Gym Apparié" })], unmatchedLabels: [{ label: "SALLE ORPHELINE", fixtureCount: 1 }] })],
    };
    renderWithProviders(<OpponentsPage />);

    await user.click(screen.getByRole("button", { name: "À apparier — 1 salles" }));
    expect(screen.getByText(/SALLE ORPHELINE/)).toBeInTheDocument();
    expect(screen.queryByText("Gym Apparié")).not.toBeInTheDocument();
  });

  it("le menu d'un gymnase liste « Fusionner dans … » par autre gymnase + « Retirer ce gymnase »", async () => {
    const user = userEvent.setup();
    travelState.data = {
      clubGeolocated: true,
      opponents: [club({ code: "C", name: "Multi", venues: [venue({ id: "l1", label: "Gym 1" }), venue({ id: "l2", label: "Gym 2" })] })],
    };
    renderWithProviders(<OpponentsPage />);

    await user.click(screen.getByRole("button", { name: "Actions pour Gym 1 — Multi" }));
    expect(screen.getByRole("menuitem", { name: "Fusionner dans « Gym 2 »" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Retirer ce gymnase" })).toBeInTheDocument();
    // Escape referme et rend le focus au trigger.
    await user.keyboard("{Escape}");
    expect(screen.getByRole("button", { name: "Actions pour Gym 1 — Multi" })).toHaveFocus();
  });

  it("un seul gymnase : le menu ne propose PAS de fusion, juste « Retirer »", async () => {
    const user = userEvent.setup();
    travelState.data = { clubGeolocated: true, opponents: [club({ code: "C", name: "Solo", venues: [venue({ id: "l1", label: "Gym Unique" })] })] };
    renderWithProviders(<OpponentsPage />);

    await user.click(screen.getByRole("button", { name: "Actions pour Gym Unique — Solo" }));
    expect(screen.queryByRole("menuitem", { name: /Fusionner/ })).not.toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Retirer ce gymnase" })).toBeInTheDocument();
  });

  it("« Retirer » ouvre une confirmation dont le texte vient de la donnée SERVIE (fallbackVenueName)", async () => {
    const user = userEvent.setup();
    travelState.data = {
      clubGeolocated: true,
      opponents: [
        club({
          code: "C",
          name: "Club XXXX",
          venues: [venue({ id: "l1", label: "Gymnase 1", fixtureCount: 5, fallbackVenueName: "Gymnase 2" }), venue({ id: "l2", label: "Gymnase 2", fixtureCount: 3, fallbackVenueName: "Gymnase 1" })],
        }),
      ],
    };
    renderWithProviders(<OpponentsPage />);

    await user.click(screen.getByRole("button", { name: "Actions pour Gymnase 1 — Club XXXX" }));
    await user.click(screen.getByRole("menuitem", { name: "Retirer ce gymnase" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/Retirer « Gymnase 1 » de Club XXXX/)).toBeInTheDocument();
    expect(within(dialog).getByText(/5 rencontres retomberont sur « Gymnase 2 »/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Retirer" }));
    expect(deleteMutate).toHaveBeenCalledWith("l1", expect.anything());
  });

  it("dernier gymnase (fallbackVenueName null) : le retrait annonce la sortie du radar", async () => {
    const user = userEvent.setup();
    travelState.data = {
      clubGeolocated: true,
      opponents: [club({ code: "C", name: "Club Dernier", fixtureCount: 6, venues: [venue({ id: "l1", label: "Seul Gym", fixtureCount: 6, fallbackVenueName: null })] })],
    };
    renderWithProviders(<OpponentsPage />);

    await user.click(screen.getByRole("button", { name: "Actions pour Seul Gym — Club Dernier" }));
    await user.click(screen.getByRole("menuitem", { name: "Retirer ce gymnase" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/n'aura plus aucun gymnase connu/)).toBeInTheDocument();
    expect(within(dialog).getByText(/6 rencontres sortiront du radar/)).toBeInTheDocument();
  });

  it("« Ajouter un gymnase » : la modale reçoit le code postal du club — CP prérempli, contexte ville+CP", async () => {
    const user = userEvent.setup();
    travelState.data = { clubGeolocated: true, opponents: [club({ code: "C9", name: "BC Brignais", city: "Brignais", postalCode: "69530", venues: [] })] };
    renderWithProviders(<OpponentsPage />);

    await user.click(screen.getByRole("button", { name: /Ajouter un gymnase/ }));
    const dialog = await screen.findByRole("dialog");
    // Le champ de recherche FFBB part prérempli du code postal fédéral — aucune saisie manuelle.
    expect(within(dialog).getByLabelText("Commune (code postal)")).toHaveValue("69530");
    expect(within(dialog).getByText(/Brignais 69530/)).toBeInTheDocument();
  });

  it("« Apparier » : la modale reçoit aussi le code postal du club (prérempli)", async () => {
    const user = userEvent.setup();
    travelState.data = {
      clubGeolocated: true,
      opponents: [club({ code: "C9", name: "BC Brignais", city: "Brignais", postalCode: "69530", venues: [], unmatchedLabels: [{ label: "SALLE ORPH", fixtureCount: 1 }] })],
    };
    renderWithProviders(<OpponentsPage />);

    await user.click(screen.getByRole("button", { name: "Apparier" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByLabelText("Commune (code postal)")).toHaveValue("69530");
  });

  it("le rang du club est GELÉ au montage : un club apparié ne saute pas dans le tri", async () => {
    const user = userEvent.setup();
    // Au montage : « Zoulou » (sans gymnase) passe premier (règle sans-gym), « Alpha » (avec gymnase) second.
    travelState.data = {
      clubGeolocated: true,
      opponents: [club({ code: "Z", name: "Zoulou", venues: [] }), club({ code: "A", name: "Alpha", venues: [venue({ id: "la", label: "Gym Alpha" })] })],
    };
    renderWithProviders(<OpponentsPage />);
    expect(screen.getAllByRole("rowheader")[0]).toHaveTextContent("Zoulou");

    // « Zoulou » gagne un gymnase ; une recherche neutre (« Gym » matche les deux) force un
    // re-render sur la NOUVELLE donnée. SANS gel, le tri alpha le ferait passer APRÈS « Alpha ».
    travelState.data = {
      clubGeolocated: true,
      opponents: [club({ code: "Z", name: "Zoulou", venues: [venue({ id: "lz", label: "Gym Zoulou" })] }), club({ code: "A", name: "Alpha", venues: [venue({ id: "la", label: "Gym Alpha" })] })],
    };
    await user.type(screen.getByRole("searchbox"), "Gym");
    const order = screen.getAllByRole("rowheader");
    expect(order[0]).toHaveTextContent("Zoulou");
    expect(order[1]).toHaveTextContent("Alpha");
  });

  it("un club SANS code fédéral (pairingKey sentinelle) : « Ajouter un gymnase » et « Apparier » restent actifs", async () => {
    const user = userEvent.setup();
    travelState.data = {
      clubGeolocated: true,
      opponents: [
        club({ code: null, pairingKey: "Xdeadbeef", name: "Club Amical", venues: [], unmatchedLabels: [{ label: "SALLE AMICALE", fixtureCount: 1 }] }),
      ],
    };
    renderWithProviders(<OpponentsPage />);

    // Zéro bouton mort : les deux gestes sont proposés même sans code fédéral.
    expect(screen.getByRole("button", { name: /Ajouter un gymnase/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Apparier" })).toBeInTheDocument();

    // La modale ouverte pour un sans-code explique le repli local (pas de « Gymnases connus »).
    await user.click(screen.getByRole("button", { name: "Apparier" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/Club sans code fédéral/)).toBeInTheDocument();
    expect(within(dialog).queryByText("Gymnases connus")).not.toBeInTheDocument();
  });

  it("le bandeau siège paraît quand le club n'est pas localisé", () => {
    geolocatedState.value = false;
    travelState.data = { clubGeolocated: false, opponents: [club({ name: "ASVEL", venues: [venue({ id: "l1", label: "Gym" })] })] };
    renderWithProviders(<OpponentsPage />);
    expect(screen.getByText(/l'adresse du siège du club n'est pas localisée/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Renseigner le siège" })).toBeInTheDocument();
  });
});
