import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { FfbbSalle, VenueSuggestion } from "./api";
import { LocateOpponentModal } from "./LocateOpponentModal";

const addMutate = vi.fn();
const pairMutate = vi.fn();
const suggestionsState: { data: VenueSuggestion[] | undefined; isError: boolean } = { data: undefined, isError: false };
const sallesState: { data: { postalCode: string | null; salles: FfbbSalle[] } | undefined; isError: boolean } = { data: undefined, isError: false };

vi.mock("./queries", () => ({
  useVenueSuggestions: () => ({ data: suggestionsState.data, isError: suggestionsState.isError, refetch: vi.fn() }),
  useFfbbSalles: () => ({ data: sallesState.data, isError: sallesState.isError, refetch: vi.fn() }),
  useAddOpponentVenue: () => ({ mutate: addMutate, isPending: false }),
  usePairOpponentVenueLabel: () => ({ mutate: pairMutate, isPending: false }),
}));

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

const renderAdd = (onClose = vi.fn()) => renderWithProviders(<LocateOpponentModal code="ARA0069001" clubName="Meyzieu Basket" fbiLabel={null} postalCode="69330" city="Meyzieu" onClose={onClose} />);
const renderPair = (onClose = vi.fn()) => renderWithProviders(<LocateOpponentModal code="ARA0069001" clubName="Meyzieu Basket" fbiLabel="SALLE MACHIN" postalCode="69330" city="Meyzieu" onClose={onClose} />);

beforeEach(() => {
  addMutate.mockReset();
  pairMutate.mockReset();
  suggestionsState.data = undefined;
  suggestionsState.isError = false;
  sallesState.data = undefined;
  sallesState.isError = false;
});

describe("LocateOpponentModal — ajouter / apparier un gymnase", () => {
  it("mode AJOUT : le titre nomme le club", () => {
    renderAdd();
    expect(screen.getByText("Ajouter un gymnase — Meyzieu Basket")).toBeInTheDocument();
  });

  it("mode APPARIER : le titre nomme le libellé orphelin", () => {
    renderPair();
    expect(screen.getByText("Apparier « SALLE MACHIN »")).toBeInTheDocument();
  });

  it("liste les gymnases connus, FFBB d'abord, avec leur pastille de compte/observation", () => {
    suggestionsState.data = [
      suggestion({ externalRef: null, label: "Halle fédérale", source: "FFBB_API" }),
      suggestion({ externalRef: "S123", label: "Gymnase choisi", source: "MANUAL", chosenByCount: 4 }),
    ];
    renderAdd();

    const list = screen.getByRole("list", { name: "Gymnases connus de Meyzieu Basket" });
    expect(within(list).getByText("Halle fédérale")).toBeInTheDocument();
    expect(within(list).getByText("vu sur FFBB")).toBeInTheDocument();
    expect(within(list).getByText("choisi 4 fois")).toBeInTheDocument();
  });

  it("mode AJOUT : un clic sur un gymnase connu appelle addOpponentVenue", async () => {
    suggestionsState.data = [suggestion({ externalRef: "S123", label: "Gymnase connu", latitude: 45.77, longitude: 4.9 })];
    renderAdd();

    await userEvent.click(screen.getByRole("button", { name: /Gymnase connu/ }));
    expect(addMutate.mock.calls[0][0]).toEqual({ code: "ARA0069001", venueLabel: "Gymnase connu", venueExternalRef: "S123", latitude: 45.77, longitude: 4.9 });
    expect(pairMutate).not.toHaveBeenCalled();
  });

  it("mode APPARIER : un clic sur un gymnase appelle pairOpponentVenueLabel avec le libellé orphelin", async () => {
    suggestionsState.data = [suggestion({ externalRef: "S123", label: "Gymnase connu", latitude: 45.77, longitude: 4.9 })];
    renderPair();

    await userEvent.click(screen.getByRole("button", { name: /Gymnase connu/ }));
    expect(pairMutate.mock.calls[0][0]).toEqual({ code: "ARA0069001", fbiLabel: "SALLE MACHIN", venueLabel: "Gymnase connu", venueExternalRef: "S123", latitude: 45.77, longitude: 4.9 });
    expect(addMutate).not.toHaveBeenCalled();
  });

  it("une suggestion SANS n° de salle reste sélectionnable (ref null, coordonnées présentes)", async () => {
    suggestionsState.data = [suggestion({ externalRef: null, label: "Sans numéro", latitude: 45.77, longitude: 4.9 })];
    renderAdd();

    expect(screen.getByText(/sans n° de salle — votre choix restera propre à votre club/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /Sans numéro/ }));
    expect(addMutate.mock.calls[0][0].venueExternalRef).toBeNull();
  });

  it("une suggestion sans coordonnées ne peut pas être choisie", () => {
    suggestionsState.data = [suggestion({ label: "Sans géo", latitude: null, longitude: null })];
    renderAdd();
    expect(screen.getByRole("button", { name: /Sans géo/ })).toBeDisabled();
  });

  it("suggestions : chargement, échec (avec repli CP), vide", () => {
    suggestionsState.data = undefined;
    const { unmount } = renderAdd();
    expect(screen.getByText("Recherche des gymnases connus…")).toBeInTheDocument();
    unmount();

    suggestionsState.isError = true;
    const failed = renderAdd();
    expect(screen.getByText(/Suggestions indisponibles — cherchez par code postal/)).toBeInTheDocument();
    expect(screen.getByLabelText("Commune (code postal)")).toBeInTheDocument();
    failed.unmount();

    suggestionsState.isError = false;
    suggestionsState.data = [];
    renderAdd();
    expect(screen.getByText(/Aucun gymnase connu pour ce club/)).toBeInTheDocument();
  });

  it("le sous-titre situe le club par sa ville et son code postal (contexte de la recherche)", () => {
    renderAdd();
    expect(screen.getByText(/Meyzieu 69330/)).toBeInTheDocument();
  });

  it("mode APPARIER : le sous-titre garde la phrase d'appariement ET ajoute le contexte ville+CP", () => {
    renderPair();
    expect(screen.getByText(/Choisissez le gymnase de/)).toBeInTheDocument();
    expect(screen.getByText(/Meyzieu 69330/)).toBeInTheDocument();
  });

  it("sans ville ni code postal connus, aucun contexte n'est inventé (mode AJOUT)", () => {
    renderWithProviders(<LocateOpponentModal code="ARA0069001" clubName="Meyzieu Basket" fbiLabel={null} postalCode={null} city={null} onClose={vi.fn()} />);
    expect(screen.queryByText(/Meyzieu 69330/)).not.toBeInTheDocument();
  });

  it("le code postal est prérempli et lance la recherche FFBB", () => {
    sallesState.data = { postalCode: "69330", salles: [salle({})] };
    renderAdd();
    expect(screen.getByLabelText("Commune (code postal)")).toHaveValue("69330");
    expect(screen.getByRole("list", { name: /Salles FFBB/ })).toBeInTheDocument();
  });

  it("choisir une salle FFBB (mode AJOUT) appelle addOpponentVenue avec ses coordonnées", async () => {
    sallesState.data = { postalCode: "69330", salles: [salle({})] };
    renderAdd();

    const list = screen.getByRole("list", { name: /Salles FFBB/ });
    await userEvent.click(within(list).getByRole("button", { name: /Gymnase des Servizières/ }));
    expect(addMutate.mock.calls[0][0]).toEqual({ code: "ARA0069001", venueLabel: "Gymnase des Servizières", venueExternalRef: "S999", latitude: 45.77, longitude: 4.9 });
  });

  it("annonce « Aucune salle trouvée » quand la recherche aboutit à zéro salle", () => {
    sallesState.data = { postalCode: "69330", salles: [] };
    renderAdd();
    expect(screen.getByText("Aucune salle trouvée pour ce code postal.")).toBeInTheDocument();
  });

  it("une salle FFBB sans coordonnées ne peut pas être choisie", () => {
    sallesState.data = { postalCode: "69330", salles: [salle({ latitude: null, longitude: null })] };
    renderAdd();
    const list = screen.getByRole("list", { name: /Salles FFBB/ });
    expect(within(list).getByRole("button", { name: /Gymnase des Servizières/ })).toBeDisabled();
  });

  it("adversaire SANS code fédéral : pas de section « Gymnases connus », une explication du repli local", () => {
    // Même si des suggestions traînent, un sans-code ne les montre pas (la requête est désactivée).
    suggestionsState.data = [suggestion({ label: "Ne doit pas paraître" })];
    renderWithProviders(
      <LocateOpponentModal code="Xdeadbeef" clubName="Club Amical" fbiLabel="SALLE AMICALE" unmatchedLabels={["SALLE AMICALE"]} sansCode postalCode={null} city={null} onClose={vi.fn()} />,
    );
    expect(screen.getByText(/Club sans code fédéral/)).toBeInTheDocument();
    expect(screen.queryByText("Gymnases connus")).not.toBeInTheDocument();
    expect(screen.queryByText("Ne doit pas paraître")).not.toBeInTheDocument();
  });

  it("mode APPARIER enchaîne : un succès retire le libellé et avance au suivant sans fermer, le pied dit « Terminer »", async () => {
    pairMutate.mockImplementation((_input: unknown, opts?: { onSuccess?: () => void; onSettled?: () => void }) => {
      opts?.onSuccess?.();
      opts?.onSettled?.();
    });
    suggestionsState.data = [suggestion({ externalRef: "S1", label: "Gym Choisi", latitude: 45.7, longitude: 4.9 })];
    const onClose = vi.fn();
    renderWithProviders(
      <LocateOpponentModal code="ARA0069001" clubName="Meyzieu Basket" fbiLabel="SALLE A" unmatchedLabels={["SALLE A", "SALLE B"]} postalCode="69330" city="Meyzieu" onClose={onClose} />,
    );
    expect(screen.getByText("Apparier « SALLE A »")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Terminer" })).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Gym Choisi/ }));
    expect(pairMutate.mock.calls[0][0].fbiLabel).toBe("SALLE A");
    expect(onClose).not.toHaveBeenCalled();
    // La file avance : le titre passe au libellé suivant, la modale reste ouverte.
    expect(screen.getByText("Apparier « SALLE B »")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Gym Choisi/ }));
    expect(pairMutate.mock.calls[1][0].fbiLabel).toBe("SALLE B");
    expect(onClose).toHaveBeenCalled();
  });

  it("mode AJOUT : le succès ferme la modale (jamais d'enchaînement)", async () => {
    addMutate.mockImplementation((_input: unknown, opts?: { onSuccess?: () => void; onSettled?: () => void }) => {
      opts?.onSuccess?.();
      opts?.onSettled?.();
    });
    suggestionsState.data = [suggestion({ externalRef: "S1", label: "Gym", latitude: 45.7, longitude: 4.9 })];
    const onClose = vi.fn();
    renderAdd(onClose);
    // Le pied « Terminer » de l'enchaînement n'existe PAS en mode ajout.
    expect(screen.queryByRole("button", { name: "Terminer" })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /Gym/ }));
    expect(onClose).toHaveBeenCalled();
  });
});
