import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Fixture, OpponentTravel } from "./api";
import { OpponentTravelCard } from "./OpponentTravelCard";

const revertMutate = vi.fn();
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
  // The modal (opened by « Localiser ») needs these on the mock.
  useVenueSuggestions: () => ({ data: undefined, isError: false, refetch: vi.fn() }),
  useFfbbSalles: () => ({ data: undefined, isError: false, refetch: vi.fn() }),
  useSetOpponentTravelManual: () => ({ mutate: vi.fn(), isPending: false }),
}));

const opp = (over: Partial<OpponentTravel> & { opponentLabel: string }): OpponentTravel => ({
  opponentOrganismeCode: "C1",
  opponentTeamKey: over.opponentLabel.toUpperCase(),
  located: true,
  hasLogo: false,
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
  revertMutate.mockReset();
  updateRun.mockReset();
  travelState.data = [];
  travelState.isError = false;
  fixturesState.data = [];
  updateState.step = "idle";
  geolocatedState.value = true;
});

describe("OpponentTravelCard — l'écran SET-UP du trajet adverse, GROUPÉ PAR CLUB (PR-3)", () => {
  it("regroupe deux équipes d'un même organisme sous UN en-tête de club dérivé + compteur", () => {
    travelState.data = [
      opp({ opponentLabel: "BASKET BALL 5EME - 1", opponentTeamKey: "BB5-1", scope: "CLUB" }),
      opp({ opponentLabel: "BASKET BALL 5EME - 2", opponentTeamKey: "BB5-2", scope: "TEAM", source: "MANUAL" }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByRole("heading", { name: "BASKET BALL 5EME", level: 4 })).toBeInTheDocument();
    expect(screen.getByText("2 équipes")).toBeInTheDocument();
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

    expect(screen.getAllByText("défaut du club")).toHaveLength(1);
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

  it("tri : le club portant une équipe non localisée passe AVANT un club tout localisé", () => {
    travelState.data = [
      opp({ opponentOrganismeCode: "Z", opponentLabel: "Zebre", opponentTeamKey: "ZEBRE", located: true }),
      opp({ opponentOrganismeCode: "A", opponentLabel: "Alpha", opponentTeamKey: "ALPHA", located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    const headings = screen.getAllByRole("heading", { level: 4 }).map((h) => h.textContent);
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

  it("siège NON localisé : bandeau « Trajets indisponibles » + lien vers la fiche club ; « Mettre à jour » reste", () => {
    geolocatedState.value = false;
    travelState.data = [opp({ opponentLabel: "Alpha", opponentTeamKey: "ALPHA" })];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByText("Trajets indisponibles : l'adresse du siège du club n'est pas localisée.")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Renseigner le siège" })).toHaveAttribute("href", "/club?section=informations");
    // « Mettre à jour les adversaires » reste (il localise les gymnases adverses, pas le siège).
    expect(screen.getByRole("button", { name: "Mettre à jour les adversaires" })).toBeInTheDocument();
  });

  it("siège localisé : aucun bandeau « Trajets indisponibles »", () => {
    geolocatedState.value = true;
    travelState.data = [opp({ opponentLabel: "Alpha", opponentTeamKey: "ALPHA" })];
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.queryByText(/Trajets indisponibles/)).not.toBeInTheDocument();
  });
});

describe("OpponentTravelCard — « Mettre à jour les adversaires » (PR 2b, un seul appel)", () => {
  it("le bouton lance la mise à jour (un seul geste serveur)", async () => {
    travelState.data = [opp({ opponentLabel: "Voisin FC", opponentTeamKey: "VOISIN" })];
    renderWithProviders(<OpponentTravelCard />);

    await userEvent.click(screen.getByRole("button", { name: "Mettre à jour les adversaires" }));
    expect(updateRun).toHaveBeenCalledTimes(1);
  });

  it("en cours : libellé unique « Mise à jour… », disabled, annonce a11y unique", () => {
    travelState.data = [opp({ opponentLabel: "Voisin FC", opponentTeamKey: "VOISIN" })];
    updateState.step = "running";
    renderWithProviders(<OpponentTravelCard />);

    expect(screen.getByRole("button", { name: "Mise à jour…" })).toBeDisabled();
    expect(screen.getByText("Mise à jour des adversaires en cours…")).toBeInTheDocument();
  });
});

describe("OpponentTravelCard — recherche instantanée (PR 2a)", () => {
  beforeEach(() => {
    travelState.data = [
      opp({ opponentOrganismeCode: "C1", opponentLabel: "BASKET 5EME - 1", opponentTeamKey: "BB-1" }),
      opp({ opponentOrganismeCode: "C2", opponentLabel: "MEYZIEU BASKET", opponentTeamKey: "MZ" }),
    ];
  });

  it("filtre par club, annonce « N clubs sur M » (role=status)", async () => {
    renderWithProviders(<OpponentTravelCard />);
    await userEvent.type(screen.getByRole("searchbox", { name: "Rechercher un club ou une équipe" }), "meyzieu");

    const count = screen.getByText("1 club sur 2");
    expect(count).toHaveAttribute("role", "status");
    expect(screen.getByRole("heading", { name: "MEYZIEU BASKET", level: 4 })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "BASKET 5EME", level: 4 })).not.toBeInTheDocument();
  });

  it("zéro résultat → « Aucun adversaire pour « xyz ». » à la place de la liste (role=status)", async () => {
    renderWithProviders(<OpponentTravelCard />);
    await userEvent.type(screen.getByRole("searchbox", { name: "Rechercher un club ou une équipe" }), "xyz");

    const empty = screen.getByText(/Aucun adversaire pour/);
    expect(empty).toHaveAttribute("role", "status");
    expect(empty).toHaveTextContent("Aucun adversaire pour « xyz ».");
    expect(screen.queryByRole("heading", { level: 4 })).not.toBeInTheDocument();
  });

  it("Escape vide la requête (la liste complète revient)", async () => {
    renderWithProviders(<OpponentTravelCard />);
    const search = screen.getByRole("searchbox", { name: "Rechercher un club ou une équipe" });
    await userEvent.type(search, "xyz");
    expect(screen.getByText(/Aucun adversaire pour/)).toBeInTheDocument();
    await userEvent.type(search, "{Escape}");
    expect(search).toHaveValue("");
    expect(screen.getByRole("heading", { name: "MEYZIEU BASKET", level: 4 })).toBeInTheDocument();
  });
});

describe("OpponentTravelCard — adversaires sans code fédéral (PR 2a, repli à part)", () => {
  it("repliés par défaut sous une disclosure, dépliés au clic ; jamais un « Localiser » ici", async () => {
    travelState.data = [
      opp({ opponentLabel: "Team A", opponentTeamKey: "T-A" }),
      opp({ opponentLabel: "Perdu FC", opponentOrganismeCode: null, opponentTeamKey: null, located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    // Replié : le corps n'est PAS monté, mais l'invite et le bouton le sont.
    expect(screen.queryByText("code fédéral non résolu")).not.toBeInTheDocument();
    expect(screen.getByText(/« Mettre à jour les adversaires » tente de retrouver leur code FFBB/)).toBeInTheDocument();
    const toggle = screen.getByRole("button", { name: "1 adversaire sans code fédéral" });
    expect(toggle).toHaveAttribute("aria-expanded", "false");

    await userEvent.click(toggle);
    expect(screen.getByText("code fédéral non résolu")).toBeInTheDocument();
    // L'orphelin n'a AUCUN bouton « Localiser » (le club codé Team A, lui, en a).
    expect(screen.queryByRole("button", { name: /Localiser.*Perdu/ })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Localiser Team A" })).toBeInTheDocument();
  });

  it("une recherche qui matche un orphelin DÉPLIE la liste et suit le compte", async () => {
    travelState.data = [
      opp({ opponentLabel: "Perdu FC", opponentOrganismeCode: null, opponentTeamKey: null, located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null }),
      opp({ opponentLabel: "Zorro Club", opponentOrganismeCode: null, opponentTeamKey: null, located: false, precision: null, locationName: null, travelMinutes: null, source: null, scope: null }),
    ];
    renderWithProviders(<OpponentTravelCard />);

    // Deux orphelins repliés.
    expect(screen.getByRole("button", { name: "2 adversaires sans code fédéral" })).toHaveAttribute("aria-expanded", "false");

    await userEvent.type(screen.getByRole("searchbox", { name: "Rechercher un club ou une équipe" }), "perdu");
    const toggle = screen.getByRole("button", { name: "1 adversaire sans code fédéral" });
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText("Perdu FC")).toBeInTheDocument();
    expect(screen.queryByText("Zorro Club")).not.toBeInTheDocument();
  });
});

describe("OpponentTravelCard — indice « Dans le fichier » passé à Localiser (PR 2a)", () => {
  it("joint les salles FBI de la rencontre par (code, teamKey) et les passe à la modale", async () => {
    travelState.data = [opp({ opponentOrganismeCode: "C9", opponentLabel: "Team A", opponentTeamKey: "T-A", scope: "CLUB" })];
    fixturesState.data = [
      { opponentOrganismeCode: "C9", opponentTeamKey: "T-A", fbiVenueLabel: "GYMNASE CHANFRAY" } as unknown as Fixture,
      { opponentOrganismeCode: "C9", opponentTeamKey: "T-A", fbiVenueLabel: "GYMNASE CHANFRAY" } as unknown as Fixture, // doublon ignoré
      { opponentOrganismeCode: "AUTRE", opponentTeamKey: "X", fbiVenueLabel: "PAS CELUI-CI" } as unknown as Fixture,
    ];
    renderWithProviders(<OpponentTravelCard />);

    await userEvent.click(screen.getByRole("button", { name: "Localiser Team A" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/Dans le fichier/)).toBeInTheDocument();
    expect(within(dialog).getByText("GYMNASE CHANFRAY")).toBeInTheDocument();
    expect(within(dialog).queryByText("PAS CELUI-CI")).not.toBeInTheDocument();
  });
});
