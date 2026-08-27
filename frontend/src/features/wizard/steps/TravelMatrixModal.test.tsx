import { fireEvent, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";
import { toast } from "@/shared/stores/toastStore";

import type { Venue, VenueTravelTime, VenueTravelTimeAutofillResult } from "../api";

const venuesState: { data: Venue[] } = { data: [] };
const matrixState: { data: VenueTravelTime[] } = { data: [] };
const autofillResultState: { value: VenueTravelTimeAutofillResult } = { value: { filled: 0, unresolved: [], skippedManual: 0 } };

const createMut = vi.fn();
const updateMut = vi.fn();
const autofillMut = vi.fn((_: undefined, opts?: { onSuccess?: (r: VenueTravelTimeAutofillResult) => void }) => opts?.onSuccess?.(autofillResultState.value));

vi.mock("../queries", () => ({
  useWizardVenues: () => ({ data: venuesState.data }),
  useVenueTravelTimes: () => ({ data: matrixState.data, isError: false, refetch: vi.fn() }),
  useCreateVenueTravelTime: () => ({ mutate: createMut, isPending: false }),
  useUpdateVenueTravelTime: () => ({ mutate: updateMut, isPending: false }),
  useAutofillVenueTravelTimes: () => ({ mutate: autofillMut, isPending: false }),
}));

import { TravelMatrixModal } from "./TravelMatrixModal";

const venue = (id: string, name: string, geo = true): Venue => ({
  id,
  name,
  color: null,
  canSplit: false,
  isActive: true,
  latitude: geo ? "45.7" : null,
  longitude: geo ? "4.8" : null,
  address: geo ? "1 rue X" : null,
});

const row = (over: Partial<VenueTravelTime> & Pick<VenueTravelTime, "id" | "venueAId" | "venueBId">): VenueTravelTime => ({
  drivingMinutes: null,
  walkingMinutes: null,
  drivingSource: null,
  walkingSource: null,
  ...over,
});

beforeEach(() => {
  venuesState.data = [venue("v1", "Alpha"), venue("v2", "Beta"), venue("v3", "Gamma")];
  matrixState.data = [];
  autofillResultState.value = { filled: 0, unresolved: [], skippedManual: 0 };
  createMut.mockClear();
  updateMut.mockClear();
  autofillMut.mockClear();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("TravelMatrixModal — première ouverture (consentement)", () => {
  it("propose l'autofill et NE le lance JAMAIS sans clic", () => {
    matrixState.data = [];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    expect(screen.getByRole("heading", { name: "Calculer les trajets entre vos gymnases ?" })).toBeInTheDocument();
    // Falsification : aucune requête d'autofill sans geste.
    expect(autofillMut).not.toHaveBeenCalled();
  });

  it("le clic « Calculer les trajets » lance l'autofill (une fois)", () => {
    matrixState.data = [];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: "Calculer les trajets" }));
    expect(autofillMut).toHaveBeenCalledTimes(1);
  });
});

describe("TravelMatrixModal — la matrice", () => {
  it("distingue AUTO et MANUEL d'un coup d'œil (icône + texte)", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO", walkingMinutes: 40, walkingSource: "MANUAL" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    // Les badges (texte exact) — distincts de la légende « Auto (calculé) » / « Manuel (saisi) ».
    expect(screen.getAllByText("Auto").length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText("Manuel").length).toBeGreaterThanOrEqual(1);
  });

  it("éditer une valeur d'un couple EXISTANT → PUT avec la valeur (MANUEL posé côté serveur)", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    const input = screen.getByRole("textbox", { name: "En voiture — Alpha → Beta" });
    fireEvent.change(input, { target: { value: "22" } });
    fireEvent.blur(input);

    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "r1", body: { venueAId: "v1", venueBId: "v2", drivingMinutes: 22 } }));
  });

  it("éditer un couple SANS ligne → POST (création) avec la valeur", () => {
    matrixState.data = [];
    // Une matrice non vide (r1) pour éviter le consentement, tout en laissant Alpha→Gamma vierge.
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    const input = screen.getByRole("textbox", { name: "En voiture — Alpha → Gamma" });
    fireEvent.change(input, { target: { value: "18" } });
    fireEvent.blur(input);

    expect(createMut).toHaveBeenCalledWith({ venueAId: "v1", venueBId: "v3", drivingMinutes: 18 });
  });

  it("les couples non résolus s'affichent « À saisir » avec leur raison (verdict servi)", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    autofillResultState.value = { filled: 1, unresolved: [{ venueAId: "v1", venueBId: "v3", reason: "missing_geo" }], skippedManual: 0 };
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    // Avant recalcul : pas encore de raison affichée.
    expect(screen.queryByText(/gymnase sans adresse/)).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Recalculer les trajets" }));
    // Après le verdict : la raison du couple Alpha→Gamma est nommée.
    expect(screen.getAllByText(/gymnase sans adresse/).length).toBeGreaterThanOrEqual(1);
  });

  it("re-lancer l'autofill : les valeurs MANUEL restent affichées inchangées", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "MANUAL" })];
    autofillResultState.value = { filled: 0, unresolved: [], skippedManual: 1 };
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    expect(screen.getAllByText("Manuel").length).toBeGreaterThanOrEqual(1);
    fireEvent.click(screen.getByRole("button", { name: "Recalculer les trajets" }));
    // La ligne MANUEL servie n'a pas bougé : le badge « Manuel » est toujours là.
    expect(autofillMut).toHaveBeenCalledTimes(1);
    expect(screen.getAllByText("Manuel").length).toBeGreaterThanOrEqual(1);
    const input = screen.getByRole("textbox", { name: "En voiture — Alpha → Beta" }) as HTMLInputElement;
    expect(input.value).toBe("15");
  });

  it("une saisie HORS BORNES est rejetée AVEC un signal (toast) — plus de restauration muette (FRT-27)", () => {
    const errorSpy = vi.spyOn(toast, "error").mockImplementation(() => 0);
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    const input = screen.getByRole("textbox", { name: "En voiture — Alpha → Beta" }) as HTMLInputElement;
    fireEvent.change(input, { target: { value: "999" } }); // > MAX_MINUTES (240)
    fireEvent.blur(input);

    // Le SIGNAL part…
    expect(errorSpy).toHaveBeenCalledWith(expect.stringMatching(/\S/));
    // …la valeur servie est restaurée (logique inchangée)…
    expect(input.value).toBe("15");
    // …et rien n'est écrit côté serveur.
    expect(updateMut).not.toHaveBeenCalled();
    expect(createMut).not.toHaveBeenCalled();
  });

  it("nomme les gymnases sans adresse et offre le lien vers leur fiche", () => {
    venuesState.data = [venue("v1", "Alpha"), venue("v2", "Beta"), venue("v3", "Gamma", false)];
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    const onLocateVenue = vi.fn();
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} onLocateVenue={onLocateVenue} />);

    fireEvent.click(screen.getByRole("button", { name: "Gamma" }));
    expect(onLocateVenue).toHaveBeenCalledWith("v3");
  });
});
