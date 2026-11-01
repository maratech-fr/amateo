import { act, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { type ReactNode, useState } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";
import { toast } from "@/shared/stores/toastStore";

import { WizardFooterContext } from "../lib/footerSlot";
import { WIZARD_TEXTS } from "../texts";
import type { Venue } from "../api";

/**
 * PR F (P4-279) — PROMESSE : tant que l'écran « Gymnases » (VenuesStep) est ouvert, il écoute le
 * flux des trajets (`useTravelStream`) et, à la TRANSITION vers un événement TERMINAL de scope
 * `VENUE_MATRIX` (le worker a fini de recalculer la matrice), pose un toast « Trajets recalculés. ».
 *   - PAS de toast au MONTAGE sur un terminal déjà passé (sinon rouvrir l'écran rejouerait un vieux
 *     recalcul).
 *   - PAS de toast si `TravelMatrixModal` est ouverte (elle montre déjà sa propre jauge/verdict).
 *   - RIEN d'app-wide : la seule écoute vit DANS VenuesStep, pas dans `AppLayout`.
 *
 * Le flux est mocké par un petit store contrôlable : `pushStream` fixe le dernier événement et
 * notifie les abonnés — le composant se re-rend et son effet voit la transition. On mute ainsi la
 * PROD (le vrai VenuesStep), pas un double. `../queries` et les enfants lourds sont réduits à des
 * stubs : seule la logique d'écoute/suppression de VenuesStep est éprouvée ici.
 */

type StreamState = { connected: boolean; latest: { scope: string | null; done: number; total: number; terminal: boolean; verdict: unknown } | null };

const h = vi.hoisted(() => ({
  listeners: new Set<() => void>(),
  stream: { value: { connected: false, latest: null } as { connected: boolean; latest: unknown } },
  venues: { data: [] as unknown[] },
  matchWindows: { data: [] as unknown[] },
  gridMatchWindows: { value: undefined as unknown },
}));

vi.mock("@/shared/lib/travelStream", async () => {
  const { useReducer, useEffect } = await import("react");
  return {
    useTravelStream: () => {
      const [, force] = useReducer((x: number) => x + 1, 0);
      useEffect(() => {
        h.listeners.add(force);
        return () => void h.listeners.delete(force);
      }, []);
      return h.stream.value;
    },
  };
});

vi.mock("../queries", () => ({
  useWizardVenues: () => ({ data: h.venues.data, isSuccess: true }),
  // NEO a câblé l'import du parc par IA dans VenuesStep (prompt pré-rempli du périmètre) : ces deux
  // lectures voisines doivent exister dans le mock, sinon VenuesStep plante au rendu (hooks undefined).
  useWizardTeams: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [] }),
  useVenueSlots: () => ({ data: [] }),
  useReservations: () => ({ data: [] }),
  useVenueGeoCheck: () => ({ data: [] }),
  useCreateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  // P4-279 — le hook dédié du PUT géo (toast au niveau hook) ; stubé ici, prouvé par
  // `queries.venueGeoToast.test.tsx`. Présent pour que VenuesStep reste montable après NEO.
  useLocateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteVenue: () => ({ mutate: vi.fn() }),
  useCreateSlot: () => ({ mutate: vi.fn() }),
  useUpdateSlot: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteSlot: () => ({ mutate: vi.fn() }),
  useDeletionImpact: () => ({ data: undefined, isPending: false, isError: false }),
  useFfbbSalles: () => ({ data: { salles: [] } }),
  useFfbbSallesProches: () => ({ data: { salles: [] } }),
  useFfbbSallesByName: () => ({ data: { postalCode: null, salles: [] }, isPending: false }),
}));

vi.mock("@/features/matches/queries", () => ({ useVenueMatchWindows: () => ({ data: h.matchWindows.data }) }));
vi.mock("@/features/matches/MatchWindowsEditor", () => ({ MatchWindowsEditor: () => null }));
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: { id: "club-1", postalCode: "69100" } } }), useWorkingSeason: () => undefined }));
// La modale d'import du parc n'est pas rendue ici (parcOpen reste faux) ; on la neutralise pour
// isoler VenuesStep de tout le graphe de modules de parcImport.
vi.mock("../parcImport/ParcImportModal", () => ({ ParcImportModal: () => null }));
vi.mock("@/shared/lib/socle", () => ({ useSocleValidated: () => false }));
vi.mock("@/shared/components/ui/venue-select", () => ({ VenueSelect: () => null }));
vi.mock("./slotFields", () => ({ CapacitySelect: () => null, DurationSelect: () => null, GroupLabelField: () => null, SharedSlotHint: () => null }));
vi.mock("./VenueGeocodeField", () => ({ VenueGeocodeField: () => null }));
vi.mock("./VenueAvailabilityGrid", () => ({
  VenueAvailabilityGrid: ({ matchWindows }: { matchWindows?: unknown }) => {
    h.gridMatchWindows.value = matchWindows;
    return null;
  },
}));
// La modale n'a pas besoin de rendre son contenu : seule son OUVERTURE (matrixOpen) compte pour
// la suppression du toast terminal.
vi.mock("./TravelMatrixModal", () => ({ TravelMatrixModal: () => null }));
vi.mock("../store", () => ({
  useWizardStore: (selector?: (s: { mode: string; calendarEntryId: string | null; clearDeepLinkOrigin: () => void }) => unknown) => {
    const state = { mode: "season", calendarEntryId: null, clearDeepLinkOrigin: () => {} };
    return selector ? selector(state) : state;
  },
}));

import { VenuesStep } from "./VenuesStep";

const venue = (id: string, name: string): Venue => ({ id, name, color: "#3498DB", canSplit: false, isActive: true, externalRef: null, address: null, latitude: null, longitude: null });

const TERMINAL_VM: StreamState = { connected: true, latest: { scope: "VENUE_MATRIX", done: 10, total: 10, terminal: true, verdict: { filled: 10, unresolved: [] } } };
const PROGRESS_VM: StreamState = { connected: true, latest: { scope: "VENUE_MATRIX", done: 3, total: 10, terminal: false, verdict: null } };

function pushStream(next: StreamState): void {
  h.stream.value = next;
  act(() => {
    h.listeners.forEach((l) => l());
  });
}

// L'écran vit dans le pied de page de l'assistant : un hôte minimal rend ce que VenuesStep y
// injecte (le bouton « Trajets entre gymnases »), ce qui permet d'ouvrir la matrice.
function Harness() {
  const [extra, setExtra] = useState<ReactNode>(null);
  return (
    <WizardFooterContext.Provider value={{ setFooterExtra: setExtra, setSuppressScrollJump: () => {} }}>
      <VenuesStep />
      <div data-testid="footer-slot">{extra}</div>
    </WizardFooterContext.Provider>
  );
}

beforeEach(() => {
  h.listeners.clear();
  h.stream.value = { connected: false, latest: null };
  h.venues.data = [];
  h.matchWindows.data = [];
  h.gridMatchWindows.value = undefined;
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("VenuesStep — toast de recalcul des trajets (P4-279)", () => {
  it("à la transition vers un terminal VENUE_MATRIX, toast « Trajets recalculés. » tant que l'écran est ouvert", async () => {
    const successSpy = vi.spyOn(toast, "success").mockImplementation(() => 0);
    h.stream.value = PROGRESS_VM;
    renderWithProviders(<Harness />);
    await screen.findByText("Aucun gymnase pour le moment.");
    expect(successSpy).not.toHaveBeenCalledWith(WIZARD_TEXTS.travelRecomputed);

    pushStream(TERMINAL_VM);

    await waitFor(() => expect(successSpy).toHaveBeenCalledWith(WIZARD_TEXTS.travelRecomputed));
  });

  it("aucun toast au MONTAGE sur un terminal VENUE_MATRIX déjà passé", async () => {
    const successSpy = vi.spyOn(toast, "success").mockImplementation(() => 0);
    h.stream.value = TERMINAL_VM;
    renderWithProviders(<Harness />);
    await screen.findByText("Aucun gymnase pour le moment.");

    expect(successSpy).not.toHaveBeenCalledWith(WIZARD_TEXTS.travelRecomputed);
  });

  it("aucun toast terminal quand la matrice de trajet (TravelMatrixModal) est ouverte", async () => {
    const user = userEvent.setup();
    const successSpy = vi.spyOn(toast, "success").mockImplementation(() => 0);
    h.venues.data = [venue("v1", "Alpha"), venue("v2", "Beta")];
    renderWithProviders(<Harness />);

    await user.click(await screen.findByRole("button", { name: /Trajets entre gymnases/i }));
    pushStream(TERMINAL_VM);

    // Laisser les effets se poser puis vérifier qu'aucun toast n'est parti (la modale montre sa jauge).
    await Promise.resolve();
    expect(successSpy).not.toHaveBeenCalledWith(WIZARD_TEXTS.travelRecomputed);
  });
});

/**
 * Promesse (D1bis, D21) : une ouverture datée vaut un jour, pas une convention. Dans l'étape Gymnases,
 * un gymnase qui n'a qu'une ouverture reste « sans créneau » et la grille hebdo ne la reçoit pas.
 * Jumeau de la porte : `useStepValidation.test.ts` ; jumeau de l'atterrissage : `WizardLayout.openingDate.test.tsx`.
 */
describe("VenuesStep — une ouverture datée ne fait pas un gymnase de match", () => {
  const dated = { id: "o1", venueId: "v1", dayOfWeek: null, appliesOnPublicHolidays: false, openingDate: "2026-11-11", label: null, startTime: "14:00", endTime: "20:00" };
  const weekly = { id: "w1", venueId: "v1", dayOfWeek: 6, appliesOnPublicHolidays: false, openingDate: null, label: null, startTime: "14:00", endTime: "18:00" };

  it("une ouverture seule : le bandeau « sans créneau » nomme le gymnase", async () => {
    h.venues.data = [venue("v1", "Gymnase Alpha")];
    h.matchWindows.data = [dated];
    renderWithProviders(<Harness />);
    expect(await screen.findByText(WIZARD_TEXTS.emptyVenuesSingular, { exact: false })).toHaveTextContent("Gymnase Alpha");
  });

  it("témoin : une ligne hebdo l'exempte", async () => {
    h.venues.data = [venue("v1", "Gymnase Alpha")];
    h.matchWindows.data = [weekly];
    renderWithProviders(<Harness />);
    await waitFor(() => expect(h.gridMatchWindows.value).toEqual([weekly]));
    expect(screen.queryByText(WIZARD_TEXTS.emptyVenuesSingular, { exact: false })).toBeNull();
  });

  it("la grille hebdo ne reçoit jamais la ligne datée", async () => {
    h.venues.data = [venue("v1", "Gymnase Alpha")];
    h.matchWindows.data = [weekly, dated];
    renderWithProviders(<Harness />);
    await waitFor(() => expect(h.gridMatchWindows.value).toEqual([weekly]));
  });
});
