import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { type ReactNode, useState } from "react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { toast } from "@/shared/stores/toastStore";

import { WizardFooterContext } from "../lib/footerSlot";
import { WIZARD_TEXTS } from "../texts";
import type { Venue, VenuePayload } from "../api";

/**
 * PROMESSE : ce que le gestionnaire tape pour son gymnase SUIVANT n'est jamais effacé par la fin de
 * l'ajout du PRÉCÉDENT. L'ajout est acquis au POST ; le refetch de la liste qui suit n'a plus le droit
 * de toucher au champ ni de refermer l'accordéon.
 *
 * Cause : `useCreateVenue` renvoie la promesse d'invalidation (`wizard/queries.ts:226`), donc le rappel
 * de `VenuesStep` (`onVenueCreatedFromField`, vide le champ et referme) n'arrive qu'après le refetch.
 * Trace visible en CI : capture de l'essai 1 de `listbox.spec.ts`, champ vide et « Donnez un nom au
 * gymnase avant de l'ajouter. » alors que le test venait d'y écrire « Gymnase Deux ».
 *
 * Le vrai `useCreateVenue`, le vrai `useWizardVenues` et un vrai `QueryClient` sont exercés : le mock
 * s'arrête à l'API, et seul le second GET des gymnases est différé, pour figer la fenêtre sans minuteur.
 */

const h = vi.hoisted(() => ({
  createVenue: vi.fn(),
  listVenues: vi.fn(),
  geoCheckVenues: vi.fn(),
}));

vi.mock("../api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../api")>()),
  createVenue: h.createVenue,
  listVenues: h.listVenues,
  geoCheckVenues: h.geoCheckVenues,
}));

vi.mock("../queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../queries")>()),
  useWizardTeams: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [] }),
  useVenueSlots: () => ({ data: [] }),
  useReservations: () => ({ data: [] }),
  useUpdateVenue: () => ({ mutate: vi.fn(), isPending: false }),
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

vi.mock("@/shared/lib/travelStream", () => ({ useTravelStream: () => ({ connected: false, latest: null }) }));
vi.mock("@/features/matches/queries", () => ({ useVenueMatchWindows: () => ({ data: [] }) }));
vi.mock("@/features/matches/MatchWindowsEditor", () => ({ MatchWindowsEditor: () => null }));
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: { id: "club-1", postalCode: "69100" } } }), useWorkingSeason: () => undefined }));
vi.mock("../parcImport/ParcImportModal", () => ({ ParcImportModal: () => null }));
vi.mock("@/shared/lib/socle", () => ({ useSocleValidated: () => false }));
vi.mock("@/shared/components/ui/venue-select", () => ({ VenueSelect: () => null }));
vi.mock("./slotFields", () => ({ CapacitySelect: () => null, DurationSelect: () => null, GroupLabelField: () => null, SharedSlotHint: () => null }));
vi.mock("./VenueGeocodeField", () => ({ VenueGeocodeField: () => null }));
vi.mock("./VenueAvailabilityGrid", () => ({ VenueAvailabilityGrid: () => null }));
vi.mock("./TravelMatrixModal", () => ({ TravelMatrixModal: () => null }));
vi.mock("../store", () => ({
  useWizardStore: (selector?: (s: { mode: string; calendarEntryId: string | null; clearDeepLinkOrigin: () => void }) => unknown) => {
    const state = { mode: "season", calendarEntryId: null, clearDeepLinkOrigin: () => {} };
    return selector ? selector(state) : state;
  },
}));

import { VenuesStep } from "./VenuesStep";

const GYM_ONE: Venue = { id: "v1", name: "Gymnase Un", color: "#E6194B", canSplit: false, isActive: true, externalRef: null, address: null, latitude: null, longitude: null };

function Harness() {
  const [extra, setExtra] = useState<ReactNode>(null);
  return (
    <WizardFooterContext.Provider value={{ setFooterExtra: setExtra, setSuppressScrollJump: () => {} }}>
      <VenuesStep />
      <div>{extra}</div>
    </WizardFooterContext.Provider>
  );
}

function renderStep() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter([{ path: "*", element: <Harness /> }], { initialEntries: ["/"] });
  render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

/** Laisse passer les rappels que react-query planifie en macrotâche ; aucune attente d'horloge. */
async function flushScheduledCallbacks(): Promise<void> {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

const nameField = () => screen.queryByRole<HTMLInputElement>("textbox", { name: WIZARD_TEXTS.venueNameLabel });

beforeEach(() => {
  h.createVenue.mockReset();
  h.listVenues.mockReset();
  h.geoCheckVenues.mockReset();
  h.geoCheckVenues.mockResolvedValue([]);
});

describe("VenuesStep — la fin d'un ajout n'efface pas la saisie suivante", () => {
  it("le nom tapé pendant le refetch survit à son retour, accordéon ouvert", async () => {
    const successToast = vi.spyOn(toast, "success");
    h.createVenue.mockImplementation((body: VenuePayload) => Promise.resolve({ ...GYM_ONE, name: body.name }));
    let releaseRefetch: (venues: Venue[]) => void = () => {};
    h.listVenues.mockResolvedValueOnce([]);
    h.listVenues.mockImplementationOnce(() => new Promise<Venue[]>((resolve) => { releaseRefetch = resolve; }));

    const user = userEvent.setup();
    renderStep();

    await user.type(await screen.findByRole("textbox", { name: WIZARD_TEXTS.venueNameLabel }), "Gymnase Un");
    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.addVenueButton }));

    // Le POST est revenu, le GET qui suit reste en vol : c'est toute la fenêtre.
    await waitFor(() => expect(h.listVenues).toHaveBeenCalledTimes(2));
    await flushScheduledCallbacks();

    // Le geste de l'e2e : rouvrir l'accordéon s'il s'est refermé, écrire le nom suivant.
    if (null === nameField()) {
      await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.addVenuesSectionTitle }));
    }
    const field = nameField();
    expect(field, "le champ de nom doit être atteignable pendant le refetch").not.toBeNull();
    await user.clear(field as HTMLInputElement);
    await user.type(field as HTMLInputElement, "Gymnase Deux");

    await act(async () => releaseRefetch([GYM_ONE]));
    // Témoin : le rappel de succès a bien tourné, sinon l'assertion suivante ne prouverait rien.
    await waitFor(() => expect(successToast).toHaveBeenCalledWith(WIZARD_TEXTS.venueAdded("Gymnase Un")));
    await flushScheduledCallbacks();

    expect(nameField(), "l'accordéon d'ajout s'est refermé sous les doigts du gestionnaire").not.toBeNull();
    expect(nameField()).toHaveValue("Gymnase Deux");
  });
});
