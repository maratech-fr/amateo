import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { FfbbSalle, OpponentTravel, VenueSuggestion } from "./api";
import { LocateOpponentModal } from "./LocateOpponentModal";

const manualMutate = vi.fn();
const suggestionsState: { data: VenueSuggestion[] | undefined; isError: boolean } = { data: undefined, isError: false };
const sallesState: { data: { postalCode: string | null; salles: FfbbSalle[] } | undefined; isError: boolean } = { data: undefined, isError: false };

vi.mock("./queries", () => ({
  useVenueSuggestions: () => ({ data: suggestionsState.data, isError: suggestionsState.isError, refetch: vi.fn() }),
  useFfbbSalles: () => ({ data: sallesState.data, isError: sallesState.isError, refetch: vi.fn() }),
  useSetOpponentTravelManual: () => ({ mutate: manualMutate, isPending: false }),
}));

const OPPONENT: OpponentTravel = {
  opponentOrganismeCode: "ARA0069001",
  opponentTeamKey: "MEYZIEU BASKET",
  opponentLabel: "Meyzieu Basket",
  located: false,
  hasLogo: false,
  precision: null,
  locationName: null,
  city: null,
  postalCode: "69330",
  travelMinutes: null,
  approximated: false,
  source: null,
  scope: null,
  overrideVenueLabel: null,
};

const suggestion = (over: Partial<VenueSuggestion>): VenueSuggestion => ({
  externalRef: "S123",
  label: "Gymnase connu",
  city: "Meyzieu",
  postalCode: "69330",
  latitude: 45.77,
  longitude: 4.9,
  source: "MANUAL",
  chosenByCount: 3,
  lastChosenAt: "2026-09-15",
  ...over,
});

const salle = (over: Partial<FfbbSalle>): FfbbSalle => ({
  name: "Gymnase des Servizières",
  address: "Rue X",
  city: "Meyzieu",
  externalRef: "S999",
  latitude: "45.77",
  longitude: "4.90",
  ...over,
});

const renderTeam = (onClose = vi.fn()) => renderWithProviders(<LocateOpponentModal opponent={OPPONENT} clubLabel="Meyzieu Basket" lockedToClub={false} onClose={onClose} />);

beforeEach(() => {
  manualMutate.mockReset();
  suggestionsState.data = undefined;
  suggestionsState.isError = false;
  sallesState.data = undefined;
  sallesState.isError = false;
});

describe("LocateOpponentModal — la correction manuelle (PR-3, suggestions + portée)", () => {
  it("nomme l'adversaire dans son titre (contexte équipe)", () => {
    renderTeam();
    expect(screen.getByText("Localiser Meyzieu Basket")).toBeInTheDocument();
  });

  it("depuis la ligne club : titre « … toutes les équipes » et portée verrouillée (pas de radio équipe)", () => {
    renderWithProviders(<LocateOpponentModal opponent={OPPONENT} clubLabel="Meyzieu Basket" lockedToClub onClose={vi.fn()} />);
    expect(screen.getByText("Localiser Meyzieu Basket, toutes les équipes")).toBeInTheDocument();
    expect(screen.queryByLabelText("Pour cette équipe")).not.toBeInTheDocument();
    expect(screen.getByLabelText("Pour tout le club")).toBeDisabled();
  });

  it("liste les gymnases connus, FFBB d'abord, avec leur pastille de compte/observation", () => {
    suggestionsState.data = [
      suggestion({ externalRef: null, label: "Halle fédérale", source: "FFBB_API" }),
      suggestion({ externalRef: "S123", label: "Gymnase choisi", source: "MANUAL", chosenByCount: 4 }),
    ];
    renderTeam();

    const list = screen.getByRole("list", { name: "Gymnases connus de Meyzieu Basket" });
    expect(within(list).getByText("Halle fédérale")).toBeInTheDocument();
    expect(within(list).getByText("vu sur FFBB")).toBeInTheDocument();
    expect(within(list).getByText("choisi 4 fois")).toBeInTheDocument();
  });

  it("un clic sur un gymnase connu pose la surcharge MANUELLE, portée TEAM par défaut", async () => {
    suggestionsState.data = [suggestion({ externalRef: "S123", label: "Gymnase connu", latitude: 45.77, longitude: 4.9 })];
    const onClose = vi.fn();
    renderTeam(onClose);

    await userEvent.click(screen.getByRole("button", { name: /Gymnase connu/ }));
    expect(manualMutate.mock.calls[0][0]).toEqual({
      opponentOrganismeCode: "ARA0069001",
      venueLabel: "Gymnase connu",
      venueExternalRef: "S123",
      latitude: 45.77,
      longitude: 4.9,
      opponentTeamKey: "MEYZIEU BASKET",
      scope: "TEAM",
    });
  });

  it("basculer la portée sur « tout le club » pose un scope CLUB, sans teamKey", async () => {
    suggestionsState.data = [suggestion({ externalRef: "S123", label: "Gymnase connu" })];
    renderTeam();

    await userEvent.click(screen.getByLabelText("Pour tout le club"));
    await userEvent.click(screen.getByRole("button", { name: /Gymnase connu/ }));
    expect(manualMutate.mock.calls[0][0]).toEqual({
      opponentOrganismeCode: "ARA0069001",
      venueLabel: "Gymnase connu",
      venueExternalRef: "S123",
      latitude: 45.77,
      longitude: 4.9,
      scope: "CLUB",
    });
  });

  it("une suggestion SANS n° de salle reste sélectionnable (ref null, coordonnées présentes)", async () => {
    suggestionsState.data = [suggestion({ externalRef: null, label: "Sans numéro", latitude: 45.77, longitude: 4.9 })];
    renderTeam();

    expect(screen.getByText(/sans n° de salle — votre choix restera propre à votre club/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /Sans numéro/ }));
    expect(manualMutate.mock.calls[0][0].venueExternalRef).toBeNull();
  });

  it("une suggestion sans coordonnées ne peut pas être choisie", () => {
    suggestionsState.data = [suggestion({ label: "Sans géo", latitude: null, longitude: null })];
    renderTeam();
    expect(screen.getByRole("button", { name: /Sans géo/ })).toBeDisabled();
  });

  it("suggestions : chargement, échec (avec repli CP), vide", () => {
    // chargement
    suggestionsState.data = undefined;
    const { unmount } = renderTeam();
    expect(screen.getByText("Recherche des gymnases connus…")).toBeInTheDocument();
    unmount();

    // échec : la section 2 (CP) reste utilisable
    suggestionsState.isError = true;
    const failed = renderTeam();
    expect(screen.getByText(/Suggestions indisponibles — cherchez par code postal/)).toBeInTheDocument();
    expect(screen.getByLabelText("Commune (code postal)")).toBeInTheDocument();
    failed.unmount();

    // vide
    suggestionsState.isError = false;
    suggestionsState.data = [];
    renderTeam();
    expect(screen.getByText(/Aucun gymnase connu pour ce club/)).toBeInTheDocument();
  });

  it("le code postal est prérempli avec celui de l'adversaire et lance la recherche FFBB", () => {
    sallesState.data = { postalCode: "69330", salles: [salle({})] };
    renderTeam();
    // Prérempli — la liste FFBB paraît SANS avoir tapé quoi que ce soit.
    expect(screen.getByLabelText("Commune (code postal)")).toHaveValue("69330");
    expect(screen.getByRole("list", { name: /Salles FFBB/ })).toBeInTheDocument();
  });

  // ── TÉMOINS GARDÉS (ex LocateOpponentModal.test.tsx:60-62) ──────────────────────────
  it("choisir une salle FFBB pose la surcharge MANUELLE avec ses coordonnées", async () => {
    sallesState.data = { postalCode: "69330", salles: [salle({})] };
    renderTeam();

    const list = screen.getByRole("list", { name: /Salles FFBB/ });
    await userEvent.click(within(list).getByRole("button", { name: /Gymnase des Servizières/ }));
    expect(manualMutate.mock.calls[0][0]).toEqual({
      opponentOrganismeCode: "ARA0069001",
      venueLabel: "Gymnase des Servizières",
      venueExternalRef: "S999",
      latitude: 45.77,
      longitude: 4.9,
      opponentTeamKey: "MEYZIEU BASKET",
      scope: "TEAM",
    });
  });

  it("annonce « Aucune salle trouvée » quand la recherche aboutit à zéro salle", () => {
    sallesState.data = { postalCode: "69330", salles: [] };
    renderTeam();
    expect(screen.getByText("Aucune salle trouvée pour ce code postal.")).toBeInTheDocument();
  });

  it("une salle FFBB sans coordonnées ne peut pas être choisie", () => {
    sallesState.data = { postalCode: "69330", salles: [salle({ latitude: null, longitude: null })] };
    renderTeam();
    const list = screen.getByRole("list", { name: /Salles FFBB/ });
    expect(within(list).getByRole("button", { name: /Gymnase des Servizières/ })).toBeDisabled();
  });
});

describe("LocateOpponentModal — indice « Dans le fichier » (PR 2a)", () => {
  const renderWithHint = (labels: string[]) =>
    renderWithProviders(<LocateOpponentModal opponent={OPPONENT} clubLabel="Meyzieu Basket" lockedToClub={false} fileVenueLabels={labels} onClose={vi.fn()} />);

  it("aucun libellé ⇒ pas de ligne « Dans le fichier »", () => {
    renderTeam(); // fileVenueLabels par défaut = []
    expect(screen.queryByText(/Dans le fichier/)).not.toBeInTheDocument();
  });

  it("un libellé ⇒ « Dans le fichier : GYMNASE CHANFRAY »", () => {
    renderWithHint(["GYMNASE CHANFRAY"]);
    expect(screen.getByText(/Dans le fichier/)).toBeInTheDocument();
    expect(screen.getByText("GYMNASE CHANFRAY")).toBeInTheDocument();
  });

  it("plusieurs libellés ⇒ joints par « · »", () => {
    renderWithHint(["HALLE A", "HALLE B", "HALLE C"]);
    expect(screen.getByText("HALLE A · HALLE B · HALLE C")).toBeInTheDocument();
  });
});
