import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen, waitFor } from "@testing-library/react";
import { type ReactNode, useState } from "react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { WizardFooterContext } from "../lib/footerSlot";
import { WIZARD_TEXTS } from "../texts";
import type { Venue } from "../api";

/**
 * PROMESSE (chantier Membre, PR 2, décision fondateur 4) : aucun appel sortant n'est déclenché par
 * un Membre. L'étape Gymnases interroge au MONTAGE la FFBB (salles de la commune, salles proches) et
 * le contrôle de position (`venues/geo-check`, qui appelle la FFBB/BAN côté serveur) — chez un
 * Membre, aucune de ces lectures ne part (`VenuesStep.tsx` : `enabled: canManage` ; `useVenueGeoCheck`
 * désarmé), et l'étape n'offre aucun ajout. Le serveur les refuse déjà en 403
 * (`MemberReadsAreSideEffectFreeTest`) ; ce test prouve que le front ne les ENVOIE pas.
 *
 * Les VRAIS hooks (`useFfbbSalles`, `useFfbbSallesProches`, `useVenueGeoCheck`) et un vrai
 * `QueryClient` sont exercés : le mock s'arrête à l'API. Témoin gestionnaire : mêmes appels partis.
 */

const h = vi.hoisted(() => ({
  canManage: true,
  listVenues: vi.fn(),
  geoCheckVenues: vi.fn(),
  listFfbbSalles: vi.fn(),
  listFfbbSallesProches: vi.fn(),
  listFfbbSallesByName: vi.fn(),
}));

vi.mock("../api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../api")>()),
  listVenues: h.listVenues,
  geoCheckVenues: h.geoCheckVenues,
  listFfbbSalles: h.listFfbbSalles,
  listFfbbSallesProches: h.listFfbbSallesProches,
  listFfbbSallesByName: h.listFfbbSallesByName,
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
  useCreateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateSlot: () => ({ mutate: vi.fn() }),
  useUpdateSlot: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteSlot: () => ({ mutate: vi.fn() }),
  useDeletionImpact: () => ({ data: undefined, isPending: false, isError: false }),
}));

vi.mock("@/shared/lib/travelStream", () => ({ useTravelStream: () => ({ connected: false, latest: null }) }));
vi.mock("@/features/matches/queries", () => ({ useVenueMatchWindows: () => ({ data: [] }) }));
vi.mock("@/features/matches/MatchWindowsEditor", () => ({ MatchWindowsEditor: () => null }));
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { role: h.canManage ? "admin" : "member", membershipStatus: "active", club: { id: "club-1", postalCode: "69100" } } }),
  useWorkingSeason: () => undefined,
  useCanManage: () => h.canManage,
}));
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

const GYM: Venue = { id: "v1", name: "Gymnase Un", color: "#E6194B", canSplit: false, isActive: true, externalRef: "S123", address: null, latitude: "45.7", longitude: "4.8" };

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

/** Laisse partir les requêtes que react-query planifie au montage ; aucune attente d'horloge. */
async function flushScheduledCallbacks(): Promise<void> {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

beforeEach(() => {
  h.canManage = true;
  h.listVenues.mockReset().mockResolvedValue([GYM]);
  h.geoCheckVenues.mockReset().mockResolvedValue([]);
  h.listFfbbSalles.mockReset().mockResolvedValue({ postalCode: "69100", salles: [] });
  h.listFfbbSallesProches.mockReset().mockResolvedValue({ postalCode: "69100", salles: [] });
  h.listFfbbSallesByName.mockReset().mockResolvedValue({ postalCode: null, salles: [] });
});

describe("VenuesStep — un Membre ne déclenche aucun appel sortant", () => {
  it("témoin gestionnaire : le montage interroge le contrôle de position et la FFBB", async () => {
    renderStep();
    expect(await screen.findByText(/Accès match de « Gymnase Un »/)).toBeInTheDocument();
    await waitFor(() => expect(h.geoCheckVenues).toHaveBeenCalled());
    expect(h.listFfbbSallesProches.mock.calls.length + h.listFfbbSalles.mock.calls.length).toBeGreaterThan(0);
  });

  it("Membre : la liste des gymnases se lit, aucun appel FFBB ni geo-check, aucun ajout", async () => {
    h.canManage = false;
    renderStep();
    expect(await screen.findByText(/Accès match de « Gymnase Un »/)).toBeInTheDocument();
    await flushScheduledCallbacks();
    expect(h.geoCheckVenues).not.toHaveBeenCalled();
    expect(h.listFfbbSalles).not.toHaveBeenCalled();
    expect(h.listFfbbSallesProches).not.toHaveBeenCalled();
    expect(h.listFfbbSallesByName).not.toHaveBeenCalled();
    expect(screen.queryByRole("button", { name: WIZARD_TEXTS.addVenueButton })).not.toBeInTheDocument();
  });
});
