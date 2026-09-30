import { fireEvent, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";
import { toast } from "@/shared/stores/toastStore";

import type { Venue, VenueTravelTime, VenueTravelTimeAutofillResult } from "../api";

const venuesState: { data: Venue[] } = { data: [] };
const matrixState: { data: VenueTravelTime[] } = { data: [] };
// Le VERDICT arrive par le flux Mercure (C6), plus par la réponse HTTP.
const autofillResultState: { value: VenueTravelTimeAutofillResult } = { value: { filled: 0, unresolved: [] } };

const createMut = vi.fn();
const updateMut = vi.fn();
// L'autofill DISPATCHE : onSuccess reçoit `{queued, alreadyRunning}` (sécurité H), pas un verdict.
const autofillResponseState = { alreadyRunning: false };
const autofillMut = vi.fn((_: undefined, opts?: { onSuccess?: (r: { queued: boolean; alreadyRunning: boolean }) => void }) =>
  opts?.onSuccess?.({ queued: !autofillResponseState.alreadyRunning, alreadyRunning: autofillResponseState.alreadyRunning }),
);

vi.mock("../queries", () => ({
  useWizardVenues: () => ({ data: venuesState.data }),
  useVenueTravelTimes: () => ({ data: matrixState.data, isError: false, refetch: vi.fn() }),
  useCreateVenueTravelTime: () => ({ mutate: createMut, isPending: false }),
  useUpdateVenueTravelTime: () => ({ mutate: updateMut, isPending: false }),
  useAutofillVenueTravelTimes: () => ({ mutate: autofillMut, isPending: false }),
}));

// Le flux des trajets : au terminal (VENUE_MATRIX), le verdict courant est servi — c'est ce
// qui, après le clic « Calculer » (computing=true), fige `{filled, unresolved}` dans la modale.
vi.mock("@/shared/lib/travelStream", () => ({
  useTravelStream: () => ({ connected: true, latest: { scope: "VENUE_MATRIX" as const, done: 1, total: 1, terminal: true, verdict: autofillResultState.value } }),
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
  autofillResultState.value = { filled: 0, unresolved: [] };
  autofillResponseState.alreadyRunning = false;
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

  it("un calcul DÉJÀ en cours (sécurité H) ne bascule PAS en « Calcul des trajets en cours… »", () => {
    autofillResponseState.alreadyRunning = true;
    matrixState.data = [];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: "Calculer les trajets" }));
    expect(autofillMut).toHaveBeenCalledTimes(1);
    // Rien n'a été dispatché : l'écran reste au consentement, pas de fausse progression.
    expect(screen.queryByText(/Calcul des trajets en cours/)).not.toBeInTheDocument();
  });
});

describe("TravelMatrixModal — matrice N×N", () => {
  it("rend une VRAIE matrice : chaque gymnase en en-tête de colonne ET de ligne", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    for (const name of ["Alpha", "Beta", "Gamma"]) {
      expect(screen.getByRole("columnheader", { name })).toBeInTheDocument();
      expect(screen.getByRole("rowheader", { name })).toBeInTheDocument();
    }
    // La diagonale : un « — » par gymnase (Alpha↔Alpha…).
    expect(screen.getAllByText("—").length).toBeGreaterThanOrEqual(3);
  });

  it("est SYMÉTRIQUE : A↔B et B↔A montrent la même valeur (même pairKey)", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    const has = (a: string, b: string) => (n: string) => n.includes(`${a} ↔ ${b}`) && n.includes("en voiture 15 min");
    expect(screen.getByRole("button", { name: has("Alpha", "Beta") })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: has("Beta", "Alpha") })).toBeInTheDocument();
  });

  it("case en LECTURE SEULE : ni champ ni badge AUTO/MANUEL dans la case (seul le filtre est un textbox)", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO", walkingMinutes: 40, walkingSource: "MANUAL" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    // Un seul textbox dans toute la vue : le filtre. Aucune case n'expose de champ.
    expect(screen.getAllByRole("textbox")).toHaveLength(1);
    // Plus aucun badge « Auto »/« Manuel » dans les cases.
    expect(screen.queryByText("Auto")).toBeNull();
    expect(screen.queryByText("Manuel")).toBeNull();
    // Les temps s'affichent en lecture seule.
    expect(screen.getAllByText("15′").length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText("40′").length).toBeGreaterThanOrEqual(1);
  });

  it("code couleur/gras : calculé = neutre, saisi à la main = accent + GRAS (indice non chromatique)", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO", walkingMinutes: 40, walkingSource: "MANUAL" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    // Le temps SAISI (40′) porte le gras + l'accent ; le temps CALCULÉ (15′) reste neutre.
    const manual = screen.getAllByText("40′")[0].parentElement as HTMLElement;
    expect(manual.className).toContain("font-semibold");
    expect(manual.className).toContain("text-accent");
    const auto = screen.getAllByText("15′")[0].parentElement as HTMLElement;
    expect(auto.className).toContain("text-muted-foreground");
    expect(auto.className).not.toContain("font-semibold");
    // Légende : même code (« Saisi à la main » en gras accent).
    expect(screen.getByText("Calculé automatiquement")).toBeInTheDocument();
    expect(screen.getByText("Saisi à la main").className).toContain("font-semibold");
  });

  it("clic sur une case → modale d'édition ; éditer un couple EXISTANT → PUT (MANUEL posé côté serveur)", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: (n) => n.includes("Alpha ↔ Beta") }));
    expect(screen.getByRole("heading", { name: "Alpha ↔ Beta" })).toBeInTheDocument();
    fireEvent.change(screen.getByRole("textbox", { name: "En voiture — Alpha ↔ Beta (minutes)" }), { target: { value: "22" } });
    fireEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    // Seule la valeur MODIFIÉE (voiture) est écrite ; la marche, inchangée, ne l'est pas.
    expect(updateMut).toHaveBeenCalledTimes(1);
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "r1", body: { venueAId: "v1", venueBId: "v2", drivingMinutes: 22 } }));
  });

  it("éditer un couple SANS ligne via la modale → POST (création) avec la valeur", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: (n) => n.includes("Alpha ↔ Gamma") }));
    fireEvent.change(screen.getByRole("textbox", { name: "En voiture — Alpha ↔ Gamma (minutes)" }), { target: { value: "18" } });
    fireEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(createMut).toHaveBeenCalledWith({ venueAId: "v1", venueBId: "v3", drivingMinutes: 18 });
  });

  it("les couples non résolus portent leur raison dans le nom accessible de la case (verdict servi)", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    autofillResultState.value = { filled: 1, unresolved: [{ venueAId: "v1", venueBId: "v3", reason: "missing_geo" }] };
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    // Avant recalcul : la case Alpha↔Gamma est « à saisir », pas encore de raison.
    expect(screen.getByRole("button", { name: (n) => n.includes("Alpha ↔ Gamma") && n.includes("à saisir") })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Recalculer les trajets" }));
    // Après le verdict : la raison est dans le nom accessible + visible dans la modale.
    const cell = screen.getByRole("button", { name: (n) => n.includes("Alpha ↔ Gamma") && n.includes("gymnase sans adresse") });
    fireEvent.click(cell);
    expect(screen.getAllByText(/gymnase sans adresse/).length).toBeGreaterThanOrEqual(1);
  });

  it("un couple interrompu par le budget se lit « relancez », pas « calcul impossible »", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    autofillResultState.value = { filled: 1, unresolved: [{ venueAId: "v1", venueBId: "v3", reason: "budget_exceeded" }] };
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: "Recalculer les trajets" }));
    // BCK-22 — le lot s'est arrêté sur son budget : le couple est à relancer, pas perdu.
    expect(screen.getByRole("button", { name: (n) => n.includes("Alpha ↔ Gamma") && n.includes("calcul interrompu, relancez") })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: (n) => n.includes("calcul impossible") })).toBeNull();
  });

  it("re-lancer l'autofill : une valeur MANUEL reste affichée, inchangée et en gras", () => {
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "MANUAL" })];
    autofillResultState.value = { filled: 0, unresolved: [] };
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: "Recalculer les trajets" }));
    expect(autofillMut).toHaveBeenCalledTimes(1);
    // La valeur MANUEL servie (15′) est toujours là, en gras accent (le nom accessible dit « saisi à la main »).
    expect(screen.getAllByText("15′")[0].parentElement?.className).toContain("font-semibold");
    expect(screen.getByRole("button", { name: (n) => n.includes("Alpha ↔ Beta") && n.includes("en voiture 15 min (saisi à la main)") })).toBeInTheDocument();
  });

  it("une saisie HORS BORNES dans la modale est rejetée AVEC un signal (toast), rien d'écrit, modale ouverte (FRT-27)", () => {
    const errorSpy = vi.spyOn(toast, "error").mockImplementation(() => 0);
    matrixState.data = [row({ id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, drivingSource: "AUTO" })];
    renderWithProviders(<TravelMatrixModal onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: (n) => n.includes("Alpha ↔ Beta") }));
    fireEvent.change(screen.getByRole("textbox", { name: "En voiture — Alpha ↔ Beta (minutes)" }), { target: { value: "999" } }); // > MAX_MINUTES (240)
    fireEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(errorSpy).toHaveBeenCalledWith(expect.stringMatching(/\S/));
    expect(updateMut).not.toHaveBeenCalled();
    expect(createMut).not.toHaveBeenCalled();
    // La modale reste OUVERTE (la saisie n'est pas perdue).
    expect(screen.getByRole("heading", { name: "Alpha ↔ Beta" })).toBeInTheDocument();
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
